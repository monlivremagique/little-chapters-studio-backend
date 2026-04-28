<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fulfillment\FulfillmentWebhookEvent;
use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Gelato\GelatoFulfillmentService;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;

final class FulfillmentController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PersonalizationSessionOwnershipGuard $personalizationSessionOwnershipGuard,
        private readonly GelatoFulfillmentService $gelatoFulfillmentService,
        #[Autowire('%env(default::GELATO_WEBHOOK_SECRET)%')]
        private readonly ?string $gelatoWebhookSecret,
    ) {
    }

    #[Route(
        '/api/custom/orders/{orderNumber}/fulfillment',
        name: 'app_custom_order_fulfillment_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readFulfillment(string $orderNumber, Request $request): JsonResponse
    {
        $sessions = $this->personalizationOrderLinker->findSessionsByOrderNumber($orderNumber);

        try {
            $this->personalizationSessionOwnershipGuard->assertCanAccessSessions($sessions, $request);
        } catch (NotFoundHttpException) {
            return new JsonResponse(['message' => 'Fulfillment order not found.'], Response::HTTP_NOT_FOUND);
        }

        $items = [];

        foreach ($sessions as $session) {
            /** @var PdfArtifact|null $pdf */
            $pdf = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy(['session' => $session], ['id' => 'DESC']);
            /** @var FulfillmentOrder|null $fulfillment */
            $fulfillment = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session], ['id' => 'DESC']);

            $items[] = [
                'sessionId' => $session->getId(),
                'sessionStatus' => $session->getStatus()->value,
                'pdf' => null !== $pdf ? [
                    'id' => $pdf->getId(),
                    'status' => $pdf->getStatus(),
                    'url' => $pdf->getPublicPath(),
                    'hash' => $pdf->getFileHash(),
                    'fileSize' => $pdf->getFileSize(),
                ] : null,
                'fulfillment' => null !== $fulfillment ? $this->normalizeFulfillment($fulfillment) : null,
            ];
        }

        return new JsonResponse($items);
    }

    #[Route(
        '/api/custom/fulfillment/gelato/webhook',
        name: 'app_custom_gelato_webhook',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function handleGelatoWebhook(Request $request): JsonResponse
    {
        if (!$this->isGelatoWebhookSecretValid($request)) {
            return new JsonResponse(['message' => 'Invalid Gelato webhook secret.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Invalid Gelato webhook payload.'], Response::HTTP_BAD_REQUEST);
        }

        $eventKey = $this->resolveWebhookEventKey($payload);
        /** @var FulfillmentWebhookEvent|null $existingEvent */
        $existingEvent = $this->entityManager->getRepository(FulfillmentWebhookEvent::class)->findOneBy([
            'eventKey' => $eventKey,
        ]);

        if ($existingEvent instanceof FulfillmentWebhookEvent) {
            return new JsonResponse(['message' => 'Webhook already processed.']);
        }

        $this->entityManager->persist(new FulfillmentWebhookEvent($eventKey, 'gelato', $payload));

        try {
            $fulfillment = $this->gelatoFulfillmentService->applyWebhook($payload);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['message' => 'Webhook already processed.']);
        }

        if (!$fulfillment instanceof FulfillmentOrder) {
            return new JsonResponse(['message' => 'Fulfillment order not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizeFulfillment($fulfillment));
    }

    private function isGelatoWebhookSecretValid(Request $request): bool
    {
        $expectedSecret = trim((string) $this->gelatoWebhookSecret);

        if ('' === $expectedSecret) {
            return false;
        }

        foreach ([
            $request->headers->get('X-Gelato-Webhook-Secret'),
            $request->headers->get('X-Webhook-Secret'),
            $request->query->get('secret'),
        ] as $providedSecret) {
            $providedSecret = trim((string) $providedSecret);

            if ('' !== $providedSecret && hash_equals($expectedSecret, $providedSecret)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function resolveWebhookEventKey(array $payload): string
    {
        foreach (['eventId', 'event_id', 'webhookId', 'webhook_id', 'id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ('' !== $value) {
                return sprintf('gelato:%s', $value);
            }
        }

        return sprintf('gelato:%s', hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function normalizeFulfillment(FulfillmentOrder $fulfillment): array
    {
        return [
            'id' => $fulfillment->getId(),
            'provider' => $fulfillment->getProvider(),
            'status' => $fulfillment->getStatus(),
            'orderNumber' => $fulfillment->getOrderNumber(),
            'providerOrderId' => $fulfillment->getProviderOrderId(),
            'trackingUrl' => $fulfillment->getTrackingUrl(),
            'trackingNumber' => $fulfillment->getTrackingNumber(),
            'errorMessage' => $fulfillment->getErrorMessage(),
            'updatedAt' => $fulfillment->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
