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
        $this->entityManager->persist(new OperationalEvent($type, $level, $sessionId, $orderNumber, $context));
        $this->logger->log($level, $type, ['session_id' => $sessionId, 'order_number' => $orderNumber] + $context);
    }
}
