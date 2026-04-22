<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PersonalizationSession;
use App\Personalization\PersonalizationOrderLinker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomOrderPersonalizationController
{
    public function __construct(
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    #[Route(
        '/api/custom/orders/{orderNumber}/sessions',
        name: 'app_custom_order_personalization_sessions_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readLinkedSessions(string $orderNumber): JsonResponse
    {
        $sessions = $this->personalizationOrderLinker->synchronizeSessionsWithOrderNumber($orderNumber);

        return new JsonResponse(array_map(
            fn (PersonalizationSession $session): array => $this->normalizeSession($session),
            $sessions,
        ));
    }

    #[Route(
        '/api/custom/orders/{orderNumber}/session',
        name: 'app_custom_order_personalization_session_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readLinkedSession(string $orderNumber): JsonResponse
    {
        $sessions = $this->personalizationOrderLinker->synchronizeSessionsWithOrderNumber($orderNumber);
        $session = $sessions[0] ?? null;

        if (null === $session) {
            return new JsonResponse(['message' => 'Linked personalization session not found for this order.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizeSession($session));
    }

    /** @return array<string, mixed> */
    private function normalizeSession(PersonalizationSession $session): array
    {
        $latestPhoto = $session->getLatestPhoto();

        return [
            'id' => $session->getId(),
            'bookId' => $session->getBookId(),
            'step' => $session->getStep(),
            'childName' => $session->getChildName() ?? '',
            'childPhoto' => null !== $latestPhoto ? $this->absoluteUrl($latestPhoto->getPublicPath()) : null,
            'dedication' => $session->getDedication(),
            'extraFields' => (object) $session->getExtraFields(),
            'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(DATE_ATOM),
            'approvedAt' => $session->getApprovedAt()?->format(DATE_ATOM),
            'cartTokenValue' => $session->getCartTokenValue(),
            'cartItemId' => $session->getCartItemId(),
            'syliusOrderId' => $session->getSyliusOrderId(),
            'syliusOrderNumber' => $session->getSyliusOrderNumber(),
            'status' => $session->getStatus()->value,
        ];
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->defaultUri, '/') . $path;
    }
}
