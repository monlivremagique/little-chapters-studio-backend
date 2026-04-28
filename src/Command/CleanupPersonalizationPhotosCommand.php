<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Personalization\UploadedPhoto;
use App\Personalization\PersonalizationPhotoManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-personalization-photos',
    description: 'Purge soft-deleted personalization photos after the configured retention grace period.',
)]
final class CleanupPersonalizationPhotosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deletedGraceDays = max(0, (int) $input->getOption('deleted-grace-days'));
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $deletedGraceDays));

        /** @var list<UploadedPhoto> $deletedPhotos */
        $deletedPhotos = $this->entityManager->getRepository(UploadedPhoto::class)->createQueryBuilder('photo')
            ->andWhere('photo.deletedAt IS NOT NULL')
            ->andWhere('photo.deletedAt <= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('photo.deletedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $purgedCount = 0;

        foreach ($deletedPhotos as $photo) {
            $storedPath = $this->personalizationPhotoManager->resolveStoredPhotoPath($photo);

            if (null !== $storedPath && is_file($storedPath)) {
                @unlink($storedPath);
            }

            $this->entityManager->remove($photo);
            ++$purgedCount;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Purged %d soft-deleted personalization photo record(s) older than %d day(s).',
            $purgedCount,
            $deletedGraceDays,
        ));

        return Command::SUCCESS;
    }
}
