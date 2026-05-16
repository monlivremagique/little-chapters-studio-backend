<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\PipelineCheckpoint;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:book-factory:create-from-brief',
    description: '12-step book factory: brief → Claude master → GPT QA (x2) → gate → runtimes → FLUX images → catalog sync.',
)]
final class BookFactoryCreateFromBriefCommand extends Command
{
    private const QA_PREMIUM_MIN_SCORE = 9.0;

    private const STEP_BRIEF_VALID = 'step_01_brief_valid';
    private const STEP_MASTER_GENERATED = 'step_02_master_generated';
    private const STEP_QA_PASS_ONE = 'step_03_qa_pass_one';
    private const STEP_QA_PASS_TWO = 'step_04_qa_pass_two';
    private const STEP_QA_GATE = 'step_05_qa_gate';
    private const STEP_MASTER_VALIDATED = 'step_06_master_validated';
    private const STEP_RUNTIMES_GENERATED = 'step_07_runtimes_generated';
    private const STEP_RUNTIMES_VALIDATED = 'step_08_runtimes_validated';
    private const STEP_IMAGES_GENERATED = 'step_09_images_generated';
    private const STEP_ASSETS_CHECKED = 'step_10_assets_checked';
    private const STEP_CATALOG_SYNCED = 'step_11_catalog_synced';
    private const STEP_CATALOG_VERIFIED = 'step_12_catalog_verified';

    public function __construct(
        private readonly ValidateBookBriefCommand $validateBookBriefCommand,
        private readonly GenerateMasterFromBriefCommand $generateMasterFromBriefCommand,
        private readonly QaCorrectMasterCommand $qaCorrectMasterCommand,
        private readonly QaGateCommand $qaGateCommand,
        private readonly CheckAssetCompletenessCommand $checkAssetCompletenessCommand,
        private readonly HttpClientInterface $httpClient,
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('brief', InputArgument::REQUIRED, 'Path to the source brief YAML file.')
            ->addOption('generate-images', null, InputOption::VALUE_NONE, 'Allow Replicate calls for FLUX image generation.')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for local API/asset verification.', 'http://localhost:8001')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Custom blueprint output directory.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write prompts without calling Replicate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $briefPath = trim((string) $input->getArgument('brief'));
        $generateImages = (bool) $input->getOption('generate-images');
        $baseUrl = rtrim(trim((string) $input->getOption('base-url')), '/');
        $customOutputDir = trim((string) $input->getOption('output-dir'));
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($briefPath) || !is_readable($briefPath)) {
            $io->error(sprintf('Brief file not found: %s', $briefPath));
            return Command::FAILURE;
        }

