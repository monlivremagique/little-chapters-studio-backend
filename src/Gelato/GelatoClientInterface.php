<?php

declare(strict_types=1);

namespace App\Gelato;

interface GelatoClientInterface
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrder(array $payload): array;

    /** @return array<string, mixed> */
    public function getOrder(string $providerOrderId): array;
}
