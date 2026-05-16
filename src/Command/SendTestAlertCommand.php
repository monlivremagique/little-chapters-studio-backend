<?php

declare(strict_types=1);

namespace App\Command;

use App\Support\CriticalAlertDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:support:send-test-alert',
    description: 'Send a test critical alert to verify ALERT_EMAIL_TO and ALERT_WEBHOOK_URL are configured.',
)]
final class SendTestAlertCommand extends Command
{
    public function __construct(
        private readonly CriticalAlertDispatcher $alertDispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->info('Dispatching test alert via CriticalAlertDispatcher…');

        $this->alertDispatcher->dispatch('test.manual_trigger', [
            'session_id' => 'test-session-000',
            'order_number' => 'TEST-ALERT-001',
            'payment_id' => null,
            'provider_order_id' => null,
            'message' => 'Test alert triggered manually via app:support:send-test-alert',
        ]);

        $io->success('Alert dispatched. Check ALERT_EMAIL_TO inbox and/or ALERT_WEBHOOK_URL logs.');
        $io->note('If no email/webhook arrived: verify ALERT_EMAIL_TO, ALERT_EMAIL_FROM and MAILER_DSN in Railway variables.');

        return Command::SUCCESS;
    }
}
