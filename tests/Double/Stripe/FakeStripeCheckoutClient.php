<?php

declare(strict_types=1);

namespace App\Tests\Double\Stripe;

use App\Stripe\StripeCheckoutClientInterface;

final class FakeStripeCheckoutClient implements StripeCheckoutClientInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    public function createCheckoutSession(array $payload): array
    {
        $sessionId = sprintf('cs_test_%s', bin2hex(random_bytes(8)));
        $session = [
            'id' => $sessionId,
            'url' => sprintf('https://checkout.stripe.test/pay/%s', $sessionId),
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'metadata' => $payload['metadata'] ?? [],
        ];

        $this->sessions[$sessionId] = $session;

        return $session;
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        if (!array_key_exists($sessionId, $this->sessions)) {
            throw new \RuntimeException(sprintf('Unknown fake Stripe checkout session "%s".', $sessionId));
        }

        return $this->sessions[$sessionId];
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid fake Stripe webhook payload.');
        }

        return $decoded;
    }

    public function markSessionPaid(string $sessionId, ?string $paymentIntentId = null): void
    {
        $session = $this->retrieveCheckoutSession($sessionId);
        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['payment_intent'] = $paymentIntentId ?? sprintf('pi_test_%s', bin2hex(random_bytes(8)));

        $this->sessions[$sessionId] = $session;
    }

    public function markSessionFailed(string $sessionId): void
    {
        $session = $this->retrieveCheckoutSession($sessionId);
        $session['status'] = 'complete';
        $session['payment_status'] = 'unpaid';

        $this->sessions[$sessionId] = $session;
    }

    public function markSessionExpired(string $sessionId): void
    {
        $session = $this->retrieveCheckoutSession($sessionId);
        $session['status'] = 'expired';
        $session['payment_status'] = 'unpaid';

        $this->sessions[$sessionId] = $session;
    }
}
