<?php

declare(strict_types=1);

namespace App\Message;

final readonly class TriggerFulfillmentMessage
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $orderNumber,
    ) {
    }
}
