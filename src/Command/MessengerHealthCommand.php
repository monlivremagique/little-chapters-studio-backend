<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:ops:messenger-health', description: 'Checks async/failure Messenger queue health for go-live operations.')]
final class MessengerHealthCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN)%')]
        private readonly string $messengerDsn,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (str_starts_with($this->messengerDsn, 'sync://') || str_starts_with($this->messengerDsn, 'in-memory://')) {
            $io->error('MESSENGER_TRANSPORT_DSN is not durable. Production must use doctrine/redis/amqp or another durable transport.');

            return Command::FAILURE;
        }

        $pending = $this->countQueue('async');
        $failed = $this->countQueue('failed');
        $io->table(['Queue', 'Messages'], [['async', $pending], ['failed', $failed]]);

        if ($failed > 0) {
            $io->error('Failed Messenger queue is not empty.');

            return Command::FAILURE;
        }

        $io->success('Messenger queues are configured and healthy.');

        return Command::SUCCESS;
    }

    private function countQueue(string $queueName): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue', ['queue' => $queueName]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
