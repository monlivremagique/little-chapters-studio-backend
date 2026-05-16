<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\PipelineCheckpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:book:create-from-blueprint',
    description: 'Builds the local premium pilot workflow from a validated blueprint with explicit image generation guards.',
)]
final class CreateFromBlueprintCommand extends Command
{
    /** @var list<string> */
    private const REQUIRED_LOCALES = ['fr', 'en', 'nl'];

    /** @var list<string> */
    private const HERO_REFERENCE_PAGES = ['page_2', 'page_3', 'page_4', 'page_5', 'page_6'];

    /** @var list<string> */
    private const NO_HERO_PAGES = ['dedication', 'summary'];

    private const STEP_VALIDATE = 'master_validated';
    private const STEP_RUNTIMES = 'runtimes_generated';
    private const STEP_RUNTIMES_VALIDATED = 'runtimes_validated';
    private const STEP_COVER_DRY = 'cover_dry_run';
    private const STEP_IMAGES = 'images_generated';
    private const STEP_CATALOG_SYNC = 'catalog_synced';
    private const STEP_CATALOG_VERIFY = 'catalog_verified';

    public function __construct(
        private readonly ValidateBlueprintCommand $validateBlueprintCommand,
        private readonly GenerateBlueprintCommand $generateBlueprintCommand,
        private readonly GenerateCoverCommand $generateCoverCommand,
        private readonly GeneratePagesCommand $generatePagesCommand,
        private readonly SyncBookBlueprintsCommand $syncBookBlueprintsCommand,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Blueprint slug, e.g. forest-of-lost-stars.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Optional explicit master blueprint path.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Optional runtime output directory.')
            ->addOption('cover-output-dir', null, InputOption::VALUE_REQUIRED, 'Optional cover output directory.')
            ->addOption('pages-output-dir', null, InputOption::VALUE_REQUIRED, 'Optional pages output directory.')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL used to verify local API and assets, e.g. http://nginx or http://localhost:8001.')
            ->addOption('generate-images', null, InputOption::VALUE_NONE, 'Explicitly allow real Replicate calls for cover and page generation.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force regeneration of cover/page assets when image generation is enabled.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        $sourcePath = trim((string) $input->getOption('source'));
        $baseUrl = rtrim(trim((string) $input->getOption('base-url')), '/');
        $generateImages = (bool) $input->getOption('generate-images');
        $force = (bool) $input->getOption('force');

        if ('' === $sourcePath) {
            if ('' === $slug) {
                $io->error('Provide either the <slug> argument or the --source option.');
                return Command::FAILURE;
            }
            $sourcePath = sprintf('%s/resources/book-blueprints/%s/master.json', $this->projectDir, $slug);
        }

        if ('' === $slug) {
            $slug = basename(dirname($sourcePath));
        }

        $runtimeOutputDir = trim((string) $input->getOption('output-dir'));
        if ('' === $runtimeOutputDir) {
            $runtimeOutputDir = sprintf('%s/resources/book-blueprints/%s/generated', $this->projectDir, $slug);
        }

        $coverOutputDir = trim((string) $input->getOption('cover-output-dir'));
        if ('' === $coverOutputDir) {
            $coverOutputDir = sprintf('%s/resources/book-blueprints/%s/generated-cover', $this->projectDir, $slug);
        }

        $pagesOutputDir = trim((string) $input->getOption('pages-output-dir'));
        if ('' === $pagesOutputDir) {
            $pagesOutputDir = sprintf('%s/resources/book-blueprints/%s/generated-pages', $this->projectDir, $slug);
        }

        $blueprintDir = dirname($sourcePath);
        $checkpoint = new PipelineCheckpoint($blueprintDir);

        $coverPath = $coverOutputDir.'/cover-generated.png';
        $heroReferencePath = $pagesOutputDir.'/hero-reference.png';
        $sourceBlueprint = $this->decodeJsonFile($sourcePath);
        $apiSlug = trim((string) (($sourceBlueprint['metadata']['slug'] ?? $slug)));
        if ('' === $apiSlug) {
            $apiSlug = $slug;
        }

        $io->title('Book Pilot Build');
        $io->writeln(sprintf('Slug: %s', $slug));
        $io->writeln(sprintf('API slug: %s', $apiSlug));
        $io->writeln(sprintf('Source: %s', $sourcePath));
        $io->writeln(sprintf('Generate images: %s', $generateImages ? 'yes' : 'no'));
        $io->writeln(sprintf('Force: %s', $force ? 'yes' : 'no'));
        $io->writeln(sprintf('Base URL: %s', '' !== $baseUrl ? $baseUrl : '(missing)'));
        $completedSteps = $checkpoint->getCompletedSteps();
        if ([] !== $completedSteps) {
            $io->writeln(sprintf('Resume mode: <comment>%d step(s) already completed</comment> (%s)',
                count($completedSteps), implode(', ', $completedSteps)));
        }

        if ('' === $baseUrl) {
            $io->error('The --base-url option is required so the command can stop on local API or asset HTTP failures.');
            return Command::FAILURE;
        }

        try {
            // ─── 3a: Validate master blueprint ──────────────────────────
            $io->section('Blueprint 3a — Validate master blueprint');
            $this->runStepSkipCheckpoint($checkpoint, self::STEP_VALIDATE,
                'Validate master blueprint', $this->validateBlueprintCommand, [
                    '--file' => $sourcePath,
                ], $output);

            // ─── 3b: Generate localized runtimes ────────────────────────
            $io->section('Blueprint 3b — Generate localized runtimes (FR/NL/EN)');
            $this->runStepSkipCheckpoint($checkpoint, self::STEP_RUNTIMES,
                'Generate localized runtimes', $this->generateBlueprintCommand, [
                    '--source' => $sourcePath,
                    '--output-dir' => $runtimeOutputDir,
                ], $output);
            foreach (self::REQUIRED_LOCALES as $locale) {
                $io->writeln(sprintf('  ✓ runtime.%s.json', $locale));
            }

            // ─── 3c: Validate each runtime ──────────────────────────────
            $io->section('Blueprint 3c — Validate each runtime (FR, EN, NL)');
            $runtimeValidatedAll = true;
            foreach (self::REQUIRED_LOCALES as $locale) {
                $runtimeStep = self::STEP_RUNTIMES_VALIDATED.'_'.$locale;
                if ($checkpoint->isCompleted($runtimeStep)) {
                    $io->writeln(sprintf('<info>  ✓ %s already validated — skipping</info>', $locale));
                } else {
                    $this->runStep(sprintf('Validate runtime %s', $locale), $this->validateBlueprintCommand, [
                        '--file' => sprintf('%s/runtime.%s.json', $runtimeOutputDir, $locale),
                        '--runtime' => true,
                    ], $output);
                    $checkpoint->markCompleted($runtimeStep, 0.0);
                    $io->writeln(sprintf('  ✓ runtime.%s.json valid', $locale));
                }
                if (!$checkpoint->isCompleted($runtimeStep)) {
                    $runtimeValidatedAll = false;
                }
            }
            if ($runtimeValidatedAll) {
                $io->writeln('<info>  ✓ all runtimes validated</info>');
            }

            // ─── 3d: Dry-run cover prompt ───────────────────────────────
            $io->section('Blueprint 3d — Dry-run cover prompt');
            $this->runStepSkipCheckpoint($checkpoint, self::STEP_COVER_DRY,
                'Dry-run cover prompt', $this->generateCoverCommand, [
                    '--source' => $sourcePath,
                    '--output-dir' => $coverOutputDir,
                    '--dry-run' => true,
                ], $output);
            $io->writeln('<info>  ✓ cover prompt built</info>');

            // ─── 3e: Image generation (most expensive) ──────────────────
            if ($generateImages) {
                $io->section('Blueprint 3e — Generate images (cover + pages + backCover)');

                if ($checkpoint->isCompleted(self::STEP_IMAGES)) {
                    $io->writeln('<info>  ✓ all images already generated — skipping</info>');
                } else {
                    // Build dedicated hero portrait prompt from the master blueprint's heroBible
                    $heroBible = is_array($sourceBlueprint['heroBible'] ?? null) ? $sourceBlueprint['heroBible'] : [];
                    $characterDesign = trim((string) ($heroBible['characterDesign'] ?? ''));
                    $styleRules = is_array($sourceBlueprint['visualBible']['style_rules'] ?? null) ? $sourceBlueprint['visualBible']['style_rules'] : [];
                    $styleDesc = implode(', ', $styleRules);
                    $promise = trim((string) ($sourceBlueprint['metadata']['promise'] ?? ''));

                    $heroPortraitPrompt = sprintf(
                        'Premium children\'s book character portrait. %s. Character: %s. Style: %s. Front-facing portrait, bust or medium shot, hero looking warmly at viewer, soft neutral warm-toned background. No text, no letters, no scene context, no action. Pure character reference illustration.',
                        $promise,
                        $characterDesign,
                        $styleDesc,
                    );

                    $heroPortraitArgs = [
                        '--source' => $sourcePath,
                        '--output-dir' => $pagesOutputDir,
                        '--hero-prompt' => $heroPortraitPrompt,
                    ];
                    if ($force) {
                        $heroPortraitArgs['--force'] = true;
                    }

                    $io->writeln('  Generating dedicated hero portrait...');
                    $this->runStep('Generate hero reference portrait', $this->generatePagesCommand, $heroPortraitArgs, $output);
                    $this->assertPhysicalFile($heroReferencePath, 'hero reference image');
                    $io->writeln('  ✓ hero-reference.png (dedicated portrait)');

                    // Generate page_1 with the hero reference (not as hero-ref itself)
                    $pageOneArguments = [
                        '--source' => $sourcePath,
                        '--output-dir' => $pagesOutputDir,
                        '--page' => 'page_1',
                        '--hero-reference' => $heroReferencePath,
                    ];
                    if ($force) {
                        $pageOneArguments['--force'] = true;
                    }

                    $io->writeln('  Generating page_1 with hero reference...');
                    $this->runStep('Generate page_1', $this->generatePagesCommand, $pageOneArguments, $output);
                    $this->assertPhysicalFile($pagesOutputDir.'/page_1-generated.png', 'page_1 image');
                    $io->writeln('  ✓ page_1-generated.png');

                    $coverArguments = [
                        '--source' => $sourcePath,
                        '--output-dir' => $coverOutputDir,
                        '--hero-reference' => $heroReferencePath,
                    ];
                    if ($force) {
                        $coverArguments['--force'] = true;
                    }

                    $io->writeln('  Generating cover...');
                    $this->runStep('Generate cover (with hero reference)', $this->generateCoverCommand, $coverArguments, $output);
                    $this->assertPhysicalFile($coverPath, 'cover image');
                    $io->writeln('  ✓ cover-generated.png');

                    // Dedication: thematic background WITHOUT hero (text will be overlaid later)
                    $dedicationArgs = [
                        '--source' => $sourcePath,
                        '--output-dir' => $pagesOutputDir,
                        '--page' => 'dedication',
                    ];
                    if ($force) {
                        $dedicationArgs['--force'] = true;
                    }
                    $io->writeln('  Generating dedication (thematic bg, no hero)...');
                    $this->runStep('Generate dedication', $this->generatePagesCommand, $dedicationArgs, $output);
                    $this->assertPhysicalFile($pagesOutputDir.'/dedication-generated.png', 'dedication image');
                    $io->writeln('  ✓ dedication-generated.png');

                    foreach (self::HERO_REFERENCE_PAGES as $pageId) {
                        $pageArguments = [
                            '--source' => $sourcePath,
                            '--cover' => $coverPath,
                            '--hero-reference' => $heroReferencePath,
                            '--output-dir' => $pagesOutputDir,
                            '--page' => $pageId,
                        ];
                        if ($force) {
                            $pageArguments['--force'] = true;
                        }

                        $io->writeln(sprintf('  Generating %s...', $pageId));
                        $this->runStep(sprintf('Generate %s with hero reference', $pageId), $this->generatePagesCommand, $pageArguments, $output);
                        $this->assertPhysicalFile(sprintf('%s/%s-generated.png', $pagesOutputDir, $pageId), sprintf('%s image', $pageId));
                        $io->writeln(sprintf('  ✓ %s-generated.png', $pageId));
                    }

                    // Summary: thematic background WITHOUT hero (text will be overlaid later)
                    $summaryArgs = [
                        '--source' => $sourcePath,
                        '--output-dir' => $pagesOutputDir,
                        '--page' => 'summary',
                    ];
                    if ($force) {
                        $summaryArgs['--force'] = true;
                    }
                    $io->writeln('  Generating summary (thematic bg, no hero)...');
                    $this->runStep('Generate summary', $this->generatePagesCommand, $summaryArgs, $output);
                    $this->assertPhysicalFile($pagesOutputDir.'/summary-generated.png', 'summary image');
                    $io->writeln('  ✓ summary-generated.png');

                    $backCoverArguments = [
                        '--source' => $sourcePath,
                        '--cover' => $coverPath,
                        '--hero-reference' => $heroReferencePath,
                        '--output-dir' => $pagesOutputDir,
                        '--page' => 'backCover',
                    ];
                    if ($force) {
                        $backCoverArguments['--force'] = true;
                    }

                    $io->writeln('  Generating backCover...');
                    $this->runStep('Generate backCover (with hero reference)', $this->generatePagesCommand, $backCoverArguments, $output);
                    $this->assertPhysicalFile($pagesOutputDir.'/backCover-generated.png', 'backCover image');
                    $io->writeln('  ✓ backCover-generated.png');

                    $checkpoint->markCompleted(self::STEP_IMAGES, 0.0);
                    $io->writeln('<info>  ✓ all images generated</info>');
                }

                $io->writeln(sprintf('<fg=blue>Output:</> %s/*.png and %s/*.png', $coverOutputDir, $pagesOutputDir));
            } else {
                $io->note('Real image generation skipped. Pass --generate-images to allow Replicate calls.');
            }

            // ─── 3f: Sync Sylius local catalog ──────────────────────────
            $io->section('Blueprint 3f — Sync Sylius local catalog');
            $this->runStepSkipCheckpoint($checkpoint, self::STEP_CATALOG_SYNC,
                'Sync Sylius local catalog', $this->syncBookBlueprintsCommand, [], $output);
            $io->writeln('<info>  ✓ Sylius catalog synced</info>');

            // ─── 3g: Verify local book state ────────────────────────────
            $io->section('Blueprint 3g — Verify local book state (API + assets HTTP 200)');
            $this->runStepSkipCheckpoint($checkpoint, self::STEP_CATALOG_VERIFY,
                'Verify local book state', $this->makeVerifyStep($apiSlug, $baseUrl), [], $output);
            $io->writeln('<info>  ✓ local verification passed</info>');

        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Pilot workflow completed successfully.');

        return Command::SUCCESS;
    }

    /** @param array<string, string|bool|int|float|null> $arguments */
    private function runStep(string $label, Command $command, array $arguments, OutputInterface $output): void
    {
        $stepOutput = new BufferedOutput();
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);
        $exitCode = $command->run($input, $stepOutput);

        if (Command::SUCCESS !== $exitCode) {
            throw new \RuntimeException(sprintf("%s failed.\n%s", $label, trim($stepOutput->fetch())));
        }

        $captured = trim($stepOutput->fetch());
        if ('' !== $captured) {
            $output->writeln(sprintf('<info>[%s]</info>', $label));
            $output->writeln($captured);
        }
    }

    /** @param array<string, string|bool|int|float|null> $arguments */
    private function runStepSkipCheckpoint(
        PipelineCheckpoint $checkpoint,
        string $stepKey,
        string $label,
        Command $command,
        array $arguments,
        OutputInterface $output,
    ): void {
        if ($checkpoint->isCompleted($stepKey)) {
            $output->writeln(sprintf('<info>  ✓ %s already completed — skipping</info>', $label));
            return;
        }

        $this->runStep($label, $command, $arguments, $output);
        $checkpoint->markCompleted($stepKey, 0.0);
    }

    private function assertPhysicalFile(string $path, string $label): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Missing %s at "%s".', $label, $path));
        }
    }

    private function makeVerifyStep(string $slug, string $baseUrl): Command
    {
        return new class($slug, $baseUrl, $this->httpClient, $this->projectDir) extends Command {
            public function __construct(
                private readonly string $slug,
                private readonly string $baseUrl,
                private readonly HttpClientInterface $httpClient,
                private readonly string $projectDir,
            ) {
                parent::__construct('_verify_local_book_state');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                try {
                    $this->verifyLocalBookState($this->slug, $this->baseUrl, $io);
                    return Command::SUCCESS;
                } catch (\RuntimeException $e) {
                    $io->error($e->getMessage());
                    return Command::FAILURE;
                }
            }

            private function verifyLocalBookState(string $slug, string $baseUrl, SymfonyStyle $io): void
            {
                $catalog = $this->requestJson(sprintf('%s/api/books', $baseUrl));
                $catalogSlugs = array_map(
                    static fn (array $entry): string => (string) ($entry['slug'] ?? ''),
                    array_filter($catalog, 'is_array'),
                );
                if (!in_array($slug, $catalogSlugs, true)) {
                    throw new \RuntimeException(sprintf('Book "%s" does not appear in GET /api/books catalog.', $slug));
                }
                $io->writeln(sprintf('  ✓ book "%s" appears in catalog', $slug));

                $payloads = [];
                foreach (['fr', 'en', 'nl'] as $locale) {
                    $payloads[$locale] = $this->requestJson(sprintf('%s/api/books/%s?locale=%s', $baseUrl, rawurlencode($slug), $locale));
                    $bookBlueprint = is_array($payloads[$locale]['bookBlueprint'] ?? null) ? $payloads[$locale]['bookBlueprint'] : null;
                    if (null === $bookBlueprint) {
                        throw new \RuntimeException(sprintf('Local API did not return bookBlueprint for locale "%s".', $locale));
                    }
                    $metadata = is_array($bookBlueprint['metadata'] ?? null) ? $bookBlueprint['metadata'] : [];
                    if (($metadata['locale'] ?? null) !== $locale) {
                        throw new \RuntimeException(sprintf('Local API returned the wrong blueprint locale for "%s".', $locale));
                    }
                    $io->writeln(sprintf('  ✓ locale %s: bookBlueprint OK', $locale));
                }

                $frPages = is_array($payloads['fr']['bookBlueprint']['pages'] ?? null) ? $payloads['fr']['bookBlueprint']['pages'] : [];
                if ([] === $frPages) {
                    throw new \RuntimeException('FR API payload contains no pages.');
                }
                $expectedPageIds = array_map(
                    static fn (array $page): string => (string) ($page['id'] ?? ''),
                    array_filter($frPages, 'is_array'),
                );
                $io->writeln(sprintf('  ✓ FR page count: %d', count($expectedPageIds)));

                foreach (['en', 'nl'] as $locale) {
                    $pages = is_array($payloads[$locale]['bookBlueprint']['pages'] ?? null) ? $payloads[$locale]['bookBlueprint']['pages'] : [];
                    $pageIds = array_map(
                        static fn (array $page): string => (string) ($page['id'] ?? ''),
                        array_filter($pages, 'is_array'),
                    );
                    if ($pageIds !== $expectedPageIds) {
                        throw new \RuntimeException(sprintf('Page order diverged for locale "%s".', $locale));
                    }
                }
                $io->writeln('  ✓ page IDs consistent across all 3 locales');

                $brokenImages = [];
                foreach (['fr', 'en', 'nl'] as $locale) {
                    $pages = is_array($payloads[$locale]['bookBlueprint']['pages'] ?? null) ? $payloads[$locale]['bookBlueprint']['pages'] : [];
                    foreach ($pages as $page) {
                        if (!is_array($page)) continue;
                        $pageId = (string) ($page['id'] ?? 'page');
                        $path = trim((string) ($page['default_image_path'] ?? ''));
                        if ('' === $path) {
                            throw new \RuntimeException(sprintf('Locale "%s" page "%s" is missing its default_image_path.', $locale, $pageId));
                        }
                        $publicPath = $this->projectDir.'/public'.$path;
                        if (!is_file($publicPath) || !is_readable($publicPath)) {
                            throw new \RuntimeException(sprintf('Missing physical asset for locale "%s" page "%s": "%s".', $locale, $pageId, $publicPath));
                        }
                        try {
                            $response = $this->httpClient->request('GET', $baseUrl.$path);
                            $statusCode = $response->getStatusCode();
                        } catch (\Throwable) {
                            $statusCode = null;
                        }
                        if (200 !== $statusCode) {
                            $brokenImages[] = ['locale' => $locale, 'id' => $pageId, 'path' => $path, 'status' => $statusCode];
                        }
                    }
                }

                if ([] !== $brokenImages) {
                    throw new \RuntimeException(sprintf('Broken images detected: %s', json_encode($brokenImages, JSON_UNESCAPED_SLASHES)));
                }

                $io->writeln('  ✓ all asset images return HTTP 200');
            }

            /** @return array<string, mixed> */
            private function requestJson(string $url): array
            {
                try {
                    $response = $this->httpClient->request('GET', $url);
                    $statusCode = $response->getStatusCode();
                    if (200 !== $statusCode) {
                        throw new \RuntimeException(sprintf('HTTP %d for "%s".', $statusCode, $url));
                    }
                    $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException(sprintf('Local API verification failed for "%s": %s', $url, $exception->getMessage()));
                }
                if (!is_array($decoded)) {
                    throw new \RuntimeException(sprintf('Local API returned a non-object payload for "%s".', $url));
                }
                return $decoded;
            }
        };
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }
}
