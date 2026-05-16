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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:book:publication-manifest', description: 'Generates or verifies a checksum manifest for book publication artifacts.')]
final class BookPublicationManifestCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Book blueprint slug.')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Verify existing manifest instead of writing it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = trim((string) $input->getArgument('slug'));
        $baseDir = sprintf('%s/resources/book-blueprints/%s', $this->projectDir, $slug);
        $manifestPath = $baseDir.'/publication-manifest.json';

        if (!is_dir($baseDir)) {
            $io->error(sprintf('Unknown book blueprint directory "%s".', $baseDir));

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('verify')) {
            $expected = $this->readManifest($manifestPath);
            $actual = $this->buildManifest($slug, $baseDir);
            if ($expected !== $actual) {
                $io->error('Publication manifest mismatch. Regenerate locally, commit artifacts, deploy, then verify again.');

                return Command::FAILURE;
            }

            $io->success(sprintf('Publication manifest verified for "%s".', $slug));

            return Command::SUCCESS;
        }

        file_put_contents($manifestPath, json_encode($this->buildManifest($slug, $baseDir), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $io->success(sprintf('Publication manifest written: %s', $manifestPath));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function buildManifest(string $slug, string $baseDir): array
    {
        $files = [];
        foreach (['master.json', 'generated/runtime.fr.json', 'generated/runtime.en.json', 'generated/runtime.nl.json'] as $relativePath) {
            $path = $baseDir.'/'.$relativePath;
            if (is_file($path)) {
                $files[$relativePath] = hash_file('sha256', $path);
            }
        }

        foreach (array_merge(glob($baseDir.'/generated-cover/*.png') ?: [], glob($baseDir.'/generated-pages/*.png') ?: []) as $path) {
            $relativePath = ltrim(str_replace($baseDir, '', $path), '/');
            $files[$relativePath] = hash_file('sha256', $path);
        }

        ksort($files);

        return [
            'schema' => 'book_publication_manifest_v1',
            'slug' => $slug,
            'files' => $files,
        ];
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Missing publication manifest "%s".', $path));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
