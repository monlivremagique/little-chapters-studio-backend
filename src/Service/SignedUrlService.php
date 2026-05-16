<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SignedUrlService
{
    private const int DEFAULT_TTL_SECONDS = 900;
    private const string SIGNATURE_ALGO = 'sha256';

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
    ) {
    }

    public function sign(string $resourceId, string $purpose, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $expires = time() + $ttlSeconds;
        $payload = sprintf('%s:%s:%d:%s', $resourceId, $purpose, $expires, $this->appSecret);
        $signature = hash_hmac(self::SIGNATURE_ALGO, $payload, $this->appSecret);

        return sprintf('%s:%d:%s', $resourceId, $expires, $signature);
    }

    public function verify(string $token, string $purpose): string
    {
        $parts = explode(':', $token);
        if (count($parts) < 3) {
            throw new \RuntimeException('Invalid signed URL token format.');
        }

        $resourceId = (string) $parts[0];
        $expires = (int) $parts[1];
        $signature = (string) $parts[2];

        if (time() > $expires) {
            throw new \RuntimeException('Signed URL has expired.');
        }

        $expected = hash_hmac(
            self::SIGNATURE_ALGO,
            sprintf('%s:%s:%d:%s', $resourceId, $purpose, $expires, $this->appSecret),
            $this->appSecret,
        );

        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid signed URL signature.');
        }

        return $resourceId;
    }

    public function buildUrl(string $basePath, string $resourceId, string $purpose, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $token = $this->sign($resourceId, $purpose, $ttlSeconds);
        return sprintf('%s/%s', rtrim($basePath, '/'), rawurlencode($token));
    }
}
