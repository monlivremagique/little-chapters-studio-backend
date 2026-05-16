<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PdfArtifact;
use App\RateLimiting\RateLimit;
use App\Service\EncryptionService;
use App\Service\SignedUrlService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PdfArtifactController
{
    private const string ENCRYPTION_CONTEXT_PDF = 'pdf_artifact';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SignedUrlService $signedUrlService,
        private readonly EncryptionService $encryptionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[RateLimit('read', 'global')]
    #[Route(
        '/api/personalization/pdfs/signed/{token}',
        name: 'app_personalization_pdfs_signed',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readPdfSigned(string $token): Response
    {
        try {
            $sessionId = $this->signedUrlService->verify($token, 'pdf_access');
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_FORBIDDEN);
        }

        /** @var PdfArtifact|null $artifact */
        $artifact = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy(
            ['accessToken' => $token],
            ['id' => 'DESC'],
        );

        if (!$artifact instanceof PdfArtifact) {
            return new JsonResponse(['message' => 'PDF artifact not found.'], Response::HTTP_NOT_FOUND);
        }

        $storagePath = $artifact->getStoragePath();
        if (!is_file($storagePath)) {
            return new JsonResponse(['message' => 'PDF file not found in private storage.'], Response::HTTP_NOT_FOUND);
        }

        $encrypted = @file_get_contents($storagePath);
        if (false === $encrypted) {
            return new JsonResponse(['message' => 'PDF could not be read from private storage.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $decrypted = $this->encryptionService->decrypt($encrypted, self::ENCRYPTION_CONTEXT_PDF);
        } catch (\RuntimeException $exception) {
            $this->logger->error('PDF decryption failed', [
                'session_id' => $sessionId,
                'error' => $exception->getMessage(),
            ]);
            return new JsonResponse(['message' => 'PDF content is unavailable.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('PDF artifact accessed via signed URL.', [
            'session_id' => $sessionId,
            'artifact_id' => $artifact->getId(),
        ]);

        $response = new Response($decrypted);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="personalized-book.pdf"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
