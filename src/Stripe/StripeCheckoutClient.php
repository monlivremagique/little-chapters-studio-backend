<?php

declare(strict_types=1);

namespace App\Stripe;

use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeCheckoutClient implements StripeCheckoutClientInterface
{
    private readonly ?string $secretKey;
    private readonly ?string $webhookSecret;

    public function __construct(
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        ?string $secretKey,
        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        ?string $webhookSecret,
    ) {
        $this->secretKey = $this->normalizeNullableString($secretKey);
        $this->webhookSecret = $this->normalizeNullableString($webhookSecret);
    }

    public function createCheckoutSession(array $payload): array
    {
        $session = $this->buildClient()->checkout->sessions->create($payload);

        return $this->normalizeSession($session);
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $session = $this->buildClient()->checkout->sessions->retrieve($sessionId, []);

        return $this->normalizeSession($session);
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $normalizedSignature = trim($signatureHeader);

        if ('' === $normalizedSignature) {
            throw new \RuntimeException('Missing Stripe signature header.');
        }

        $event = Webhook::constructEvent($payload, $normalizedSignature, $this->requireWebhookSecret());

        return $this->normalizeEvent($event);
    }

    private function buildClient(): StripeClient
    {
        return new StripeClient([
            'api_key' => $this->requireSecretKey(),
            'stripe_version' => '2026-02-25.clover',
        ]);
    }

    private function requireSecretKey(): string
    {
        if (null === $this->secretKey) {
            throw new \RuntimeException('Stripe is not configured locally. Set STRIPE_SECRET_KEY.');
        }

        return $this->secretKey;
    }

    private function requireWebhookSecret(): string
    {
        if (null === $this->webhookSecret) {
            throw new \RuntimeException('Stripe webhook verification is not configured locally. Set STRIPE_WEBHOOK_SECRET.');
        }

        return $this->webhookSecret;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSession(Session $session): array
    {
        return [
            'id' => (string) $session->id,
            'url' => isset($session->url) ? (string) $session->url : null,
            'status' => isset($session->status) ? (string) $session->status : null,
            'payment_status' => isset($session->payment_status) ? (string) $session->payment_status : null,
            'payment_intent' => is_string($session->payment_intent) ? $session->payment_intent : null,
            'amount_total' => isset($session->amount_total) ? (int) $session->amount_total : null,
            'currency' => isset($session->currency) ? (string) $session->currency : null,
            'metadata' => is_array($session->metadata) ? $session->metadata : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEvent(Event $event): array
    {
        $payload = $event->jsonSerialize();

        if (!is_array($payload)) {
            throw new \RuntimeException('Stripe webhook payload normalization failed.');
        }

        return $payload;
    }
}
