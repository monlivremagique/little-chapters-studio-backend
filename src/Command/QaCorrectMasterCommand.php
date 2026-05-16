<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintValidator;
use App\BookBrief\BookBriefQaPromptBuilder;
use App\Integration\Replicate\ReplicateTextGenerationClientInterface;
use App\Support\JsonExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:book:qa-correct-master',
    description: 'GPT-only QA corrective pass: scores + correctedMaster → master.json updated in place.',
)]
final class QaCorrectMasterCommand extends Command
{
    private const MAX_QA_ITERATIONS = 3;
    private const POLL_TIMEOUT_SECONDS = 600;
    private const POLL_SLEEP_MICROSECONDS = 1500000;
    private const MAX_TEXT_GENERATION_ATTEMPTS = 3;
    private const RETRY_SLEEP_MICROSECONDS = 1000000;

    private readonly string $qaModel;

    public function __construct(
        private readonly BookBriefQaPromptBuilder $bookBriefQaPromptBuilder,
        private readonly BlueprintValidator $blueprintValidator,
        private readonly ReplicateTextGenerationClientInterface $replicateTextGenerationClient,
    ) {
        parent::__construct();
        $bookModel = trim((string) getenv('BOOK_MODEL')) ?: 'anthropic/claude-4-sonnet';
        $this->qaModel = trim((string) getenv('QA_MODEL')) ?: $bookModel;
    }

