<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Gelato\GelatoFulfillmentService;
use App\Message\TriggerFulfillmentMessage;
use App\Pdf\PersonalizationPdfRenderer;
use App\Personalization\PreviewVersionFactory;
use App\Support\CriticalAlertDispatcher;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TriggerFulfillmentMessageHandler
{
    private const TERMINAL_STATUSES = [
        PersonalizationSessionStatus::SubmittedToGelato,
        PersonalizationSessionStatus::InProduction,
        PersonalizationSessionStatus::Shipped,
        PersonalizationSessionStatus::Delivered,
        PersonalizationSessionStatus::Failed,
        PersonalizationSessionStatus::Cancelled,
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PreviewVersionFactory $previewVersionFactory,
        private PersonalizationPdfRenderer $pdfRenderer,
        private GelatoFulfillmentService $gelatoFulfillmentService,
        private OperationalEventRecorder $operationalEventRecorder,
        private CriticalAlertDispatcher $criticalAlertDispatcher,
    ) {
    }

    public function __invoke(TriggerFulfillmentMessage $message): void
    {
        /** @var PersonalizationSession|null $session */
        $session = $this->entityManager->getRepository(PersonalizationSession::class)->find($message->sessionId);

        if (!$session instanceof PersonalizationSession) {
            return;
        }

        // Already reached a terminal state — nothing to do.
        if (\in_array($session->getStatus(), self::TERMINAL_STATUSES, true)) {
            return;
        }

        $version = $this->previewVersionFactory->findLatestApprovedVersion($session);

        if (null === $version) {
            // Permanent failure — no preview version exists; retrying won't help.
            $this->operationalEventRecorder->record(
                'fulfillment.exhausted_no_preview',
                'error',
                $session->getId(),
                $session->getSyliusOrderNumber(),
            );
            $session->markFailed();
            $this->entityManager->flush();
            $this->criticalAlertDispatcher->dispatch('fulfillment.failed_no_preview', [
                'session_id' => $session->getId(),
                'order_number' => $session->getSyliusOrderNumber(),
                'payment_id' => null,
                'provider_order_id' => null,
                'message' => 'TriggerFulfillmentMessage exhausted: no approved preview version found.',
            ]);

            return;
        }

        // Render PDF — idempotent (returns existing artifact if already rendered).
        // Throws on failure → Messenger will retry this message.
        $pdfArtifact = $this->pdfRenderer->render($version);

        // Submit to Gelato — idempotent (existing submitted order is returned as-is).
        // submitOrRetry() throws on transient Gelato failure → Messenger retries.
        $this->gelatoFulfillmentService->submitOrRetry($session, $pdfArtifact);

        $this->entityManager->flush();
    }
}
