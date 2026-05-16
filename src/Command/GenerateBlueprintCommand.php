<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintProjector;
use App\BookBlueprint\BlueprintValidationResult;
use App\BookBlueprint\BlueprintValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:book:generate-blueprint',
    description: 'Projects a master Book Blueprint V2 into localized runtime blueprints.',
)]
final class GenerateBlueprintCommand extends Command
{
    /** @var list<string> */
    private const DEFAULT_LOCALES = ['fr', 'en', 'nl'];

    /** @var list<string> */
    private const ALLOWED_LOCALES = ['fr', 'en', 'nl'];

    public function __construct(
        private readonly BlueprintValidator $blueprintValidator,
        private readonly BlueprintProjector $blueprintProjector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Path to the master blueprint JSON file.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Target directory for generated runtime.{locale}.json files.')
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated locales to generate.', implode(',', self::DEFAULT_LOCALES))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and project in memory without writing files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourcePath = trim((string) $input->getOption('source'));
        $outputDir = trim((string) $input->getOption('output-dir'));
        $dryRun = (bool) $input->getOption('dry-run');
        $requestedLocales = $this->parseLocales((string) $input->getOption('locales'));

        if ('' === $sourcePath) {
            $io->error('The --source option is required.');

            return Command::FAILURE;
        }

        if ('' === $outputDir) {
            $io->error('The --output-dir option is required.');

            return Command::FAILURE;
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $io->error(sprintf('Source file "%s" is not readable.', $sourcePath));

            return Command::FAILURE;
        }

        if ([] === $requestedLocales) {
            $io->error('At least one supported locale is required. Allowed values: fr,en,nl.');

            return Command::FAILURE;
        }

        $masterBlueprint = $this->decodeJsonFile($sourcePath);
        if (!is_array($masterBlueprint)) {
            $io->error('The source blueprint root must be a JSON object.');

            return Command::FAILURE;
        }

        $masterValidation = $this->blueprintValidator->validateMasterBlueprint($masterBlueprint);
        if (!$masterValidation->isValid()) {
            $io->error('Master blueprint validation failed.');
            $io->listing($masterValidation->errors);

            return Command::FAILURE;
        }

        $generatedBlueprints = [];
        $runtimeReports = [];
        $errors = [];
        $warnings = [];

        foreach ($requestedLocales as $locale) {
            $runtimeBlueprint = $this->blueprintProjector->projectRuntimeBlueprint($masterBlueprint, $locale);
            $runtimeValidation = $this->blueprintValidator->validateRuntimeBlueprint($runtimeBlueprint);

            if (!$runtimeValidation->isValid()) {
                foreach ($runtimeValidation->errors as $error) {
                    $errors[] = sprintf('[%s] %s', $locale, $error);
                }
            }

            foreach ($runtimeValidation->warnings as $warning) {
                $warnings[] = sprintf('[%s] %s', $locale, $warning);
            }

            $generatedBlueprints[$locale] = $runtimeBlueprint;
            $runtimeReports[$locale] = $runtimeValidation;
        }

        $io->section('Blueprint Generation Report');
        $io->writeln(sprintf('Source: %s', $sourcePath));
        $io->writeln(sprintf('Output directory: %s', $outputDir));
        $io->writeln(sprintf('Dry run: %s', $dryRun ? 'yes' : 'no'));
        $io->writeln(sprintf('Locales generated: %s', implode(', ', array_keys($generatedBlueprints))));

        foreach ($runtimeReports as $locale => $report) {
            $io->writeln(sprintf(
                'Locale %s: pages=%d, assets=%s',
                $locale,
                $report->pageCount,
                [] !== $report->assets ? implode(', ', $report->assets) : 'none',
            ));
        }

        if ([] !== $warnings) {
            $io->warning('Warnings');
            $io->listing($warnings);
        } else {
            $io->writeln('Warnings: none');
        }

        if ([] !== $errors) {
            $io->error('Projected runtime validation failed.');
            $io->listing($errors);

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success('Blueprints projected successfully in dry-run mode.');

            return Command::SUCCESS;
        }

        if ((file_exists($outputDir) && !is_dir($outputDir)) || (!file_exists($outputDir) && !@mkdir($outputDir, 0775, true) && !is_dir($outputDir))) {
            $io->error(sprintf('The output directory "%s" could not be created.', $outputDir));

            return Command::FAILURE;
        }

        foreach ($generatedBlueprints as $locale => $runtimeBlueprint) {
            $targetPath = rtrim($outputDir, '/').sprintf('/runtime.%s.json', $locale);
            $encodedBlueprint = json_encode($runtimeBlueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (false === $encodedBlueprint) {
                $io->error(sprintf('Failed to encode runtime blueprint for locale %s.', $locale));

                return Command::FAILURE;
            }

            $this->writeAtomically($targetPath, $encodedBlueprint."\n");
        }

        $io->success('Blueprints generated successfully.');

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function parseLocales(string $rawLocales): array
    {
        $locales = array_values(array_unique(array_filter(array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            explode(',', $rawLocales),
        ))));

        return array_values(array_filter(
            $locales,
            static fn (string $locale): bool => in_array($locale, self::ALLOWED_LOCALES, true),
        ));
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function decodeJsonFile(string $path): array|null
    {
        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function writeAtomically(string $targetPath, string $contents): void
    {
        $temporaryPath = $targetPath.'.tmp';
        file_put_contents($temporaryPath, $contents);
        rename($temporaryPath, $targetPath);
    }
}
