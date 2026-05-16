<?php

declare(strict_types=1);

namespace App\Integration\Replicate;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReplicateTextGenerationClient implements ReplicateTextGenerationClientInterface
{
    private HttpClientInterface $httpClient;

    /** @var array<string, string> */
    private array $resolvedModelVersions = [];

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(string:REPLICATE_API_TOKEN)%')]
        private readonly string $apiToken,
        #[Autowire('%env(string:REPLICATE_API_BASE_URI)%')]
        private readonly string $apiBaseUri,
    ) {
        $this->httpClient = ScopingHttpClient::forBaseUri(
            $httpClient,
            '' !== trim($this->apiBaseUri) ? rtrim($this->apiBaseUri, '/') : 'https://api.replicate.com/v1',
            [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', trim($this->apiToken)),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ],
        );
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiToken);
    }

    public function assertConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        throw new \RuntimeException('Replicate text generation is not configured locally. Set REPLICATE_API_TOKEN.');
    }

    public function createPrediction(string $modelReference, array $input): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request('POST', $this->buildApiUrl('predictions'), [
                'json' => [
                    'version' => $this->resolveLatestModelVersion($modelReference),
                    'input' => $input,
                ],
            ]);

            return $response->toArray(false);
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate text prediction request failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    public function getPrediction(string $predictionId): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request('GET', $this->buildApiUrl(sprintf('predictions/%s', rawurlencode(trim($predictionId)))));

            return $response->toArray(false);
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate text prediction polling failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    private function buildApiUrl(string $path): string
    {
        return rtrim($this->apiBaseUri, '/') . '/' . ltrim($path, '/');
    }

    private function extractError(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return '' !== $message ? $message : $exception::class;
    }

    private function resolveLatestModelVersion(string $modelReference): string
    {
        if (isset($this->resolvedModelVersions[$modelReference])) {
            return $this->resolvedModelVersions[$modelReference];
        }

        try {
            [$owner, $name] = $this->splitModel($modelReference);
            $response = $this->httpClient->request('GET', $this->buildApiUrl(sprintf('models/%s/%s', rawurlencode($owner), rawurlencode($name))));
            $payload = $response->toArray(false);
            $version = trim((string) (($payload['latest_version']['id'] ?? null) ?: ''));

            if ('' === $version) {
                throw new \RuntimeException(sprintf('Replicate model "%s" did not return a latest version id.', $modelReference));
            }

            $this->resolvedModelVersions[$modelReference] = $version;

            return $version;
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate text model lookup failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    /** @return array{string,string} */
    private function splitModel(string $modelReference): array
    {
        $parts = explode('/', trim($modelReference), 2);

        if (2 !== count($parts) || '' === trim($parts[0]) || '' === trim($parts[1])) {
            throw new \RuntimeException('Replicate model must be in the form "owner/name".');
        }

        return [trim($parts[0]), trim($parts[1])];
    }
}
