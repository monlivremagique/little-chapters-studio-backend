<?php

declare(strict_types=1);

namespace App\Command;

use App\Personalization\PersonalizationPreviewGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:personalization:process-generation-jobs')]
final class ProcessPersonalizationGenerationJobsCommand extends Command
{
    public function __construct(
        private readonly PersonalizationPreviewGenerator $personalizationPreviewGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs processed per pass.', '10')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep polling until max-runtime is reached.')
            ->addOption('sleep-seconds', null, InputOption::VALUE_REQUIRED, 'Idle sleep between loop iterations.', '2')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds when --loop is enabled.', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $loop = (bool) $input->getOption('loop');
        $sleepSeconds = max(1, (int) $input->getOption('sleep-seconds'));
        $maxRuntime = max(1, (int) $input->getOption('max-runtime'));
        $startedAt = time();
        $totalProcessed = 0;

        do {
            $processed = $this->personalizationPreviewGenerator->processPendingJobs($limit);
            $totalProcessed += $processed;

            if (!$loop) {
                $io->success(sprintf('Processed %d personalization generation job(s).', $processed));

                return Command::SUCCESS;
            }

            if ($processed === 0) {
                sleep($sleepSeconds);
            }
        } while ((time() - $startedAt) < $maxRuntime);

        $io->success(sprintf('Processed %d personalization generation job(s) in loop mode.', $totalProcessed));

        return Command::SUCCESS;
    }
}
