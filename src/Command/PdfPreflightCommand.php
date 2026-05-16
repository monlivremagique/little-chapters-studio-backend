<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Personalization\PdfArtifact;
use App\Pdf\PdfPreflightValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:pdf:preflight', description: 'Runs and persists preflight checks for a PDF artifact.')]
final class PdfPreflightCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PdfPreflightValidator $pdfPreflightValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('artifactId', InputArgument::REQUIRED, 'PdfArtifact id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $artifactId = (int) $input->getArgument('artifactId');
        /** @var PdfArtifact|null $artifact */
        $artifact = $this->entityManager->getRepository(PdfArtifact::class)->find($artifactId);

        if (!$artifact instanceof PdfArtifact) {
            $io->error(sprintf('PDF artifact "%d" was not found.', $artifactId));

            return Command::FAILURE;
        }

        $report = $this->pdfPreflightValidator->validate($artifact);
        if ($report['passed']) {
            $artifact->markPreflightPassed($report);
            $this->entityManager->flush();
            $io->success('PDF preflight passed.');

            return Command::SUCCESS;
        }

        $artifact->markPreflightFailed($report);
        $this->entityManager->flush();
        $io->error($report['errors']);

        return Command::FAILURE;
    }
}
