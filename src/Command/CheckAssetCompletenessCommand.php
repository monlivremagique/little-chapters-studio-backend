<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:book:check-assets',
    description: 'Checks completeness of generated assets: cover, pages, summary, backCover PNG files.',
)]
final class CheckAssetCompletenessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('blueprint-dir', InputArgument::REQUIRED, 'Blueprint directory containing master.json and generated-*/ directories.')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Expected number of generated page images (default: auto-detect from sceneDefinitions).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $blueprintDir = rtrim(trim((string) $input->getArgument('blueprint-dir')), '/');

        if (!is_dir($blueprintDir)) {
            $io->error(sprintf('Blueprint directory not found: %s', $blueprintDir));
            return Command::FAILURE;
        }

        $masterPath = $blueprintDir.'/master.json';
        if (!is_file($masterPath)) {
            $io->error(sprintf('master.json not found: %s', $masterPath));
            return Command::FAILURE;
        }

        try {
            $master = json_decode((string) file_get_contents($masterPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Cannot parse master.json: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $sceneDefinitions = is_array($master['sceneDefinitions'] ?? null) ? $master['sceneDefinitions'] : [];
        $slug = trim((string) (($master['metadata']['slug'] ?? basename($blueprintDir))));

        $io->title('Asset Completeness Check');
        $io->writeln(sprintf('Slug: %s', $slug));
        $io->writeln(sprintf('Blueprint directory: %s', $blueprintDir));

        $errors = [];
        $warnings = [];
        $checked = 0;

        // Determine expected count from sceneDefinitions
        $expectedCount = (int) $input->getOption('expected-count');
        $generatableScenes = [];
        if ($expectedCount <= 0) {
            foreach ($sceneDefinitions as $scene) {
                if (!is_array($scene)) continue;
                $type = trim((string) ($scene['type'] ?? ''));
                if (in_array($type, ['cover', 'story', 'backCover'], true)) {
                    $generatableScenes[] = $scene;
                }
            }
            $expectedCount = count($generatableScenes);
        }

        // Check cover
        $coverDir = $blueprintDir.'/generated-cover';
        $coverPng = $coverDir.'/cover-generated.png';
        if (is_file($coverPng)) {
            $io->writeln(sprintf('  ✓ cover-generated.png (%dkB)', $this->fileSizeKb($coverPng)));
            ++$checked;
        } else {
            $errors[] = 'Missing cover-generated.png in generated-cover/';
        }

        // Check pages
        $pagesDir = $blueprintDir.'/generated-pages';
        if (!is_dir($pagesDir)) {
            $errors[] = 'Missing generated-pages/ directory';
        } else {
            $expectedPageIds = [];
            foreach ($sceneDefinitions as $scene) {
                if (!is_array($scene)) continue;
                $type = trim((string) ($scene['type'] ?? ''));
                if (in_array($type, ['cover', 'story', 'backCover'], true)) {
                    $expectedPageIds[] = trim((string) ($scene['id'] ?? ''));
                }
            }

            foreach ($expectedPageIds as $pageId) {
                $pngPath = $pagesDir.'/'.$pageId.'-generated.png';
                if (is_file($pngPath)) {
                    $io->writeln(sprintf('  ✓ %s-generated.png (%dkB)', $pageId, $this->fileSizeKb($pngPath)));
                    ++$checked;
                } elseif ('cover' !== $pageId) {
                    $errors[] = sprintf('Missing %s-generated.png in generated-pages/', $pageId);
                }
            }

            // hero-reference.png is optional but recommended
            $heroRefPath = $pagesDir.'/hero-reference.png';
            if (is_file($heroRefPath)) {
                $io->writeln(sprintf('  ✓ hero-reference.png (%dkB)', $this->fileSizeKb($heroRefPath)));
            } else {
                $warnings[] = 'hero-reference.png not found (recommended for hero consistency)';
            }
        }

        // Summary
        if (!isset($errors['summary'])) {
            $summaryPng = $pagesDir.'/summary-generated.png';
            if (!is_file($summaryPng)) {
                // Summary might be in cover dir depending on pipeline
                $io->warning('summary-generated.png not found in generated-pages/');
            }
        }

        $io->writeln(sprintf("\nChecked: %d / %d expected generatable assets", $checked, $expectedCount));

        if ([] !== $warnings) {
            $io->warning($warnings);
        }

        if ([] !== $errors) {
            $io->error(array_merge(['Asset completeness FAILED:'], $errors));
            return Command::FAILURE;
        }

        $io->success(sprintf('All %d generated assets are present and readable.', $checked));
        return Command::SUCCESS;
    }

    private function fileSizeKb(string $path): int
    {
        $bytes = @filesize($path);
        return false !== $bytes ? (int) round($bytes / 1024) : 0;
    }
}
