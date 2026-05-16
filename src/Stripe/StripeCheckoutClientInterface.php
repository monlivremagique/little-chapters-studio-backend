<?php

declare(strict_types=1);

namespace App\Stripe;

interface StripeCheckoutClientInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $sessionId): array;

    /**
     * @return array<string, mixed>
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;
}
