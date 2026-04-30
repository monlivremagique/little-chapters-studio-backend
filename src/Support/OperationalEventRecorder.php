<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Support\OperationalEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OperationalEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function record(string $type, string $level = 'info', ?string $sessionId = null, ?string $orderNumber = null, array $context = []): void
    {
        $normalizedContext = [
            'session_id' => $sessionId,
            'order_number' => $orderNumber,
            'payment_id' => isset($context['payment_id']) ? (string) $context['payment_id'] : null,
            'provider_order_id' => isset($context['provider_order_id']) ? (string) $context['provider_order_id'] : null,
            'provider_job_id' => isset($context['provider_job_id']) ? (string) $context['provider_job_id'] : null,
            'pdf_artifact_id' => isset($context['pdf_artifact_id']) ? (string) $context['pdf_artifact_id'] : null,
        ] + $context;

        $this->entityManager->persist(new OperationalEvent($type, $level, $sessionId, $orderNumber, $normalizedContext));
        $this->logger->log($level, $type, $normalizedContext);
    }
}