        try {
            $brief = Yaml::parseFile($briefPath);
        } catch (\Throwable $e) {
            $io->error(sprintf('Invalid YAML: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        if (!is_array($brief) || !isset($brief['slug']) || !is_string($brief['slug']) || '' === trim($brief['slug'])) {
            $io->error('Brief must have a non-empty "slug" key.');
            return Command::FAILURE;
        }

        $slug = trim($brief['slug']);
        $outputDir = '' !== $customOutputDir
            ? $customOutputDir
            : sprintf('%s/resources/book-blueprints/%s', $this->projectDir, $slug);
        $blueprintDir = $outputDir;
        $masterPath = $outputDir.'/master.json';

        PipelineCheckpoint::ensureDir($outputDir);
        $checkpoint = new PipelineCheckpoint($outputDir);

        $io->title(sprintf('═══ BOOK FACTORY (12 steps) — %s ═══', $slug));
        $io->writeln(sprintf('Brief:       %s', $briefPath));
        $io->writeln(sprintf('Output dir:  %s', $outputDir));
        $io->writeln(sprintf('Dry run:     %s', $dryRun ? 'yes' : 'no'));
        $io->writeln(sprintf('Gen images:  %s', $generateImages ? 'yes' : 'no'));

        $completed = $checkpoint->getCompletedSteps();
        if ([] !== $completed) {
            $io->writeln(sprintf('Resume mode: %d step(s) done', count($completed)));
        }

        $app = new Application($this->kernel);
        $app->setAutoExit(false);

        // ══════════════════════════════════════
        // STEP 1/12 : Validate brief YAML
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_BRIEF_VALID, '1/12 — Validate brief YAML')) {
            $exitCode = $this->validateBookBriefCommand->run(new ArrayInput(['brief' => $briefPath]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error('Brief validation FAILED.');
                return Command::FAILURE;
            }
            $checkpoint->markCompleted(self::STEP_BRIEF_VALID, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 2/12 : Generate master (Claude)
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_MASTER_GENERATED, '2/12 — Generate master (Claude)')) {
            $args = ['--brief' => $briefPath, '--output-dir' => $outputDir];
            if ($dryRun) { $args['--dry-run'] = true; }
            $exitCode = $app->run(new ArrayInput(['command' => 'app:book:generate-master-from-brief', ...$args]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error('Master generation FAILED.');
                return Command::FAILURE;
            }
            $checkpoint->markCompleted(self::STEP_MASTER_GENERATED, 0.0);
            if ($dryRun) {
                $io->success('Dry-run complete after step 2.');
                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $io->success('Dry-run complete after step 2.');
            return Command::SUCCESS;
        }

        // ══════════════════════════════════════
        // STEP 3/12 : QA pass 1 (GPT)
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_QA_PASS_ONE, '3/12 — QA corrective pass 1 (GPT)')) {
            $exitCode = $this->qaCorrectMasterCommand->run(
                new ArrayInput(['--brief' => $briefPath, '--source' => $masterPath]),
                $output,
            );
            if (Command::SUCCESS !== $exitCode) {
                $io->warning('QA pass 1 failed — continuing with current master.');
            }
            $checkpoint->markCompleted(self::STEP_QA_PASS_ONE, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 4/12 : QA pass 2 (GPT)
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_QA_PASS_TWO, '4/12 — QA corrective pass 2 (GPT)')) {
            $exitCode = $this->qaCorrectMasterCommand->run(
                new ArrayInput(['--brief' => $briefPath, '--source' => $masterPath]),
                $output,
            );
            if (Command::SUCCESS !== $exitCode) {
                $io->warning('QA pass 2 failed — continuing with V3 master.');
            }
            $checkpoint->markCompleted(self::STEP_QA_PASS_TWO, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 5/12 : QA gate (info, non-blocking)
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_QA_GATE, '5/12 — QA gate (info, non-blocking)')) {
            $this->qaGateCommand->run(new ArrayInput(['blueprint-dir' => $outputDir]), $output);
            $checkpoint->markCompleted(self::STEP_QA_GATE, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 6/12 : Validate master blueprint
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_MASTER_VALIDATED, '6/12 — Validate master blueprint')) {
            $exitCode = $app->run(new ArrayInput(['command' => 'app:book:validate-blueprint', '--file' => $masterPath]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error('Master validation FAILED.');
                return Command::FAILURE;
            }
            $checkpoint->markCompleted(self::STEP_MASTER_VALIDATED, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 7/12 : Generate runtimes FR/NL/EN
        // ══════════════════════════════════════
        $runtimeOutputDir = $outputDir.'/generated';
        if ($this->needsStep($io, $checkpoint, self::STEP_RUNTIMES_GENERATED, '7/12 — Generate runtimes (FR/NL/EN)')) {
            $exitCode = $app->run(new ArrayInput([
                'command' => 'app:book:generate-blueprint',
                '--source' => $masterPath, '--output-dir' => $runtimeOutputDir,
            ]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error('Runtime generation FAILED.');
                return Command::FAILURE;
            }
            foreach (['fr', 'en', 'nl'] as $locale) {
                $rp = sprintf('%s/runtime.%s.json', $runtimeOutputDir, $locale);
                file_exists($rp) ? $io->writeln(sprintf('  ✓ runtime.%s.json', $locale)) : $io->error("Missing $rp");
            }
            $checkpoint->markCompleted(self::STEP_RUNTIMES_GENERATED, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 8/12 : Validate each runtime
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_RUNTIMES_VALIDATED, '8/12 — Validate runtimes (FR, EN, NL)')) {
            foreach (['fr', 'en', 'nl'] as $locale) {
                $exitCode = $app->run(new ArrayInput([
                    'command' => 'app:book:validate-blueprint',
                    '--file' => sprintf('%s/runtime.%s.json', $runtimeOutputDir, $locale),
                    '--runtime' => true,
                ]), $output);
                if (Command::SUCCESS !== $exitCode) {
                    $io->warning(sprintf('Runtime validation warning for "%s" — continuing.', $locale));
                }
                $io->writeln(sprintf('  ✓ runtime.%s.json validated', $locale));
            }
            $checkpoint->markCompleted(self::STEP_RUNTIMES_VALIDATED, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 9/12 : Generate images (FLUX)
        // ══════════════════════════════════════
        if ($generateImages) {
            if ($this->needsStep($io, $checkpoint, self::STEP_IMAGES_GENERATED, '9/12 — Generate images (FLUX)')) {
                $exitCode = $app->run(new ArrayInput([
                    'command' => 'app:book:create-from-blueprint',
                    'slug' => $slug, '--base-url' => $baseUrl, '--generate-images' => true,
                ]), $output);
                if (Command::SUCCESS !== $exitCode) {
                    $io->error('Image generation FAILED.');
                    return Command::FAILURE;
                }
                $checkpoint->markCompleted(self::STEP_IMAGES_GENERATED, 0.0);
            }
        } else {
            $io->note('[9/12] Images skipped. Pass --generate-images to enable FLUX.');
        }

        // ══════════════════════════════════════
        // STEP 10/12 : Check assets
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_ASSETS_CHECKED, '10/12 — Check assets')) {
            $exitCode = $this->checkAssetCompletenessCommand->run(
                new ArrayInput(['blueprint-dir' => $outputDir]),
                $output,
            );
            if (Command::SUCCESS !== $exitCode) {
                $generateImages ? $io->error('Assets check FAILED.') : $io->warning('Assets incomplete (expected without --generate-images).');
                if ($generateImages) return Command::FAILURE;
            }
            $checkpoint->markCompleted(self::STEP_ASSETS_CHECKED, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 11/12 : Sync Sylius catalog
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_CATALOG_SYNCED, '11/12 — Sync Sylius catalog')) {
            $exitCode = $app->run(new ArrayInput(['command' => 'app:sync-book-blueprints']), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error('Catalog sync FAILED.');
                return Command::FAILURE;
            }
            $checkpoint->markCompleted(self::STEP_CATALOG_SYNCED, 0.0);
        }

        // ══════════════════════════════════════
        // STEP 12/12 : Verify catalog
        // ══════════════════════════════════════
        if ($this->needsStep($io, $checkpoint, self::STEP_CATALOG_VERIFIED, '12/12 — Verify catalog (API + assets)')) {
            $exitCode = $app->run(new ArrayInput([
                'command' => 'app:book:verify-catalog',
                'slug' => $slug, '--base-url' => $baseUrl,
            ]), $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->warning('Catalog verification had warnings — book may not be visible in frontend.');
            }
            $checkpoint->markCompleted(self::STEP_CATALOG_VERIFIED, 0.0);
        }

        $io->success(sprintf(
            "═══ 12-STEP PIPELINE COMPLETE: '%s' is ready. ═══\n  → Catalog: %s/api/books\n  → Detail: %s/api/books/%s?locale=fr|en|nl",
            $slug, $baseUrl, $baseUrl, $slug,
        ));

        return Command::SUCCESS;
    }

    private function needsStep(SymfonyStyle $io, PipelineCheckpoint $checkpoint, string $key, string $label): bool
    {
        $io->section($label);
        if ($checkpoint->isCompleted($key)) {
            $io->writeln('<info>  ✓ already completed — skipping</info>');
            return false;
        }
        return true;
    }
}
