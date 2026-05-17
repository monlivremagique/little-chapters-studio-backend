<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\PageGenerationService;
use App\Integration\Replicate\ReplicatePredictionClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:book:generate-pages',
    description: 'Generates admin story page images from a Master Blueprint V2 using Replicate FLUX 2 Pro.',
)]
final class GeneratePagesCommand extends Command
{
    private const REQUIRED_MODEL = 'black-forest-labs/flux-2-pro';
    private const POLL_TIMEOUT_SECONDS = 180;
    private const POLL_SLEEP_MICROSECONDS = 1500000;
    private const MAX_GENERATION_ATTEMPTS = 3;
    private const RETRY_SLEEP_MICROSECONDS = 1000000;

    public function __construct(
        private readonly PageGenerationService $pageGenerationService,
        private readonly ReplicatePredictionClientInterface $replicatePredictionClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to the master blueprint JSON file.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Optional page id to generate, e.g. page_1.')
            ->addOption('photo', null, InputOption::VALUE_REQUIRED, 'Optional child photo path.')
            ->addOption('cover', null, InputOption::VALUE_REQUIRED, 'Path to the approved cover image.')
            ->addOption('hero-reference', null, InputOption::VALUE_REQUIRED, 'Optional approved hero reference image path to lock the hero across pages.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Optional output directory.')
            ->addOption('hero-prompt', null, InputOption::VALUE_REQUIRED, 'When set, generate a dedicated hero reference portrait with this custom prompt (no scene context).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate pages even if the image file already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Build page prompts and debug payloads without calling Replicate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourcePath = trim((string) $input->getOption('source'));
        $pageId = trim((string) $input->getOption('page'));
        $photoPath = trim((string) $input->getOption('photo'));
        $heroReferencePath = trim((string) $input->getOption('hero-reference'));
        $heroPrompt = trim((string) $input->getOption('hero-prompt'));
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ('' === $sourcePath || !is_file($sourcePath) || !is_readable($sourcePath)) {
            $io->error('The --source option is required and must point to a readable master blueprint JSON file.');

            return Command::FAILURE;
        }

        $outputDir = rtrim(trim((string) $input->getOption('output-dir')), '/');
        if ('' === $outputDir || $outputDir === dirname($sourcePath)) {
            $outputDir = dirname($sourcePath).'/generated-pages';
        }

        $coverPath = trim((string) $input->getOption('cover'));
        if ('' === $coverPath) {
            $coverPath = dirname($sourcePath).'/generated-cover/cover-generated.png';
        }
        // Cover is optional for the hero-reference page (page_1 runs before cover in the hero-locking pipeline)
        $resolvedCoverPath = is_file($coverPath) && is_readable($coverPath) ? $coverPath : null;

        try {
            $masterBlueprint = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $io->error(sprintf('Invalid JSON: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if (!is_array($masterBlueprint)) {
            $io->error('The master blueprint root must be a JSON object.');

            return Command::FAILURE;
        }

        try {
            $scenes = $this->pageGenerationService->validateAndExtractGeneratableScenes(
                $masterBlueprint,
                '' !== $pageId ? $pageId : null,
            );
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ((!file_exists($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) || (file_exists($outputDir) && !is_dir($outputDir))) {
            $io->error(sprintf('The output directory "%s" is not writable.', $outputDir));

            return Command::FAILURE;
        }

        $io->section('Page Generation Report');
        $io->writeln(sprintf('Source: %s', $sourcePath));
        $io->writeln(sprintf('Output directory: %s', $outputDir));
        $io->writeln(sprintf('Cover reference: %s', $coverPath));
        $io->writeln(sprintf('Hero reference: %s', '' !== $heroReferencePath ? $heroReferencePath : 'none'));
        $io->writeln(sprintf('Hero prompt: %s', '' !== $heroPrompt ? '(custom portrait prompt)' : 'none (uses scene prompt)'));
        $io->writeln(sprintf('Dry run: %s', $dryRun ? 'yes' : 'no'));
        $io->writeln(sprintf('Force: %s', $force ? 'yes' : 'no'));
        $io->writeln(sprintf('Photo provided: %s', '' !== $photoPath ? 'yes' : 'no'));
        $totalScenes = count($scenes);
        $existingScenes = 0;
        foreach ($scenes as $scene) {
            $sid = (string) ($scene['id'] ?? '');
            $genPath = sprintf('%s/%s-generated.png', $outputDir, $sid);
            if (!$force && !$dryRun && is_file($genPath)) {
                $existingScenes++;
            }
        }
        $toGenerate = $totalScenes - $existingScenes;
        if ($dryRun) $toGenerate = $totalScenes;
        $io->writeln(sprintf('Pages (%d total, %d to generate): %s',
            $totalScenes,
            $toGenerate,
            implode(', ', array_map(static fn (array $scene): string => (string) ($scene['id'] ?? 'page'), $scenes))
        ));

        if (!$dryRun) {
            $this->replicatePredictionClient->assertConfigured();

            if ($this->replicatePredictionClient->getModelReference() !== self::REQUIRED_MODEL) {
                $io->error(sprintf(
                    'REPLICATE_MODEL must be set to "%s" for this command. Current value: "%s".',
                    self::REQUIRED_MODEL,
                    $this->replicatePredictionClient->getModelReference(),
                ));

                return Command::FAILURE;
            }
        }

        // If hero-prompt is set, generate a dedicated hero reference portrait (no scene context)
        if ('' !== $heroPrompt && !$dryRun && !$force && is_file(sprintf('%s/hero-reference.png', $outputDir))) {
            $io->writeln('<info>[hero-reference] Reusing existing hero portrait.</info>');
        } elseif ('' !== $heroPrompt) {
            $this->generateHeroPortrait($heroPrompt, $masterBlueprint, $outputDir, $dryRun, $force, $io);
        }

        foreach ($scenes as $scene) {
            $sceneId = (string) ($scene['id'] ?? 'page');

            try {
                $payload = $this->pageGenerationService->buildPageGenerationPayload(
                    $masterBlueprint,
                    $scene,
                    $resolvedCoverPath,
                    '' !== $photoPath ? $photoPath : null,
                    '' !== $heroReferencePath ? $heroReferencePath : null,
                );
            } catch (\RuntimeException $exception) {
                $io->error(sprintf('[%s] %s', $sceneId, $exception->getMessage()));

                return Command::FAILURE;
            }

            $generatedPath = sprintf('%s/%s-generated.png', $outputDir, $sceneId);
            if (!$dryRun && !$force && is_file($generatedPath)) {
                $this->writeDebugArtifacts(
                    $sourcePath,
                    $outputDir,
                    $resolvedCoverPath,
                    '' !== $photoPath ? $photoPath : null,
                    $sceneId,
                    $payload['prompt'],
                    $payload['negativePrompt'],
                    $payload['input'],
                    false,
                    [
                        'status' => 'skipped',
                        'reason' => 'existing_output',
                        'output' => [$generatedPath],
                    ],
                );
                $io->writeln(sprintf('[%s] Reused existing generated page.', $sceneId));

                continue;
            }

            if ($dryRun) {
                $this->writeDebugArtifacts(
                    $sourcePath,
                    $outputDir,
                    $resolvedCoverPath,
                    '' !== $photoPath ? $photoPath : null,
                    $sceneId,
                    $payload['prompt'],
                    $payload['negativePrompt'],
                    $payload['input'],
                    true,
                    null,
                );
                $io->writeln(sprintf('[%s] Dry-run payload written.', $sceneId));

                continue;
            }

            try {
                $this->writeDebugArtifacts(
                    $sourcePath,
                    $outputDir,
                    $resolvedCoverPath,
                    '' !== $photoPath ? $photoPath : null,
                    $sceneId,
                    $payload['prompt'],
                    $payload['negativePrompt'],
                    $payload['input'],
                    false,
                    [
                        'status' => 'prepared',
                        'prediction' => null,
                    ],
                );
                $finalPrediction = $this->runPredictionWithRetry($payload['input']);
                $outputUrls = $this->extractOutputUrls($finalPrediction['output'] ?? null);

                if ([] === $outputUrls) {
                    throw new \RuntimeException('Replicate completed without an output image URL.');
                }

                $downloaded = $this->replicatePredictionClient->downloadFile($outputUrls[0]);
                if (false === file_put_contents($generatedPath, $downloaded['content']) || !is_file($generatedPath)) {
                    throw new \RuntimeException(sprintf('The generated page could not be written to "%s".', $generatedPath));
                }

                $this->writeDebugArtifacts(
                    $sourcePath,
                    $outputDir,
                    $resolvedCoverPath,
                    '' !== $photoPath ? $photoPath : null,
                    $sceneId,
                    $payload['prompt'],
                    $payload['negativePrompt'],
                    $payload['input'],
                    false,
                    $finalPrediction,
                );
                $io->writeln(sprintf('[%s] Generated successfully.', $sceneId));
            } catch (\RuntimeException $exception) {
                $io->error(sprintf('[%s] %s', $sceneId, $exception->getMessage()));

                return Command::FAILURE;
            }
        }

        $io->success($dryRun
            ? 'Page prompts built successfully in dry-run mode.'
            : 'Pages generated successfully.');

        return Command::SUCCESS;
    }

    /**
     * Generate a dedicated hero reference portrait (character sheet style, no scene context).
     */
    private function generateHeroPortrait(string $heroPrompt, array $masterBlueprint, string $outputDir, bool $dryRun, bool $force, SymfonyStyle $io): void
    {
        $heroPath = sprintf('%s/hero-reference.png', $outputDir);
        if (!$force && is_file($heroPath)) {
            $io->writeln('<info>[hero-reference] Already exists, skipping.</info>');
            return;
        }

        $imageGeneration = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        $defaultNegative = trim((string) ($imageGeneration['negativePromptDefault'] ?? ''));

        $input = [
            'prompt' => $heroPrompt,
            'input_images' => [],
            'aspect_ratio' => '3:4',
            'resolution' => trim((string) ($imageGeneration['resolution'] ?? '1 MP')) ?: '1 MP',
            'output_format' => 'png',
        ];
        if ('' !== $defaultNegative) {
            $input['negative_prompt'] = $defaultNegative;
        }

        if ($dryRun) {
            file_put_contents(sprintf('%s/hero-reference-prompt.txt', $outputDir), $heroPrompt."\n");
            file_put_contents(sprintf('%s/hero-reference-debug.json', $outputDir), json_encode([
                'model' => self::REQUIRED_MODEL,
                'dryRun' => true,
                'input' => $input,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $io->writeln('<info>[hero-reference] Dry-run prompt written.</info>');
            return;
        }

        $finalPrediction = $this->runPredictionWithRetry($input);
        $outputUrls = $this->extractOutputUrls($finalPrediction['output'] ?? null);
        if ([] === $outputUrls) {
            throw new \RuntimeException('Replicate hero portrait generation completed without output URL.');
        }
        $downloaded = $this->replicatePredictionClient->downloadFile($outputUrls[0]);
        if (false === file_put_contents($heroPath, $downloaded['content']) || !is_file($heroPath)) {
            throw new \RuntimeException(sprintf('Hero portrait could not be written to "%s".', $heroPath));
        }

        file_put_contents(sprintf('%s/hero-reference-prompt.txt', $outputDir), $heroPrompt."\n");
        file_put_contents(sprintf('%s/hero-reference-debug.json', $outputDir), json_encode([
            'model' => self::REQUIRED_MODEL,
            'dryRun' => false,
            'input' => $input,
            'prediction' => $finalPrediction,
            'estimatedCostUsd' => 0.055,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $io->writeln(sprintf('<info>[hero-reference] Generated dedicated hero portrait: %s</info>', $heroPath));
    }

    /** @return array<string, mixed> */
    private function waitForPrediction(string $predictionId): array
    {
        if ('' === trim($predictionId)) {
            throw new \RuntimeException('Replicate did not return a prediction id.');
        }

        $startedAt = time();

        do {
            $prediction = $this->replicatePredictionClient->getPrediction($predictionId);
            $status = trim((string) ($prediction['status'] ?? 'processing'));

            if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                if ('succeeded' !== $status) {
                    throw new \RuntimeException(sprintf('Replicate page generation ended with status "%s": %s', $status, trim((string) ($prediction['error'] ?? 'unknown error'))));
                }

                return $prediction;
            }

            usleep(self::POLL_SLEEP_MICROSECONDS);
        } while ((time() - $startedAt) < self::POLL_TIMEOUT_SECONDS);

        throw new \RuntimeException(sprintf('Replicate page generation timed out after %d seconds.', self::POLL_TIMEOUT_SECONDS));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function runPredictionWithRetry(array $input): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_GENERATION_ATTEMPTS; ++$attempt) {
            try {
                $prediction = $this->replicatePredictionClient->createPrediction($input);

                return $this->waitForPrediction((string) ($prediction['id'] ?? ''));
            } catch (\RuntimeException $exception) {
                $lastException = $exception;
                if ($attempt >= self::MAX_GENERATION_ATTEMPTS || !$this->isTransientReplicateFailure($exception)) {
                    throw $exception;
                }

                usleep(self::RETRY_SLEEP_MICROSECONDS);
            }
        }

        throw $lastException ?? new \RuntimeException('Page generation failed unexpectedly.');
    }

    /** @return list<string> */
    private function extractOutputUrls(mixed $output): array
    {
        if (is_string($output) && '' !== trim($output)) {
            return [trim($output)];
        }

        if (!is_array($output)) {
            return [];
        }

        $urls = [];
        foreach ($output as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $urls[] = trim($value);
            }
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $replicateInputPayload
     * @param array<string, mixed>|null $prediction
     */
    private function writeDebugArtifacts(
        string $sourcePath,
        string $outputDir,
        ?string $coverPath,
        ?string $photoPath,
        string $sceneId,
        string $prompt,
        string $negativePrompt,
        array $replicateInputPayload,
        bool $dryRun,
        ?array $prediction,
    ): void {
        file_put_contents(sprintf('%s/%s-prompt.txt', $outputDir, $sceneId), $prompt."\n");
        file_put_contents(sprintf('%s/%s-negative-prompt.txt', $outputDir, $sceneId), $negativePrompt."\n");
        file_put_contents(sprintf('%s/%s-debug.json', $outputDir, $sceneId), json_encode([
            'source' => $sourcePath,
            'coverReference' => $coverPath,
            'model' => self::REQUIRED_MODEL,
            'scene' => $sceneId,
            'dryRun' => $dryRun,
            'photoProvided' => null !== $photoPath,
            'outputDir' => $outputDir,
            'prompt' => $prompt,
            'negativePrompt' => $negativePrompt,
            'replicateInputPayload' => $replicateInputPayload,
            'prediction' => $prediction,
            'estimatedCostUsd' => 0.055,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function isTransientReplicateFailure(\RuntimeException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'prediction interrupted')
            || str_contains($message, '(code: pa)')
            || str_contains($message, 'async prediction failed')
            || str_contains($message, 'readerror')
            || str_contains($message, 'error generating image');
    }
}