    protected function configure(): void
    {
        $this
            ->addOption('brief', null, InputOption::VALUE_REQUIRED, 'Path to the source brief YAML file.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to the master.json to correct.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write GPT prompt without calling Replicate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $briefPath = trim((string) $input->getOption('brief'));
        $sourcePath = trim((string) $input->getOption('source'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ('' === $briefPath || !is_file($briefPath) || !is_readable($briefPath)) {
            $io->error('The --brief option is required and must point to a readable brief YAML file.');
            return Command::FAILURE;
        }

        if ('' === $sourcePath || !is_file($sourcePath) || !is_readable($sourcePath)) {
            $io->error('The --source option is required and must point to a readable master.json file.');
            return Command::FAILURE;
        }

        try {
            $brief = Yaml::parseFile($briefPath);
        } catch (\Throwable $e) {
            $io->error(sprintf('Invalid YAML: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        try {
            $master = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Invalid JSON in --source: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $outputDir = dirname($sourcePath);

        $io->title('QA Corrective Pass — GPT');
        $io->writeln(sprintf('Brief: %s', $briefPath));
        $io->writeln(sprintf('Source: %s', $sourcePath));
        $io->writeln(sprintf('Model: %s', $this->qaModel));
        $io->writeln(sprintf('Dry run: %s', $dryRun ? 'yes' : 'no'));

        $currentMaster = $master;
        $correctedMasterWasApplied = false;
        $qaHardFailure = false;
        $qaIteration = 0;
        $previousScores = null;

        while ($qaIteration < self::MAX_QA_ITERATIONS && !$qaHardFailure) {
            ++$qaIteration;

            if ($qaIteration > 1) {
                $io->writeln(sprintf('<info>Pass %d/%d — re-submitting corrected master...</info>', $qaIteration, self::MAX_QA_ITERATIONS));
            }

            $qaPrompt = $this->bookBriefQaPromptBuilder->build($brief, $currentMaster);

            if ($dryRun) {
                $this->writeQaArtifacts($outputDir, $qaPrompt['prompt'], $qaPrompt['input'], true, $currentMaster, null, null, $qaIteration);
                $io->success(sprintf('[Dry-run] QA pass %d prompt written.', $qaIteration));
                return Command::SUCCESS;
            }

            $this->writeQaArtifacts($outputDir, $qaPrompt['prompt'], $qaPrompt['input'], false, $currentMaster, ['status' => 'prepared'], null, $qaIteration);

            try {
                $this->replicateTextGenerationClient->assertConfigured();
                ['prediction' => $finalPrediction, 'outputText' => $outputText] = $this->generateModelOutputText($qaPrompt['input'], $this->qaModel);

                if ('' === $outputText) {
                    throw new \RuntimeException('GPT QA completed without returning any review JSON.');
                }

                $qaResponse = $this->validateQaResponse($this->decodeModelJson($outputText, 'qa'));
            } catch (\RuntimeException $e) {
                $io->warning(sprintf('QA call failed: %s — continuing with current master.', $e->getMessage()));
                $qaHardFailure = true;
                break;
            }

            $correctedMaster = $this->extractCorrectedMasterFromQaResponse($qaResponse);
            $useCorrectedMaster = [] !== $correctedMaster;

            if ('NO_GO' === $qaResponse['verdict'] && !$useCorrectedMaster) {
                $io->warning('GPT returned NO_GO without correctedMaster. Continuing with current master.');
                $qaHardFailure = true;
                break;
            }

            if ($useCorrectedMaster) {
                // Validate corrected master slug matches the original brief
                $correctedSlug = trim((string) (($correctedMaster['metadata']['slug'] ?? '')));
                $expectedSlug = trim((string) ($brief['slug'] ?? ''));
                if ('' !== $expectedSlug && $correctedSlug !== $expectedSlug && '' !== $correctedSlug) {
                    $io->warning(sprintf(
                        'Corrected master slug "%s" does not match brief slug "%s" — keeping current master.',
                        $correctedSlug,
                        $expectedSlug,
                    ));
                    $useCorrectedMaster = false;
                }
            }

            if ($useCorrectedMaster) {
                // Validate corrected master structure before writing (QW2)
                $validation = $this->blueprintValidator->validateMasterBlueprint($correctedMaster);
                if (!$validation->isValid()) {
                    $io->warning(sprintf('Corrected master invalid — keeping previous version. Errors: %s', implode('; ', $validation->errors)));
                    $useCorrectedMaster = false;
                }
            }

            if ($useCorrectedMaster) {
                $currentMaster = $correctedMaster;
                $correctedMasterWasApplied = true;

                file_put_contents($sourcePath, $this->encodeJson($currentMaster)."\n");
                $io->writeln(sprintf('<info>  ✓ master.json updated (V%d)</info>', $qaIteration + 1));
            }

            $scores = $qaResponse['scores'] ?? [];

            // Stagnation detection: compare scores with previous iteration
            if (null !== $previousScores && $this->detectStagnation($previousScores, $scores)) {
                $io->warning('QA scores stagnated — accepting current master to break loop.');
                $this->writeQaReport($outputDir, $briefPath, $sourcePath, $qaResponse, $qaIteration, $useCorrectedMaster);
                return Command::SUCCESS;
            }
            $previousScores = $scores;

            $qaReport = [
                'brief' => $briefPath,
                'source' => $sourcePath,
                'model' => $this->qaModel,
                'iteration' => $qaIteration,
                'dryRun' => false,
                'verdict' => $qaResponse['verdict'],
                'scores' => $qaResponse['scores'],
                'blockingIssues' => $qaResponse['blockingIssues'],
                'correctedMasterApplied' => $useCorrectedMaster,
            ];

            $this->writeQaArtifacts($outputDir, $qaPrompt['prompt'], $qaPrompt['input'], false, $currentMaster, $finalPrediction, $qaResponse, $qaIteration);
            file_put_contents($outputDir.'/claude-qa-report.json', $this->encodeJson($qaReport)."\n");

            // Accept if all 6 core dimensions are >= 9.0 (translationNaturalness is informative)
            if ($this->validateCoreScores($scores)) {
                $io->writeln(sprintf('<info>✓ QA pass %d: core dimensions ≥ 9.0 — master accepted</info>', $qaIteration));
                return Command::SUCCESS;
            }

            if ($qaIteration >= self::MAX_QA_ITERATIONS) {
                $io->warning(sprintf('Max iterations (%d) reached. Using last available master.', self::MAX_QA_ITERATIONS));
                return Command::SUCCESS;
            }
        }

        if ($correctedMasterWasApplied || $qaIteration > 0) {
            $io->warning('QA corrective pass completed with warnings. Check claude-qa-report.json for details.');
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $scores */
    private function validateCoreScores(array $scores): bool
    {
        foreach (['editorial', 'imageability', 'heroConsistency', 'localeCompleteness', 'bedtimeSafety', 'premiumBelgium'] as $dim) {
            $score = $scores[$dim] ?? 0;
            if (!is_numeric($score) || (float) $score < 9.0) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $input */
    private function generateModelOutputText(array $input, string $model): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_TEXT_GENERATION_ATTEMPTS; ++$attempt) {
            try {
                $prediction = $this->replicateTextGenerationClient->createPrediction($model, $input);
                $finalPrediction = $this->waitForPrediction((string) ($prediction['id'] ?? ''));
                $outputText = $this->extractOutputText($finalPrediction['output'] ?? null);

                return ['prediction' => $finalPrediction, 'outputText' => $outputText];
            } catch (\RuntimeException $exception) {
                $lastException = $exception;
                if ($attempt >= self::MAX_TEXT_GENERATION_ATTEMPTS || !$this->isTransientFailure($exception)) {
                    throw $exception;
                }
                usleep(self::RETRY_SLEEP_MICROSECONDS);
            }
        }

        throw $lastException ?? new \RuntimeException('Text generation failed.');
    }

    private function waitForPrediction(string $predictionId): array
    {
        if ('' === trim($predictionId)) {
            throw new \RuntimeException('Replicate did not return a prediction id.');
        }

        $startedAt = time();

        do {
            $prediction = $this->replicateTextGenerationClient->getPrediction($predictionId);
            $status = trim((string) ($prediction['status'] ?? 'processing'));

            if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                if ('succeeded' !== $status) {
                    throw new \RuntimeException(sprintf('GPT generation ended with status "%s": %s', $status, trim((string) ($prediction['error'] ?? 'unknown error'))));
                }
                return $prediction;
            }

            usleep(self::POLL_SLEEP_MICROSECONDS);
        } while ((time() - $startedAt) < self::POLL_TIMEOUT_SECONDS);

        throw new \RuntimeException(sprintf('GPT generation timed out after %d seconds.', self::POLL_TIMEOUT_SECONDS));
    }

    private function extractOutputText(mixed $output): string
    {
        if (is_string($output)) {
            return trim($output);
        }
        if (!is_array($output)) {
            return '';
        }
        $parts = [];
        foreach ($output as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }
            if (is_array($item)) {
                $text = trim((string) ($item['text'] ?? $item['content'] ?? ''));
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }
        return trim(implode('', $parts));
    }

    /** @return array<string, mixed> */
    private function decodeModelJson(string $rawText, string $label = 'unknown'): array
    {
        $result = JsonExtractor::extract($rawText);
        if (null !== $result) {
            return $result;
        }
        throw new \RuntimeException(sprintf('GPT did not return valid JSON [%s]. Raw output (first 500 chars): %s', $label, mb_substr($rawText, 0, 500)));
    }

    /** @param array<string, mixed> $qaResponse @return array<string, mixed> */
    private function validateQaResponse(array $qaResponse): array
    {
        $verdict = trim((string) ($qaResponse['verdict'] ?? ''));
        if (!in_array($verdict, ['GO', 'NO_GO'], true)) {
            throw new \RuntimeException('GPT QA must return verdict = GO or NO_GO.');
        }

        $scores = $qaResponse['scores'] ?? null;
        if (!is_array($scores)) {
            throw new \RuntimeException('GPT QA must return a scores object.');
        }

        foreach (['editorial', 'imageability', 'heroConsistency', 'localeCompleteness', 'bedtimeSafety', 'premiumBelgium'] as $scoreKey) {
            $value = $scores[$scoreKey] ?? null;
            if ((!is_int($value) && !is_float($value) && !ctype_digit((string) $value)) || (float) $value < 0 || (float) $value > 10) {
                throw new \RuntimeException(sprintf('GPT QA score "%s" must be a number between 0 and 10.', $scoreKey));
            }
        }

        $blockingIssues = $qaResponse['blockingIssues'] ?? null;
        if (!is_array($blockingIssues)) {
            throw new \RuntimeException('GPT QA must return blockingIssues as an array.');
        }
        foreach ($blockingIssues as $index => $issue) {
            if (!is_string($issue)) {
                throw new \RuntimeException(sprintf('GPT QA blockingIssues[%d] must be a string.', $index));
            }
        }
        if (!array_key_exists('correctedMaster', $qaResponse) || !is_array($qaResponse['correctedMaster'])) {
            throw new \RuntimeException('GPT QA must return correctedMaster as an object.');
        }

        return [
            'verdict' => $verdict,
            'scores' => $scores,
            'blockingIssues' => array_values($blockingIssues),
            'correctedMaster' => $qaResponse['correctedMaster'],
        ];
    }

    /** @param array<string, mixed> $qaResponse @return array<string, mixed> */
    private function extractCorrectedMasterFromQaResponse(array $qaResponse): array
    {
        $candidate = $qaResponse['correctedMaster'] ?? [];
        while (is_array($candidate) && $this->looksLikeQaEnvelope($candidate)) {
            $candidate = $candidate['correctedMaster'] ?? [];
        }
        if (!is_array($candidate) || [] === $candidate) {
            return [];
        }
        return $candidate;
    }

    /** @param array<string, mixed> $payload */
    private function looksLikeQaEnvelope(array $payload): bool
    {
        return array_key_exists('verdict', $payload)
            && array_key_exists('scores', $payload)
            && array_key_exists('blockingIssues', $payload)
            && array_key_exists('correctedMaster', $payload)
            && !array_key_exists('schema', $payload);
    }

    /** @param array<string, mixed>|null $prediction @param array<string, mixed>|null $qaResponse */
    private function writeQaArtifacts(string $outputDir, string $prompt, array $input, bool $dryRun, array $master, ?array $prediction, ?array $qaResponse, int $iteration): void
    {
        $suffix = '' === $iteration ? '' : "-pass{$iteration}";
        file_put_contents($outputDir."/claude-qa-prompt{$suffix}.txt", $prompt."\n");
        file_put_contents($outputDir."/claude-qa-payload{$suffix}.json", $this->encodeJson([
            'model' => $this->qaModel,
            'input' => $input,
        ])."\n");
        file_put_contents($outputDir."/claude-qa-debug{$suffix}.json", $this->encodeJson([
            'model' => $this->qaModel,
            'dryRun' => $dryRun,
            'outputDir' => $outputDir,
            'prompt' => $prompt,
            'input' => $input,
            'master' => $master,
            'prediction' => $prediction,
            'qaResponse' => $qaResponse,
        ])."\n");
    }

    /** @param array<string, mixed> $prev @param array<string, mixed> $curr */
    private function detectStagnation(array $prev, array $curr): bool
    {
        $dims = ['editorial', 'imageability', 'heroConsistency', 'localeCompleteness', 'bedtimeSafety', 'premiumBelgium', 'translationNaturalness'];
        $unchanged = 0;
        foreach ($dims as $dim) {
            $p = (float) ($prev[$dim] ?? 0);
            $c = (float) ($curr[$dim] ?? 0);
            if ($p > 0 && abs($c - $p) < 0.3) {
                ++$unchanged;
            }
        }
        // Stagnation if 5+ out of 7 dimensions changed by less than 0.3
        return $unchanged >= 5;
    }

    /** @param array<string, mixed> $qaResponse */
    private function writeQaReport(string $outputDir, string $briefPath, string $sourcePath, array $qaResponse, int $iteration, bool $applied): void
    {
        file_put_contents($outputDir.'/claude-qa-report.json', $this->encodeJson([
            'brief' => $briefPath,
            'source' => $sourcePath,
            'model' => $this->qaModel,
            'iteration' => $iteration,
            'dryRun' => false,
            'verdict' => $qaResponse['verdict'],
            'scores' => $qaResponse['scores'],
            'blockingIssues' => $qaResponse['blockingIssues'],
            'correctedMasterApplied' => $applied,
        ])."\n");
    }

    private function isTransientFailure(\RuntimeException $exception): bool
    {
        $msg = strtolower($exception->getMessage());
        return str_contains($msg, 'prediction interrupted')
            || str_contains($msg, '(code: pa)')
            || str_contains($msg, 'async prediction failed')
            || str_contains($msg, 'readerror');
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            throw new \RuntimeException('JSON encoding failed.');
        }
        return $encoded;
    }
}
