<?php

declare(strict_types=1);

namespace App\Production;

use App\Entity\Personalization\PersonalizationSession;
use App\Gelato\GelatoFulfillmentService;
use App\Pdf\PersonalizationPdfRenderer;
use App\Personalization\PreviewVersionFactory;
use App\Support\CriticalAlertDispatcher;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;

final class PostPaymentProductionOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PreviewVersionFactory $previewVersionFactory,
        private readonly PersonalizationPdfRenderer $pdfRenderer,
        private readonly GelatoFulfillmentService $gelatoFulfillmentService,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        private readonly CriticalAlertDispatcher $criticalAlertDispatcher,
    ) {
    }

    /** @param list<PersonalizationSession> $sessions */
    public function processPaidSessions(array $sessions): void
    {
        foreach ($sessions as $session) {
            $this->processPaidSession($session);
        }

        $this->entityManager->flush();
    }

    public function processPaidSession(PersonalizationSession $session): void
    {
        $version = $this->previewVersionFactory->findLatestApprovedVersion($session);

        if (null === $version) {
            $this->operationalEventRecorder->record('production.skipped_missing_preview_version', 'error', $session->getId(), $session->getSyliusOrderNumber());
            $session->markFailed();

            $this->criticalAlertDispatcher->dispatch('pdf.render_failed', [
                'session_id' => $session->getId(),
                'order_number' => $session->getSyliusOrderNumber(),
                'payment_id' => null,
                'provider_order_id' => null,
                'message' => 'Missing approved preview version before PDF rendering.',
            ]);

            return;
        }

        try {
            $pdfArtifact = $this->pdfRenderer->render($version);
        } catch (\Throwable $exception) {
            $session->markFailed();
            $this->operationalEventRecorder->record('pdf.render_failed', 'error', $session->getId(), $session->getSyliusOrderNumber(), [
                'message' => $exception->getMessage(),
            ]);
            $this->pdfRenderer->dispatchFailureAlert($session, $exception);

            return;
        }

        $this->gelatoFulfillmentService->submit($session, $pdfArtifact);
    }
}
