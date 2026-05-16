<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Support\OperationalEvent;
use App\RateLimiting\RateLimit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal webhook receiver for CriticalAlertDispatcher.
 * ALERT_WEBHOOK_URL should point to this endpoint.
 * Payload: {"type": "...", "context": {...}}
 */
final class AlertWebhookController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(default::SUPPORT_OPERATIONS_TOKEN)%')]
        private readonly ?string $supportToken,
    ) {
    }

    #[RateLimit('webhook', 'ip')]
    #[Route(
        '/api/custom/alerts/receive',
        name: 'app_custom_alerts_receive',
        methods: ['POST'],
    )]
    public function receive(Request $request): JsonResponse
    {
        // Validate with support token header or query param
        $token = $request->headers->get('X-Support-Token')
            ?? $request->query->get('token')
            ?? '';

        $expectedToken = trim((string) $this->supportToken);
        if ('' !== $expectedToken && $token !== $expectedToken) {
            return $this->error('Authentification requise.', Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('Payload JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $type = isset($payload['type']) ? 'alert.' . (string) $payload['type'] : 'alert.unknown';
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : $payload;

        $sessionId = isset($context['session_id']) ? (string) $context['session_id'] : null;
        $orderNumber = isset($context['order_number']) ? (string) $context['order_number'] : null;

        $event = new OperationalEvent($type, 'warning', $sessionId, $orderNumber, $context);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'received', 'event_type' => $type]);
    }
}
