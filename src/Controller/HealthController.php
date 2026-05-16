<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/api/health', name: 'app_api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'mon-livre-magique-backend',
        ]);
    }

    #[Route('/api/health/async', name: 'app_api_health_async', methods: ['GET'])]
    public function async(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'asyncQueueDepth' => $this->countQueue('async'),
            'failedQueueDepth' => $this->countQueue('failed'),
        ]);
    }

    private function countQueue(string $queueName): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue', ['queue' => $queueName]);
        } catch (\Throwable) {
            return 0;
        }
    }
}
