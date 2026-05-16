<?php

declare(strict_types=1);

namespace App\Controller;

use App\FrontCatalog\FrontCatalogProvider;
use App\RateLimiting\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[RateLimit('read', 'ip')]
final class FrontCatalogController
{
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
            return new JsonResponse(['error' => $e->getMessage()], 400);
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
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (NotFoundHttpException $e) {
            // Catch here to return JSON 404 directly. Symfony's default exception handler
            // tries to initialize a session on stateless routes, causing HTTP 500 instead of 404.
            return new JsonResponse(['error' => $e->getMessage()], 404);
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
            return new JsonResponse(['error' => $e->getMessage()], 400);
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
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
    }
}
