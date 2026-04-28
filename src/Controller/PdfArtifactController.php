<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PdfArtifact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PdfArtifactController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route(
        '/api/personalization/pdfs/{accessToken}',
        name: 'app_personalization_pdfs_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readPdf(string $accessToken): Response
    {
        /** @var PdfArtifact|null $artifact */
        $artifact = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy([
            'accessToken' => trim($accessToken),
        ]);

        if (!$artifact instanceof PdfArtifact || !is_file($artifact->getStoragePath())) {
            return new JsonResponse(['message' => 'PDF artifact not found.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($artifact->getStoragePath());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="personalized-book.pdf"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
