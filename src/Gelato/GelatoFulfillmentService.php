<?php

declare(strict_types=1);

namespace App\Gelato;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GelatoFulfillmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GelatoClientInterface $gelatoClient,
        private readonly GelatoShippingAddressResolver $shippingAddressResolver,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        #[Autowire('%env(default::GELATO_PRODUCT_UID)%')]
        private readonly ?string $productUid,
        #[Autowire('%env(default::GELATO_SHIPMENT_METHOD_UID)%')]
        private readonly ?string $shipmentMethodUid,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function submit(PersonalizationSession $session, PdfArtifact $pdfArtifact): FulfillmentOrder
    {
        /** @var FulfillmentOrder|null $existing */
        $existing = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session]);

        if ($existing instanceof FulfillmentOrder) {
            return $existing;
        }

        if (null === $session->getSyliusOrderNumber()) {
            throw new \RuntimeException('Cannot submit fulfillment without a Sylius order number.');
        }

        $payload = $this->buildPayload($session, $pdfArtifact);
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
            ]);
        } catch (\Throwable $exception) {
            $fulfillmentOrder->markFailed($exception->getMessage());
            $this->operationalEventRecorder->record('gelato.submission_failed', 'error', $session->getId(), $session->getSyliusOrderNumber(), [
                'error' => $exception->getMessage(),
            ]);
        }

        return $fulfillmentOrder;
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

        $this->operationalEventRecorder->record('gelato.webhook', 'info', $session->getId(), $fulfillmentOrder->getOrderNumber(), [
            'status' => $status,
            'provider_order_id' => $fulfillmentOrder->getProviderOrderId(),
        ]);

        return $fulfillmentOrder;
    }

    /** @return array<string, mixed> */
    private function buildPayload(PersonalizationSession $session, PdfArtifact $pdfArtifact): array
    {
        $productUid = trim((string) $this->productUid);

        if ('' === $productUid) {
            $productUid = 'book_pf_210x210_hardcover_placeholder';
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
                    'url' => rtrim($this->defaultUri, '/').$pdfArtifact->getPublicPath(),
                ]],
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
