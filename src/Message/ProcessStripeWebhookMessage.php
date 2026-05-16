<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessStripeWebhookMessage
{
    /**
     * @param array<string, mixed> $event
     */
    public function __construct(
        private array $event,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function event(): array
    {
        return $this->event;
    }
}
