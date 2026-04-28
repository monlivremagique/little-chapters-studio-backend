<?php

declare(strict_types=1);

namespace App\Tests\Double\Gelato;

use App\Gelato\GelatoClientInterface;

final class FakeGelatoClient implements GelatoClientInterface
{
    /** @var list<array<string, mixed>> */
    private array $createdOrders = [];

    public function createOrder(array $payload): array
    {
        $this->createdOrders[] = $payload;

        return [
            'id' => sprintf('gelato_test_%s', bin2hex(random_bytes(6))),
            'orderReferenceId' => (string) ($payload['orderReferenceId'] ?? ''),
            'status' => 'submitted',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getCreatedOrders(): array
    {
        return $this->createdOrders;
    }
}
