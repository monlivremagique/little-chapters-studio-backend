<?php

declare(strict_types=1);

namespace App\Error;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ErrorEnvelopeFactory
{
    public function __construct(
        private readonly ErrorMessageMapper $messageMapper,
    ) {
    }

    /**
     * Build a sanitized error JSON response.
     *
     * Only $publicMessage reaches the client. The $context array is
     * logged server-side for debugging.
     *
     * @param array<string, mixed> $context
     */
    public function createResponse(
        int $httpCode,
        string $publicMessage,
        string $correlationId,
        array $context = [],
        array $extraHeaders = [],
    ): JsonResponse {
        $body = [
            'error' => [
                'message' => $publicMessage,
                'code' => $httpCode,
                'correlationId' => $correlationId,
            ],
        ];

        $response = new JsonResponse($body, $httpCode);
        $response->headers->set('X-Correlation-ID', $correlationId);

        foreach ($extraHeaders as $key => $value) {
            $response->headers->set($key, (string) $value);
        }

        return $response;
    }

    /**
     * Shortcut: from a raw exception produce a sanitized response.
     *
     * @param array<string, mixed> $context
     */
    public function fromException(
        \Throwable $exception,
        int $httpCode,
        string $correlationId,
        array $context = [],
        array $extraHeaders = [],
    ): JsonResponse {
        $publicMessage = $this->messageMapper->toPublicMessage($exception->getMessage(), $httpCode);

        return $this->createResponse($httpCode, $publicMessage, $correlationId, $context, $extraHeaders);
    }
}
