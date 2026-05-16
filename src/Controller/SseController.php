<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PersonalizationSession;
use App\RateLimiting\RateLimit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/personalization/sessions')]
final class SseController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[RateLimit('read', 'session')]
    #[Route('/{id}/generation/stream', name: 'app_sse_generation_stream', methods: ['GET'])]
    public function streamGeneration(int $id, Request $request): Response
    {
        $session = $this->entityManager->getRepository(PersonalizationSession::class)->find($id);
        if (null === $session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        $response = new StreamedResponse(function () use ($session): void {
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $lastStatus = null;
            $timeout = 300;
            $start = time();

            while ((time() - $start) < $timeout) {
                $this->entityManager->clear();
                $current = $this->entityManager->getRepository(PersonalizationSession::class)->find($session->getId());

                if (null === $current) {
                    echo "event: error\ndata: " . json_encode(['error' => 'Session lost']) . "\n\n";
                    flush();
                    break;
                }

                $status = $current->getStatus()->value;
                $data = ['status' => $status, 'sessionId' => $current->getId()];

                if ($status !== $lastStatus) {
                    $lastStatus = $status;
                    echo "event: status\ndata: " . json_encode($data) . "\n\n";
                    flush();
                }

                if (in_array($status, ['preview_ready', 'approved', 'failed', 'cancelled'], true)) {
                    echo "event: complete\ndata: " . json_encode($data) . "\n\n";
                    flush();
                    break;
                }

                sleep(2);
            }

            echo "event: timeout\ndata: {}\n\n";
            flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
