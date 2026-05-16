<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:book:validate-blueprint',
    description: 'Validates a Book Blueprint V2 master file or a localized runtime blueprint file.',
)]
final class ValidateBlueprintCommand extends Command
{
    public function __construct(private readonly BlueprintValidator $blueprintValidator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Absolute or relative path to the blueprint JSON file.')
            ->addOption('runtime', null, InputOption::VALUE_NONE, 'Validate a localized runtime blueprint instead of a master V2 blueprint.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = trim((string) $input->getOption('file'));
        $runtime = (bool) $input->getOption('runtime');

        if ('' === $filePath) {
            $io->error('The --file option is required.');

            return Command::FAILURE;
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('Blueprint file "%s" is not readable.', $filePath));

            return Command::FAILURE;
        }

        $contents = (string) file_get_contents($filePath);

        try {
            $blueprint = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $io->error(sprintf('Invalid JSON: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if (!is_array($blueprint)) {
            $io->error('The blueprint root must be a JSON object.');

            return Command::FAILURE;
        }

        $report = $runtime ? $this->blueprintValidator->validateRuntimeBlueprint($blueprint) : $this->blueprintValidator->validateMasterBlueprint($blueprint);
        $modeLabel = $runtime ? 'Runtime Blueprint' : 'Master Blueprint V2';

        $io->section(sprintf('%s Validation Report', $modeLabel));
        $io->writeln(sprintf('File: %s', $filePath));
        $io->writeln(sprintf('Status: %s', $report->isValid() ? 'OK' : 'FAIL'));
        $io->writeln(sprintf('Pages: %d', $report->pageCount));
        $io->writeln(sprintf('Locales detected: %s', [] !== $report->locales ? implode(', ', $report->locales) : 'none'));
        $io->writeln(sprintf('Assets referenced: %s', [] !== $report->assets ? implode(', ', $report->assets) : 'none'));

        if ([] !== $report->errors) {
            $io->error('Blocking errors');
            $io->listing($report->errors);
        }

        if ([] !== $report->warnings) {
            $io->warning('Warnings');
            $io->listing($report->warnings);
        } else {
            $io->writeln('Warnings: none');
        }

        if ($report->isValid()) {
            $io->success(sprintf('%s is valid.', $modeLabel));

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }
}
