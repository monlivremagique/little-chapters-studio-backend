<?php

declare(strict_types=1);

namespace App\Tests\Double\Gelato;

use App\Gelato\GelatoClientInterface;

final class FakeGelatoClient implements GelatoClientInterface
{
    /** @var list<array<string, mixed>> */
    private array $createdOrders = [];

    private bool $shouldFailNextCreate = false;

    public function setShouldFailNextCreate(bool $fail): void
    {
        $this->shouldFailNextCreate = $fail;
    }

    public function reset(): void
    {
        $this->createdOrders = [];
        $this->shouldFailNextCreate = false;
    }

    public function createOrder(array $payload): array
    {
        if ($this->shouldFailNextCreate) {
            $this->shouldFailNextCreate = false;
            throw new \RuntimeException('Gelato API error: simulated transient failure (503)');
        }

        $this->createdOrders[] = $payload;

        return [
            'id' => sprintf('gelato_test_%s', bin2hex(random_bytes(6))),
            'orderReferenceId' => (string) ($payload['orderReferenceId'] ?? ''),
            'status' => 'submitted',
        ];
    }

    public function getOrder(string $providerOrderId): array
    {
        return [
            'id' => $providerOrderId,
            'status' => 'submitted',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getCreatedOrders(): array
    {
        return $this->createdOrders;
    }
}
