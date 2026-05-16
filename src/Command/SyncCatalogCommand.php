<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:sync-catalog',
    description: 'Bootstraps and synchronizes the full multilingual catalog from a single entrypoint.',
)]
final class SyncCatalogCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        if ($this->isCatalogEmpty()) {
            $output->writeln('<info>Catalog is empty, loading fixtures...</info>');

            $statusCode = $this->runCommand($application, [
                'command' => 'sylius:fixtures:load',
                'suite' => 'little_chapters_phase2',
                '--no-interaction' => true,
            ], $output);

            if (Command::SUCCESS !== $statusCode) {
                return $statusCode;
            }

            $output->writeln('<info>Applying post-seed SQL...</info>');
            $this->applyPostSeedSql();
        }

        foreach ([
            'app:sync-book-blueprints',
            'app:backfill-catalog-locales',
            'app:diagnose-catalog-locales',
        ] as $commandName) {
            $output->writeln(sprintf('<info>Running %s...</info>', $commandName));

            $statusCode = $this->runCommand($application, [
                'command' => $commandName,
                '--no-interaction' => true,
            ], $output);

            if (Command::SUCCESS !== $statusCode) {
                return $statusCode;
            }
        }

        $output->writeln('<info>Catalog sync completed.</info>');

        return Command::SUCCESS;
    }

    private function isCatalogEmpty(): bool
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sylius_channel') === 0;
    }

    private function applyPostSeedSql(): void
    {
        $sqlFilePath = $this->projectDir . '/scripts/phase2-post-seed.sql';
        $sql = (string) file_get_contents($sqlFilePath);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    private function runCommand(Application $application, array $arguments, OutputInterface $output): int
    {
        return $application->run(new ArrayInput($arguments), $output);
    }
}
