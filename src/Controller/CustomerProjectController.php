<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer\Customer;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\User\ShopUser;
use App\FrontCatalog\FrontCatalogProvider;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use App\Trait\ApiErrorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerProjectController
{
    use ApiErrorTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly Security $security,
    ) {
    }

    #[Route(
        '/api/custom/projects',
        name: 'app_custom_projects_list',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function listProjects(Request $request): JsonResponse
    {
        if (!$this->hasOwnershipContext($request)) {
            return $this->error('Vous n\'avez pas les droits nécessaires pour accéder à cette ressource.', Response::HTTP_FORBIDDEN);
        }

        $sessions = $this->findAccessibleSessions($request);
        $booksById = [];

        foreach ($this->frontCatalogProvider->getBooks() as $book) {
            $booksById[(string) $book['id']] = $book;
        }

        return new JsonResponse(array_map(
            fn (PersonalizationSession $session): array => $this->normalizeProject($session, $booksById[(string) $session->getBookId()] ?? null),
            $sessions,
        ));
    }

    #[Route(
        '/api/custom/projects/{id}',
        name: 'app_custom_projects_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readProject(string $id, Request $request): JsonResponse
    {
        if (!$this->hasOwnershipContext($request)) {
            return $this->error('Projet introuvable.', Response::HTTP_NOT_FOUND);
        }

        foreach ($this->findAccessibleSessions($request) as $session) {
            if ($session->getId() === $id) {
                $booksById = [];

                foreach ($this->frontCatalogProvider->getBooks() as $book) {
                    $booksById[(string) $book['id']] = $book;
                }

                return new JsonResponse($this->normalizeProject($session, $booksById[(string) $session->getBookId()] ?? null));
            }
        }

        return $this->error('Projet introuvable.', Response::HTTP_NOT_FOUND);
    }

    /** @return list<PersonalizationSession> */
    private function findAccessibleSessions(Request $request): array
    {
        $customer = $this->getCurrentCustomer();

        if ($customer instanceof Customer) {
            return $this->entityManager->getRepository(PersonalizationSession::class)->findBy(
                ['ownerCustomer' => $customer],
                ['updatedAt' => 'DESC'],
            );
        }

        $ownerToken = trim((string) $request->headers->get(PersonalizationSessionOwnershipGuard::HEADER_NAME, ''));

        if ('' === $ownerToken) {
            return [];
        }

        return $this->entityManager->getRepository(PersonalizationSession::class)->findBy(
            ['guestOwnerToken' => $ownerToken],
            ['updatedAt' => 'DESC'],
        );
    }

    /** @param array<string, mixed>|null $book */
    private function normalizeProject(PersonalizationSession $session, ?array $book): array
    {
        return [
            'id' => $session->getId(),
            'bookId' => $session->getBookId(),
            'bookTitle' => (string) ($book['title'] ?? $session->getBookId()),
            'coverImage' => (string) ($book['coverImage'] ?? ''),
            'childName' => $session->getChildName() ?? '',
            'status' => $this->mapProjectStatus($session),
            'createdAt' => $session->getCreatedAt()->format('d/m/Y'),
            'updatedAt' => $session->getUpdatedAt()->format('d/m/Y'),
            'previewPages' => [],
            'personalization' => [
                'id' => $session->getId(),
                'bookId' => $session->getBookId(),
                'step' => $session->getStep(),
                'childName' => $session->getChildName() ?? '',
                'childPhoto' => null,
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
            ],
        ];
    }

    private function mapProjectStatus(PersonalizationSession $session): string
    {
        return match ($session->getStatus()->value) {
            'draft', 'photo_uploaded', 'content_completed' => 'draft',
            'generation_requested', 'generating', 'preview_partial_ready' => 'generating',
            'preview_ready', 'approved' => 'ready',
            'cart_attached', 'checkout_completed', 'pdf_rendering', 'print_ready', 'submitted_to_gelato', 'in_production' => 'ordered',
            'shipped', 'delivered' => 'completed',
            default => 'draft',
        };
    }

    private function getCurrentCustomer(): ?Customer
    {
        $user = $this->security->getUser();

        if (!$user instanceof ShopUser) {
            return null;
        }

        $customer = $user->getCustomer();

        return $customer instanceof Customer ? $customer : null;
    }

    private function hasOwnershipContext(Request $request): bool
    {
        return $this->getCurrentCustomer() instanceof Customer
            || '' !== trim((string) $request->headers->get(PersonalizationSessionOwnershipGuard::HEADER_NAME, ''));
    }
}
