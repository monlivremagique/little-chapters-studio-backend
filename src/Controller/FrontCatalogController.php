<?php

declare(strict_types=1);

namespace App\Controller;

use App\FrontCatalog\FrontCatalogProvider;
use App\RateLimiting\RateLimit;
use App\Trait\ApiErrorTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[RateLimit('read', 'ip')]
final class FrontCatalogController
{
    use ApiErrorTrait;
    public function __construct(
        private readonly FrontCatalogProvider $frontCatalogProvider,
    ) {
    }

    #[Route(
        '/api/books',
        name: 'app_front_catalog_books',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function books(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;

        try {
            return new JsonResponse($this->frontCatalogProvider->getBooks($locale));
        } catch (BadRequestHttpException $e) {
            return $this->error('Le catalogue n\'a pas pu être chargé.', 400);
        }
    }

    #[Route(
        '/api/books/{slug}',
        name: 'app_front_catalog_book_by_slug',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function bookBySlug(string $slug, Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;

        try {
            return new JsonResponse($this->frontCatalogProvider->getBookBySlug($slug, $locale));
        } catch (BadRequestHttpException $e) {
            return $this->error('Cette langue n\'est pas disponible pour le moment.', 400);
        } catch (NotFoundHttpException $e) {
            return $this->error('Livre introuvable.', 404);
        }
    }

    #[Route(
        '/api/collections',
        name: 'app_front_catalog_collections',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function collections(Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;

        try {
            return new JsonResponse($this->frontCatalogProvider->getCollections($locale));
        } catch (BadRequestHttpException $e) {
            return $this->error('Le catalogue n\'a pas pu être chargé.', 400);
        }
    }

    #[Route(
        '/api/collections/{slug}',
        name: 'app_front_catalog_collection_by_slug',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function collectionBySlug(string $slug, Request $request): JsonResponse
    {
        $locale = $request->query->getString('locale') ?: null;

        try {
            return new JsonResponse($this->frontCatalogProvider->getCollectionBySlug($slug, $locale));
        } catch (BadRequestHttpException $e) {
            return $this->error('Cette langue n\'est pas disponible pour le moment.', 400);
        } catch (NotFoundHttpException $e) {
            return $this->error('Collection introuvable.', 404);
        }
    }
}
