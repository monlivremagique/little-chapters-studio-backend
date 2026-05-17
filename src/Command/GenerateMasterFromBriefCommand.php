<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintValidator;
use App\BookBrief\BookBriefPromptBuilder;
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
    name: 'app:book:generate-master-from-brief',
    description: 'Generates a Book Blueprint V2 master.json from a YAML brief using Claude on Replicate. No QA — use app:book:qa-correct-master separately.',
)]
final class GenerateMasterFromBriefCommand extends Command
{
    private const POLL_TIMEOUT_SECONDS = 900;
    private const POLL_SLEEP_MICROSECONDS = 1500000;
    private const MAX_TEXT_GENERATION_ATTEMPTS = 3;
    private const RETRY_SLEEP_MICROSECONDS = 1000000;

    private readonly string $bookModel;

    public function __construct(
        private readonly BookBriefPromptBuilder $bookBriefPromptBuilder,
        private readonly BlueprintValidator $blueprintValidator,
        private readonly ReplicateTextGenerationClientInterface $replicateTextGenerationClient,
    ) {
        parent::__construct();
        $this->bookModel = trim((string) getenv('BOOK_MODEL')) ?: 'anthropic/claude-4-sonnet';
    }

    protected function configure(): void
    {
        $this
            ->addOption('brief', null, InputOption::VALUE_REQUIRED, 'Path to the source brief YAML file.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Target blueprint directory where master.json will be written.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write Claude prompt and debug payload without calling Replicate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $briefPath = trim((string) $input->getOption('brief'));
        $outputDir = trim((string) $input->getOption('output-dir'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ('' === $briefPath || !is_file($briefPath) || !is_readable($briefPath)) {
            $io->error('The --brief option is required and must point to a readable YAML brief file.');
            return Command::FAILURE;
        }

        try {
            $brief = Yaml::parseFile($briefPath);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Invalid YAML: %s', $exception->getMessage()));
            return Command::FAILURE;
        }

        if (!is_array($brief)) {
            $io->error('The brief root must be a YAML mapping/object.');
            return Command::FAILURE;
        }

        try {
            $builtPrompt = $this->bookBriefPromptBuilder->build($brief);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        if ('' === $outputDir) {
            $outputDir = sprintf('resources/book-blueprints/%s', $builtPrompt['slug']);
        }

        if ((!file_exists($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) || (file_exists($outputDir) && !is_dir($outputDir))) {
            $io->error(sprintf('The output directory "%s" is not writable.', $outputDir));
            return Command::FAILURE;
        }

        $io->section('Master Blueprint From Brief');
        $io->writeln(sprintf('Brief: %s', $briefPath));
        $io->writeln(sprintf('Output directory: %s', $outputDir));
        $io->writeln(sprintf('Dry run: %s', $dryRun ? 'yes' : 'no'));
        $io->writeln(sprintf('Model: %s', $this->bookModel));

        if ($dryRun) {
            $this->writeMasterArtifacts($briefPath, $outputDir, $builtPrompt['prompt'], $builtPrompt['input'], true, null, null, null);
            $io->success('Claude master blueprint prompt built successfully in dry-run mode.');
            return Command::SUCCESS;
        }

        try {
            $this->replicateTextGenerationClient->assertConfigured();
            $this->writeMasterArtifacts($briefPath, $outputDir, $builtPrompt['prompt'], $builtPrompt['input'], false, ['status' => 'prepared'], null, null);
            ['prediction' => $finalPrediction, 'outputText' => $outputText] = $this->generateModelOutputText($builtPrompt['input']);

            if ('' === $outputText) {
                throw new \RuntimeException('Claude completed without returning any master blueprint text.');
            }

            $rawMasterBlueprint = $this->normalizeGeneratedMaster($this->decodeModelJson($outputText, 'master'));
            $this->writeMasterArtifacts($briefPath, $outputDir, $builtPrompt['prompt'], $builtPrompt['input'], false, $finalPrediction, $rawMasterBlueprint, $outputText);

            $validation = $this->blueprintValidator->validateMasterBlueprint($rawMasterBlueprint);
            if (!$validation->isValid()) {
                throw new \RuntimeException(sprintf("Generated master blueprint is invalid:\n%s", implode("\n", $validation->errors)));
            }

            $masterPath = rtrim($outputDir, '/').'/master.json';
            if (false === file_put_contents($masterPath, $this->encodeJson($rawMasterBlueprint)."\n") || !is_file($masterPath)) {
                throw new \RuntimeException(sprintf('The generated master blueprint could not be written to "%s".', $masterPath));
            }

            $io->writeln(sprintf('  ✓ master.json written (%s)', $masterPath));
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Master blueprint generated and validated successfully.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $input @return array{prediction:array<string,mixed>,outputText:string} */
    private function generateModelOutputText(array $input): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_TEXT_GENERATION_ATTEMPTS; ++$attempt) {
            try {
                $prediction = $this->replicateTextGenerationClient->createPrediction($this->bookModel, $input);
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

        throw $lastException ?? new \RuntimeException('Text generation failed unexpectedly.');
    }

    /** @return array<string, mixed> */
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
                    throw new \RuntimeException(sprintf('Replicate generation ended with status "%s": %s', $status, trim((string) ($prediction['error'] ?? 'unknown error'))));
                }
                return $prediction;
            }

            usleep(self::POLL_SLEEP_MICROSECONDS);
        } while ((time() - $startedAt) < self::POLL_TIMEOUT_SECONDS);

        throw new \RuntimeException(sprintf('Replicate generation timed out after %d seconds.', self::POLL_TIMEOUT_SECONDS));
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
        throw new \RuntimeException(sprintf('Claude did not return valid JSON [%s]. Raw output (first 500 chars): %s', $label, mb_substr($rawText, 0, 500)));
    }

    /** @param array<string, mixed> $replicateInputPayload @param array<string, mixed>|null $prediction @param array<string, mixed>|null $generatedMaster @param string|null $rawModelOutput */
    private function writeMasterArtifacts(string $briefPath, string $outputDir, string $prompt, array $replicateInputPayload, bool $dryRun, ?array $prediction, ?array $generatedMaster, ?string $rawModelOutput = null): void
    {
        file_put_contents($outputDir.'/claude-master-prompt.txt', $prompt."\n");
        file_put_contents($outputDir.'/claude-master-payload.json', $this->encodeJson(['model' => $this->bookModel, 'input' => $replicateInputPayload])."\n");
        file_put_contents($outputDir.'/claude-master-debug.json', $this->encodeJson([
            'brief' => $briefPath, 'model' => $this->bookModel, 'dryRun' => $dryRun, 'outputDir' => $outputDir,
            'prompt' => $prompt, 'replicateInputPayload' => $replicateInputPayload,
            'prediction' => $prediction, 'generatedMaster' => $generatedMaster, 'rawModelOutput' => $rawModelOutput,
        ])."\n");
    }

    /** @param array<string, mixed> $masterBlueprint @return array<string, mixed> */
    private function normalizeGeneratedMaster(array $masterBlueprint): array
    {
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        if (isset($metadata['theme']) && is_string($metadata['theme'])) {
            $metadata['theme'] = array_values(array_filter(array_map('trim', explode(',', $metadata['theme']))));
        }
        if (isset($metadata['supportedLocales']) && is_string($metadata['supportedLocales'])) {
            $metadata['supportedLocales'] = array_values(array_filter(array_map('trim', explode(',', $metadata['supportedLocales']))));
        }
        if (isset($metadata['version']) && !is_int($metadata['version']) && !ctype_digit((string) $metadata['version'])) {
            $metadata['version'] = 2;
        }
        $masterBlueprint['metadata'] = $metadata;

        $locales = is_array($masterBlueprint['locales'] ?? null) ? $masterBlueprint['locales'] : [];
        foreach ($locales as $locale => $localeNode) {
            if (!is_array($localeNode)) continue;
            $pages = is_array($localeNode['pages'] ?? null) ? $localeNode['pages'] : [];
            foreach ($pages as $pageKey => $pageNode) {
                if (!is_array($pageNode)) continue;
                if (isset($pageNode['title']) && !isset($pageNode['title_template'])) {
                    $pageNode['title_template'] = $pageNode['title']; unset($pageNode['title']);
                }
                if (isset($pageNode['text']) && !isset($pageNode['text_template'])) {
                    $pageNode['text_template'] = $pageNode['text']; unset($pageNode['text']);
                }
                $pages[$pageKey] = $pageNode;
            }
            $localeNode['pages'] = $pages;
            $locales[$locale] = $localeNode;
        }
        $masterBlueprint['locales'] = $locales;

        $vb = is_array($masterBlueprint['visualBible'] ?? null) ? $masterBlueprint['visualBible'] : [];
        if (!isset($vb['style_rules']) && is_array($vb['styleRules'] ?? null)) $vb['style_rules'] = $vb['styleRules'];
        if (isset($vb['style_rules']) && is_string($vb['style_rules'])) $vb['style_rules'] = $this->stringToList($vb['style_rules']);
        if (isset($vb['palette']) && is_array($vb['palette'])) $vb['palette'] = $this->flattenToSentence($vb['palette']);
        if (isset($vb['lighting']) && is_array($vb['lighting'])) $vb['lighting'] = $this->flattenToSentence($vb['lighting']);
        if (isset($vb['compositionRules']) && is_string($vb['compositionRules'])) $vb['compositionRules'] = $this->stringToList($vb['compositionRules']);
        $masterBlueprint['visualBible'] = $vb;

        $hb = is_array($masterBlueprint['heroBible'] ?? null) ? $masterBlueprint['heroBible'] : [];
        if (!isset($hb['identityRules']) && is_array($hb['identity_rules'] ?? null)) $hb['identityRules'] = $hb['identity_rules'];
        if (isset($hb['identityRules']) && is_string($hb['identityRules'])) $hb['identityRules'] = $this->stringToList($hb['identityRules']);
        if (isset($hb['forbiddenDrift']) && is_string($hb['forbiddenDrift'])) $hb['forbiddenDrift'] = $this->stringToList($hb['forbiddenDrift']);
        if (isset($hb['characterDesign']) && is_array($hb['characterDesign'])) $hb['characterDesign'] = $this->flattenToSentence($hb['characterDesign']);
        $masterBlueprint['heroBible'] = $hb;

        $ig = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        foreach (['negative_prompt_default', 'defaultNegativePrompt', 'negativePrompt'] as $ck) {
            if (!isset($ig['negativePromptDefault']) && isset($ig[$ck])) $ig['negativePromptDefault'] = $ig[$ck];
        }
        if (isset($ig['model']) && !isset($ig['modelStrategy'])) $ig['modelStrategy'] = ['model' => $ig['model']];
        $masterBlueprint['imageGeneration'] = $ig;

        $sds = is_array($masterBlueprint['sceneDefinitions'] ?? null) ? $masterBlueprint['sceneDefinitions'] : [];
        foreach ($sds as $i => $sd) {
            if (!is_array($sd)) continue;
            // Fix missing/zero pageNumber: assign sequential from position
            $pageNumber = (int) ($sd['pageNumber'] ?? 0);
            if ($pageNumber < 1) {
                $sd['pageNumber'] = $i + 1;
            }
            foreach (['camera','composition','foreground','midground','background','lighting','emotion'] as $f) {
                if (isset($sd[$f]) && is_array($sd[$f])) $sd[$f] = $this->flattenToSentence($sd[$f]);
            }
            foreach (['must_show','must_not_show'] as $lk) {
                if (array_key_exists($lk, $sd) && null === $sd[$lk]) $sd[$lk] = [];
                if (isset($sd[$lk]) && is_string($sd[$lk])) $sd[$lk] = array_values(array_filter(array_map('trim', explode(',', $sd[$lk]))));
            }
            $sds[$i] = $sd;
        }
        $masterBlueprint['sceneDefinitions'] = $sds;

        $qa = is_array($masterBlueprint['qa'] ?? null) ? $masterBlueprint['qa'] : [];
        foreach (['requiredPageTypes','requiredLocales'] as $qlk) {
            if (isset($qa[$qlk]) && is_string($qa[$qlk])) $qa[$qlk] = $this->stringToCommaList($qa[$qlk]);
        }
        if (isset($qa['rules']) && is_string($qa['rules'])) $qa['rules'] = $this->stringToList($qa['rules']);
        if (isset($qa['placeholderPolicy']) && is_string($qa['placeholderPolicy'])) {
            $qa['placeholderPolicy'] = ['allowed' => ['{child_name}','{child_pronoun_subject}','{child_possessive_det}'], 'forbidden' => []];
        }
        $pp = is_array($qa['placeholderPolicy'] ?? null) ? $qa['placeholderPolicy'] : [];
        foreach (['allowedPlaceholders','allowed_placeholders'] as $aa) {
            if (!isset($pp['allowed']) && isset($pp[$aa])) $pp['allowed'] = $pp[$aa];
        }
        if (!isset($pp['allowed'])) $pp['allowed'] = ['{child_name}','{child_pronoun_subject}','{child_possessive_det}'];
        if (!isset($pp['forbidden'])) $pp['forbidden'] = [];
        $qa['placeholderPolicy'] = $pp;

        $sc = is_array($qa['scorecard'] ?? null) ? $qa['scorecard'] : [];
        foreach (['editorialScore'=>['editorial','editorial_score'],'imageabilityScore'=>['imageability','imageability_score'],
                  'heroConsistencyScore'=>['heroConsistency','hero_consistency'],'localeCompletenessScore'=>['localeCompleteness','locale_completeness'],
                  'translationNaturalnessScore'=>['translationNaturalness','translation_naturalness']] as $ck => $aliases) {
            if (isset($sc[$ck])) continue;
            foreach ($aliases as $al) { if (isset($sc[$al])) { $sc[$ck] = $sc[$al]; break; } }
        }
        $qa['scorecard'] = $sc;
        $masterBlueprint['qa'] = $qa;

        return $masterBlueprint;
    }

    /** @return list<string> */
    private function stringToList(string $value): array
    {
        $normalized = preg_split('/(?:\r?\n|\.\s+)/', trim($value)) ?: [];
        return array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B.,;"), $normalized)));
    }

    /** @return list<string> */
    private function stringToCommaList(string $value): array
    {
        $normalized = preg_split('/(?:\r?\n|,)/', trim($value)) ?: [];
        return array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B.,;"), $normalized)));
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool { return array_keys($value) === range(0, count($value) - 1); }

    /** @param array<mixed> $value @return list<string> */
    private function flattenToStringList(array $value): array
    {
        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) { $items[] = trim($item); continue; }
            if (is_array($item)) { $items = [...$items, ...$this->flattenToStringList($item)]; }
        }
        return array_values(array_filter($items, static fn (string $item): bool => '' !== $item));
    }

    /** @param array<mixed> $value */
    private function flattenToSentence(array $value): string { return implode(', ', $this->flattenToStringList($value)); }

    private function isTransientFailure(\RuntimeException $exception): bool
    {
        $msg = strtolower($exception->getMessage());
        return str_contains($msg, 'prediction interrupted') || str_contains($msg, '(code: pa)')
            || str_contains($msg, 'async prediction failed') || str_contains($msg, 'readerror');
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $encoded) throw new \RuntimeException('JSON encoding failed.');
        return $encoded;
    }
}
