<?php

declare(strict_types=1);

namespace App\Integration\Replicate;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReplicatePredictionClient implements ReplicatePredictionClientInterface
{
    private HttpClientInterface $httpClient;
    private ?string $resolvedModelVersion = null;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(string:REPLICATE_API_TOKEN)%')]
        private readonly string $apiToken,
        #[Autowire('%env(string:REPLICATE_MODEL)%')]
        private readonly string $model,
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
        return '' !== trim($this->apiToken) && '' !== trim($this->model);
    }

    public function assertConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        throw new \RuntimeException('Replicate is not configured locally. Set REPLICATE_API_TOKEN and REPLICATE_MODEL.');
    }

    public function getModelReference(): string
    {
        return trim($this->model);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function createPrediction(array $input): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request('POST', $this->buildApiUrl('predictions'), [
                'json' => [
                    'version' => $this->resolveLatestModelVersion(),
                    'input' => $input,
                ],
            ]);

            return $response->toArray(false);
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate prediction request failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrediction(string $predictionId): array
    {
        $this->assertConfigured();

        try {
            $response = $this->httpClient->request('GET', $this->buildApiUrl(sprintf('predictions/%s', rawurlencode(trim($predictionId)))));

            return $response->toArray(false);
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate prediction polling failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    /**
     * @return array{content:string,mimeType:string}
     */
    public function downloadFile(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'image/*,*/*;q=0.1',
                ],
            ]);

            return [
                'content' => $response->getContent(),
                'mimeType' => $response->getHeaders(false)['content-type'][0] ?? 'application/octet-stream',
            ];
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate output download failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    private function extractError(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return '' !== $message ? $message : $exception::class;
    }

    private function buildApiUrl(string $path): string
    {
        return rtrim($this->apiBaseUri, '/') . '/' . ltrim($path, '/');
    }

    private function resolveLatestModelVersion(): string
    {
        if (null !== $this->resolvedModelVersion) {
            return $this->resolvedModelVersion;
        }

        try {
            [$owner, $name] = $this->splitModel();
            $response = $this->httpClient->request('GET', $this->buildApiUrl(sprintf('models/%s/%s', rawurlencode($owner), rawurlencode($name))));
            $payload = $response->toArray(false);
            $version = trim((string) (($payload['latest_version']['id'] ?? null) ?: ''));

            if ('' === $version) {
                throw new \RuntimeException(sprintf('Replicate model "%s" did not return a latest version id.', $this->model));
            }

            $this->resolvedModelVersion = $version;

            return $this->resolvedModelVersion;
        } catch (ClientException|TransportException $exception) {
            throw new \RuntimeException(sprintf('Replicate model lookup failed: %s', $this->extractError($exception)), 0, $exception);
        }
    }

    /**
     * @return array{string,string}
     */
    private function splitModel(): array
    {
        $parts = explode('/', trim($this->model), 2);

        if (2 !== count($parts) || '' === trim($parts[0]) || '' === trim($parts[1])) {
            throw new \RuntimeException('REPLICATE_MODEL must be in the form "owner/name".');
        }

        return [trim($parts[0]), trim($parts[1])];
    }
}
