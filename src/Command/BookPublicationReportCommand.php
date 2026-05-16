<?php

declare(strict_types=1);

namespace App\Command;

use App\BookBlueprint\BlueprintValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:book:publication-report', description: 'Reports publication readiness for a book blueprint slug.')]
final class BookPublicationReportCommand extends Command
{
    private const LOCALES = ['fr', 'en', 'nl'];

    public function __construct(
        private readonly BlueprintValidator $blueprintValidator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Book blueprint slug.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        $baseDir = sprintf('%s/resources/book-blueprints/%s', $this->projectDir, $slug);
        $errors = [];
        $rows = [];

        $master = $this->readJson($baseDir.'/master.json');
        if (!is_array($master)) {
            $errors[] = 'master.json missing or invalid.';
        } else {
            $validation = $this->blueprintValidator->validateMasterBlueprint($master);
            if (!$validation->isValid()) {
                $errors = [...$errors, ...$validation->errors];
            }
            $rows[] = ['master', $validation->isValid() ? 'OK' : 'FAIL'];
        }

        foreach (self::LOCALES as $locale) {
            $runtime = $this->readJson(sprintf('%s/generated/runtime.%s.json', $baseDir, $locale));
            if (!is_array($runtime)) {
                $errors[] = sprintf('runtime.%s.json missing or invalid.', $locale);
                $rows[] = [sprintf('runtime.%s', $locale), 'FAIL'];
                continue;
            }

            $validation = $this->blueprintValidator->validateRuntimeBlueprint($runtime);
            if (($runtime['metadata']['locale'] ?? null) !== $locale) {
                $errors[] = sprintf('runtime.%s metadata.locale mismatch.', $locale);
            }
            if (!$validation->isValid()) {
                $errors = [...$errors, ...array_map(static fn (string $error): string => sprintf('%s: %s', $locale, $error), $validation->errors)];
            }

            $assetFailures = 0;
            foreach (is_array($runtime['pages'] ?? null) ? $runtime['pages'] : [] as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $path = trim((string) ($page['default_image_path'] ?? ''));
                if ('' === $path || !is_file($this->projectDir.'/public'.$path)) {
                    ++$assetFailures;
                }
            }

            if ($assetFailures > 0) {
                $errors[] = sprintf('runtime.%s has %d missing public asset(s).', $locale, $assetFailures);
            }

            $rows[] = [sprintf('runtime.%s', $locale), $validation->isValid() && 0 === $assetFailures ? 'OK' : 'FAIL'];
        }

        $io->table(['Check', 'Status'], $rows);
        if ([] !== $errors) {
            $io->error($errors);

            return Command::FAILURE;
        }

        $io->success(sprintf('Book "%s" is publication-ready locally.', $slug));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
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
