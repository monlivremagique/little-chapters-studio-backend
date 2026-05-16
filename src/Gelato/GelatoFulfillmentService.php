<?php

declare(strict_types=1);

namespace App\Gelato;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Support\CriticalAlertDispatcher;
use App\Support\OperationalEventRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GelatoFulfillmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly GelatoClientInterface $gelatoClient,
        private readonly GelatoShippingAddressResolver $shippingAddressResolver,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        private readonly CriticalAlertDispatcher $criticalAlertDispatcher,
        #[Autowire('%env(default::GELATO_PRODUCT_UID)%')]
        private readonly ?string $productUid,
        #[Autowire('%env(default::GELATO_SHIPMENT_METHOD_UID)%')]
        private readonly ?string $shipmentMethodUid,
        #[Autowire('%env(default::GELATO_PUBLIC_BASE_URL)%')]
        private readonly ?string $gelatoPublicBaseUrl,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function submit(PersonalizationSession $session, PdfArtifact $pdfArtifact, ?string $publicBaseUrl = null): FulfillmentOrder
    {
        /** @var FulfillmentOrder|null $existing */
        $existing = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session]);

        if ($existing instanceof FulfillmentOrder) {
            return $existing;
        }

        if (null === $session->getSyliusOrderNumber()) {
            throw new \RuntimeException('Cannot submit fulfillment without a Sylius order number.');
        }

        if ('passed' !== $pdfArtifact->getPreflightStatus()) {
            throw new \RuntimeException(sprintf('Cannot submit Gelato order: PDF preflight status is "%s".', $pdfArtifact->getPreflightStatus()));
        }

        $payload = $this->buildPayload($session, $pdfArtifact, $publicBaseUrl);
        $fulfillmentOrder = new FulfillmentOrder($session, $pdfArtifact, $session->getSyliusOrderNumber(), $payload);
        $this->entityManager->persist($fulfillmentOrder);
        $this->operationalEventRecorder->record('gelato.submission_started', 'info', $session->getId(), $session->getSyliusOrderNumber());

        try {
            $response = $this->gelatoClient->createOrder($payload);
            $providerOrderId = (string) ($response['id'] ?? $response['orderId'] ?? $response['orderReferenceId'] ?? $session->getSyliusOrderNumber());
            $fulfillmentOrder->markSubmitted($providerOrderId, $response);
            $session->markSubmittedToGelato();
            $this->operationalEventRecorder->record('gelato.submitted', 'info', $session->getId(), $session->getSyliusOrderNumber(), [
                'provider_order_id' => $providerOrderId,
                'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
            ]);
        } catch (\Throwable $exception) {
            $fulfillmentOrder->markFailed($exception->getMessage());
            $this->operationalEventRecorder->record('gelato.submission_failed', 'error', $session->getId(), $session->getSyliusOrderNumber(), [
                'error' => $exception->getMessage(),
                'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
                'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
            ]);
            $this->criticalAlertDispatcher->dispatch('gelato.submission_failed', [
                'session_id' => $session->getId(),
                'order_number' => $session->getSyliusOrderNumber(),
                'payment_id' => null,
                'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
                'message' => $exception->getMessage(),
                'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
            ]);
        }

        return $fulfillmentOrder;
    }

    /**
     * Retry-safe submission: throws on Gelato API failure so Symfony Messenger retries the message.
     * Resets a previously-failed FulfillmentOrder before retrying. Skips already-submitted orders.
     */
    public function submitOrRetry(PersonalizationSession $session, PdfArtifact $pdfArtifact): FulfillmentOrder
    {
        $ownsTransaction = !$this->connection->isTransactionActive();

        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->entityManager->lock($session, LockMode::PESSIMISTIC_WRITE);

            if (null === $session->getSyliusOrderNumber()) {
                throw new \RuntimeException('Cannot submit fulfillment without a Sylius order number.');
            }

            if ('passed' !== $pdfArtifact->getPreflightStatus()) {
                throw new \RuntimeException(sprintf('Cannot submit Gelato order: PDF preflight status is "%s".', $pdfArtifact->getPreflightStatus()));
            }

            /** @var FulfillmentOrder|null $existing */
            $existing = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session]);

            if ($existing instanceof FulfillmentOrder && in_array($existing->getStatus(), ['submitted', 'in_production', 'production', 'printed', 'shipped', 'delivered'], true)) {
                return $existing;
            }

            $payload = $this->buildPayload($session, $pdfArtifact);

            if ($existing instanceof FulfillmentOrder) {
                $existing->resetForRetry();
                $fulfillmentOrder = $existing;
            } else {
                $fulfillmentOrder = new FulfillmentOrder($session, $pdfArtifact, $session->getSyliusOrderNumber(), $payload);
                $this->entityManager->persist($fulfillmentOrder);
                $this->entityManager->flush();
            }

            $this->operationalEventRecorder->record('gelato.submission_started', 'info', $session->getId(), $session->getSyliusOrderNumber());

            try {
                // Throws on failure — caller (MessageHandler) lets the exception propagate so Messenger retries.
                $response = $this->gelatoClient->createOrder($payload);
            } catch (\Throwable $exception) {
                $fulfillmentOrder->markFailed($exception->getMessage());
                $this->operationalEventRecorder->record('gelato.submission_failed', 'error', $session->getId(), $session->getSyliusOrderNumber(), [
                    'error' => $exception->getMessage(),
                    'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
                    'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
                ]);
                $this->criticalAlertDispatcher->dispatch('gelato.submission_failed', [
                    'session_id' => $session->getId(),
                    'order_number' => $session->getSyliusOrderNumber(),
                    'payment_id' => null,
                    'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
                    'message' => $exception->getMessage(),
                    'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
                ]);

                throw $exception;
            }

            $providerOrderId = (string) ($response['id'] ?? $response['orderId'] ?? $response['orderReferenceId'] ?? $session->getSyliusOrderNumber());
            $fulfillmentOrder->markSubmitted($providerOrderId, $response);
            $session->markSubmittedToGelato();
            $this->operationalEventRecorder->record('gelato.submitted', 'info', $session->getId(), $session->getSyliusOrderNumber(), [
                'provider_order_id' => $providerOrderId,
                'pdf_artifact_id' => (string) ($pdfArtifact->getId() ?? ''),
            ]);

            if ($ownsTransaction) {
                $this->entityManager->flush();
                $this->connection->commit();
            }

            return $fulfillmentOrder;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    public function applyWebhook(array $payload): ?FulfillmentOrder
    {
        $orderReferenceId = trim((string) ($payload['orderReferenceId'] ?? $payload['order_reference_id'] ?? ''));
        $providerOrderId = trim((string) ($payload['id'] ?? $payload['orderId'] ?? ''));
        $itemReferenceId = trim((string) ($payload['itemReferenceId'] ?? $payload['item_reference_id'] ?? ''));

        /** @var FulfillmentOrder|null $fulfillmentOrder */
        $fulfillmentOrder = null;

        if ('' !== $itemReferenceId) {
            $fulfillmentOrder = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy([
                'session' => $itemReferenceId,
            ]);
        }

        if (!$fulfillmentOrder instanceof FulfillmentOrder && '' !== $providerOrderId) {
            $fulfillmentOrder = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy([
                'providerOrderId' => $providerOrderId,
            ]);
        }

        if (!$fulfillmentOrder instanceof FulfillmentOrder && '' !== $orderReferenceId) {
            $fulfillmentOrder = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy([
                'orderNumber' => $orderReferenceId,
            ]);
        }

        if (!$fulfillmentOrder instanceof FulfillmentOrder) {
            return null;
        }

        $status = trim((string) ($payload['fulfillmentStatus'] ?? $payload['status'] ?? 'updated'));
        $fulfillmentOrder->applyWebhookStatus($status, $payload);
        $session = $fulfillmentOrder->getSession();

        match ($status) {
            'in_production', 'production', 'printed' => $session->markInProduction(),
            'shipped' => $session->markShipped(),
            'delivered' => $session->markDelivered(),
            'cancelled', 'canceled' => $session->markCancelled(),
            'failed', 'error' => $session->markFailed(),
            default => null,
        };
        $this->syncSyliusShipmentState($session, $status);

        $this->operationalEventRecorder->record('gelato.webhook', 'info', $session->getId(), $fulfillmentOrder->getOrderNumber(), [
            'status' => $status,
            'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
            'tracking_number' => $fulfillmentOrder->getTrackingNumber(),
        ]);

        return $fulfillmentOrder;
    }

    private function syncSyliusShipmentState(PersonalizationSession $session, string $providerStatus): void
    {
        $orderId = $session->getSyliusOrderId();
        if (null === $orderId) {
            return;
        }

        $shippingState = match ($providerStatus) {
            'shipped' => 'shipped',
            'delivered' => 'shipped',
            'cancelled', 'canceled' => 'cancelled',
            'failed', 'error' => 'cancelled',
            default => 'ready',
        };

        $this->connection->executeStatement(
            'UPDATE sylius_order SET shipping_state = :state WHERE id = :orderId',
            ['state' => $shippingState, 'orderId' => $orderId],
        );
        $this->connection->executeStatement(
            'UPDATE sylius_shipment SET state = :state WHERE order_id = :orderId',
            ['state' => $shippingState, 'orderId' => $orderId],
        );
    }

    /** @return array<string, mixed> */
    private function buildPayload(PersonalizationSession $session, PdfArtifact $pdfArtifact, ?string $publicBaseUrl = null): array
    {
        $productUid = trim((string) $this->productUid);

        if ('' === $productUid) {
            throw new \RuntimeException(sprintf(
                'GELATO_PRODUCT_UID is not configured. Set the GELATO_PRODUCT_UID environment variable to the Gelato product UID for session %s.',
                $session->getId(),
            ));
        }

        $resolvedPublicBaseUrl = trim((string) ($publicBaseUrl ?? $this->gelatoPublicBaseUrl ?? $this->defaultUri));

        if ('' === $resolvedPublicBaseUrl) {
            throw new \RuntimeException('A public base URL is required to expose the PDF artifact to Gelato.');
        }

        $snapshotPayload = $pdfArtifact->getPreviewVersion()->getSnapshotPayload();
        $pageCount = count(is_array($snapshotPayload['pages'] ?? null) ? $snapshotPayload['pages'] : []);

        if ($pageCount <= 0) {
            throw new \RuntimeException('The approved preview version does not expose any page for Gelato fulfillment.');
        }

        return [
            'orderType' => 'order',
            'orderReferenceId' => (string) $session->getSyliusOrderNumber(),
            'customerReferenceId' => $session->getOwnerCustomer()?->getEmail() ?? $session->getGuestOwnerToken(),
            'currency' => 'EUR',
            'items' => [[
                'itemReferenceId' => $session->getId(),
                'productUid' => $productUid,
                'files' => [[
                    'type' => 'default',
                    'url' => rtrim($resolvedPublicBaseUrl, '/').$pdfArtifact->getPublicPath(),
                ]],
                'pageCount' => $pageCount,
                'quantity' => 1,
            ]],
            'shipmentMethodUid' => trim((string) $this->shipmentMethodUid) !== '' ? trim((string) $this->shipmentMethodUid) : 'standard',
            'shippingAddress' => $this->shippingAddressResolver->resolveForSession($session),
            'metadata' => [
                'personalization_session_id' => $session->getId(),
                'pdf_hash' => $pdfArtifact->getFileHash(),
            ],
        ];
    }
}
