<?php

declare(strict_types=1);

namespace App\Controller;

use App\FrontCatalog\FrontCatalogProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

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
    public function books(): JsonResponse
    {
        return new JsonResponse($this->frontCatalogProvider->getBooks());
    }

    #[Route(
        '/api/books/{slug}',
        name: 'app_front_catalog_book_by_slug',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function bookBySlug(string $slug): JsonResponse
    {
        return new JsonResponse($this->frontCatalogProvider->getBookBySlug($slug));
    }

    #[Route(
        '/api/collections',
        name: 'app_front_catalog_collections',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function collections(): JsonResponse
    {
        return new JsonResponse($this->frontCatalogProvider->getCollections());
    }

    #[Route(
        '/api/collections/{slug}',
        name: 'app_front_catalog_collection_by_slug',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false, '_stateless' => true],
    )]
    public function collectionBySlug(string $slug): JsonResponse
    {
        return new JsonResponse($this->frontCatalogProvider->getCollectionBySlug($slug));
    }
}
