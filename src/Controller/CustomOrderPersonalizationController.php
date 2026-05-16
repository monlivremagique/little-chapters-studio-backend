<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PersonalizationSession;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use App\RateLimiting\RateLimit;
use App\Service\SignedUrlService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RateLimit('read', 'session')]
final class CustomOrderPersonalizationController
{
    public function __construct(
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PersonalizationSessionOwnershipGuard $personalizationSessionOwnershipGuard,
        private readonly SignedUrlService $signedUrlService,
    ) {
    }

    #[Route(
        '/api/custom/orders/{orderNumber}/sessions',
        name: 'app_custom_order_personalization_sessions_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readLinkedSessions(string $orderNumber, Request $request): JsonResponse
    {
        $sessions = $this->personalizationOrderLinker->synchronizeSessionsWithOrderNumber($orderNumber);
        $this->personalizationSessionOwnershipGuard->assertCanAccessSessions($sessions, $request);

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
    public function readLinkedSession(string $orderNumber, Request $request): JsonResponse
    {
        $sessions = $this->personalizationOrderLinker->synchronizeSessionsWithOrderNumber($orderNumber);
        $this->personalizationSessionOwnershipGuard->assertCanAccessSessions($sessions, $request);
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
            'ownerToken' => $session->getGuestOwnerToken(),
            'step' => $session->getStep(),
            'childName' => $session->getChildName() ?? '',
            'childPhoto' => null !== $latestPhoto
                ? $this->signedUrlService->buildUrl(
                    sprintf('/api/personalization/photos/%s/signed', $latestPhoto->getId()),
                    $latestPhoto->getId(),
                    'photo_access',
                    900,
                )
                : null,
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
}
