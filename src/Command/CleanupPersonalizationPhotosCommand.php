<?php

declare(strict_types=1);

namespace App\Command;

use App\Personalization\PersonalizationPhotoManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-personalization-photos',
    description: 'Hard-purge expired personalization photos beyond the configured retention period, and soft-deleted photos beyond grace period.',
)]
final class CleanupPersonalizationPhotosCommand extends Command
{
    public function __construct(
        private readonly PersonalizationPhotoManager $personalizationPhotoManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'deleted-grace-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of full days to keep soft-deleted photos before hard purge.',
                '7',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview which photos would be purged without actually deleting them.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedGraceDays = max(0, (int) $input->getOption('deleted-grace-days'));
        $maxRetentionDays = $this->personalizationPhotoManager->getMaxRetentionDays();
        $isDryRun = (bool) $input->getOption('dry-run');

        $threshold = min($deletedGraceDays, $maxRetentionDays);
        $deadline = (new \DateTimeImmutable())->modify(sprintf('-%d days', $threshold));

        $result = $this->personalizationPhotoManager->purgeExpiredPhotos($deadline);

        if ($isDryRun) {
            $io->note('DRY RUN — no photos were actually deleted.');

            $io->writeln(sprintf(
                '  Would purge %d photo(s) older than %d day(s) (retention: %d days, grace: %d days).',
                $result['photoCount'],
                $threshold,
                $maxRetentionDays,
                $deletedGraceDays,
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Hard-purged %d personalization photo(s) older than %d day(s) (retention: %d days, grace: %d days).',
            $result['purgedCount'],
            $threshold,
            $maxRetentionDays,
            $deletedGraceDays,
        ));

        return Command::SUCCESS;
    }
}
