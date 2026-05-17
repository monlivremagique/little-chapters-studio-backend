<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:book:generate-hero-reference',
    description: 'Generates page_1 and writes hero-reference.png from a Master Blueprint V2. Discrete re-runnable hero sheet step.',
)]
final class GenerateHeroReferenceCommand extends Command
{
    public function __construct(
        private readonly GeneratePagesCommand $generatePagesCommand,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to the master blueprint JSON file.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory for hero-reference.png output (defaults to <blueprintDir>/generated-pages).')
            ->addOption('photo', null, InputOption::VALUE_REQUIRED, 'Optional child photo path passed to page generation.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate even if hero-reference.png already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Build prompt without calling Replicate.')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Blueprint slug (used to resolve default paths when --source is omitted).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourcePath = trim((string) $input->getOption('source'));
        $slug = trim((string) $input->getOption('slug'));

        if ('' === $sourcePath) {
            if ('' === $slug) {
                $io->error('Provide either --source <master.json> or --slug <book-slug>.');

                return Command::FAILURE;
            }

            $sourcePath = sprintf('%s/resources/book-blueprints/%s/master.json', $this->projectDir, $slug);
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $io->error(sprintf('Master blueprint not found or not readable: %s', $sourcePath));

            return Command::FAILURE;
        }

        $outputDir = trim((string) $input->getOption('output-dir'));
        if ('' === $outputDir) {
            $outputDir = dirname($sourcePath).'/generated-pages';
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $photoPath = trim((string) $input->getOption('photo'));

        $heroReferencePath = $outputDir.'/hero-reference.png';

        if (!$force && !$dryRun && is_file($heroReferencePath)) {
            $io->note(sprintf('hero-reference.png already exists at "%s". Use --force to regenerate.', $heroReferencePath));
            $io->success('Hero reference is already present. Skipping generation.');

            return Command::SUCCESS;
        }

        $io->title('Hero Reference Generation');
        $io->writeln(sprintf('Source: %s', $sourcePath));
        $io->writeln(sprintf('Output dir: %s', $outputDir));
        $io->writeln(sprintf('Dry-run: %s', $dryRun ? 'yes' : 'no'));
        $io->writeln(sprintf('Force: %s', $force ? 'yes' : 'no'));

        $heroRefPath = sprintf('%s/hero-reference.png', $outputDir);
        $pageArguments = [
            '--source' => $sourcePath,
            '--output-dir' => $outputDir,
            '--page' => 'page_1',
        ];
        if (is_file($heroRefPath) && is_readable($heroRefPath)) {
            $pageArguments['--hero-reference'] = $heroRefPath;
        }

        if ('' !== $photoPath) {
            $pageArguments['--photo'] = $photoPath;
        }

        if ($force) {
            $pageArguments['--force'] = true;
        }

        if ($dryRun) {
            $pageArguments['--dry-run'] = true;
        }

        $stepOutput = new BufferedOutput();
        $stepInput = new ArrayInput($pageArguments);
        $stepInput->setInteractive(false);
        $exitCode = $this->generatePagesCommand->run($stepInput, $stepOutput);
        $captured = trim($stepOutput->fetch());

        if ('' !== $captured) {
            $output->writeln($captured);
        }

        if (Command::SUCCESS !== $exitCode) {
            $io->error('page_1 generation failed. See output above.');

            return Command::FAILURE;
        }

        if (!$dryRun) {
            if (!is_file($heroReferencePath) || !is_readable($heroReferencePath)) {
                $io->error(sprintf('hero-reference.png was not created at "%s". Check GeneratePagesCommand output.', $heroReferencePath));

                return Command::FAILURE;
            }

            $io->success(sprintf('Hero reference written to: %s', $heroReferencePath));
        } else {
            $io->success('Dry-run complete. No Replicate calls made.');
        }

        return Command::SUCCESS;
    }
}
