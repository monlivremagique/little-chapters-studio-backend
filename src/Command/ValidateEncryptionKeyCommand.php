<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:validate-encryption-key',
    description: 'Verify that the payment encryption key is present and non-empty. Run after deploy.',
)]
final class ValidateEncryptionKeyCommand extends Command
{
    private const KEY_PATH = 'config/encryption/prod.key';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keyPath = $this->projectDir.'/'.self::KEY_PATH;

        if (!file_exists($keyPath)) {
            $io->error(sprintf('Encryption key file not found: %s', $keyPath));
            $io->note('Set SYLIUS_PAYMENT_ENCRYPTION_KEY_CONTENT on Railway so the key survives container restarts.');

            return Command::FAILURE;
        }

        $key = trim((string) file_get_contents($keyPath));

        if ('' === $key) {
            $io->error('Encryption key file exists but is empty.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Encryption key is present (%d chars). Payment encryption is operational.', strlen($key)));
        $io->note('If SYLIUS_PAYMENT_ENCRYPTION_KEY_CONTENT is not set on Railway, a new key will be generated on each container restart, breaking existing encrypted payment data.');

        return Command::SUCCESS;
    }
}
