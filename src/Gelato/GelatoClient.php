<?php

declare(strict_types=1);

namespace App\Gelato;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GelatoClient implements GelatoClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::GELATO_API_KEY)%')]
        private readonly ?string $apiKey,
        #[Autowire('%env(default::GELATO_API_BASE_URI)%')]
        private readonly ?string $apiBaseUri,
    ) {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrder(array $payload): array
    {
        $apiKey = trim((string) $this->apiKey);

        if ('' === $apiKey) {
            throw new \RuntimeException('Gelato is not configured locally. Set GELATO_API_KEY before submitting fulfillment orders.');
        }

        $response = $this->httpClient->request('POST', rtrim($this->apiBaseUri ?: 'https://order.gelatoapis.com', '/').'/v4/orders', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $apiKey,
            ],
            'json' => $payload,
        ]);

        $data = $response->toArray(false);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Gelato order creation failed with HTTP %d.', $response->getStatusCode()));
        }

        return is_array($data) ? $data : [];
    }
}
