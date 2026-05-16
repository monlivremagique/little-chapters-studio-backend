<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessStripeWebhookMessage;
use App\Stripe\StripeCheckoutSynchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'sylius.command_bus', handles: ProcessStripeWebhookMessage::class)]
final readonly class ProcessStripeWebhookMessageHandler
{
    public function __construct(
        private StripeCheckoutSynchronizer $stripeCheckoutSynchronizer,
    ) {
    }

    public function __invoke(ProcessStripeWebhookMessage $message): void
    {
        $this->stripeCheckoutSynchronizer->handleWebhookEvent($message->event());
    }
}
