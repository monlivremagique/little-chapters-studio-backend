<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Support\OperationalEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SupportOperationsController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(default::SUPPORT_OPERATIONS_TOKEN)%')]
        private readonly ?string $supportToken,
    ) {
    }

    #[Route(
        '/api/custom/support/orders/{orderNumber}/events',
        name: 'app_custom_support_order_events_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readOrderEvents(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return new JsonResponse(['message' => 'A valid support token is required.'], Response::HTTP_FORBIDDEN);
        }

        $events = $this->entityManager->getRepository(OperationalEvent::class)->findBy(
            ['orderNumber' => $orderNumber],
            ['id' => 'ASC'],
        );

        return new JsonResponse(array_map(
            static fn (OperationalEvent $event): array => [
                'type' => $event->getType(),
                'level' => $event->getLevel(),
                'sessionId' => $event->getSessionId(),
                'orderNumber' => $event->getOrderNumber(),
                'context' => (object) $event->getContext(),
                'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
            ],
            $events,
        ));
    }

    private function isSupportTokenValid(Request $request): bool
    {
        $expectedToken = trim((string) $this->supportToken);

        return '' !== $expectedToken
            && hash_equals($expectedToken, trim((string) $request->headers->get('X-Support-Token', '')));
    }
}
