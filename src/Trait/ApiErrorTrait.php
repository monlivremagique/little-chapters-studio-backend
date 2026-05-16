<?php

declare(strict_types=1);

namespace App\Trait;

use App\Error\ErrorMessageMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides a standard {@see error()} helper for API controllers.
 *
 * Every error response uses a consistent JSON envelope with
 * sanitised public messages — never raw exception messages.
 */
trait ApiErrorTrait
{
    /**
     * Return a sanitised error JSON response.
     *
     * @param array<string, string> $extraHeaders
     */
    protected function error(
        string $publicMessage,
        int $httpCode = Response::HTTP_BAD_REQUEST,
        array $extraHeaders = [],
        string $correlationId = '',
    ): JsonResponse {
        if ('' === $correlationId) {
            $correlationId = bin2hex(random_bytes(16));
        }

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
            $response->headers->set($key, $value);
        }

        return $response;
    }

    /**
     * Map a raw exception message to a public-safe message via {@see ErrorMessageMapper}.
     *
     * Controllers that need to sanitise a caught exception can use this.
     */
    protected function errorFromException(
        \Throwable $exception,
        int $httpCode = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        $mapper = new ErrorMessageMapper();
        $publicMessage = $mapper->toPublicMessage($exception->getMessage(), $httpCode);

        return $this->error($publicMessage, $httpCode);
    }

    /**
     * Convenience: 400 with a public-friendly message.
     */
    protected function badRequest(string $fallbackMessage = ''): JsonResponse
    {
        return $this->error($fallbackMessage ?: 'La requête n\'a pas pu être traitée. Veuillez vérifier les informations saisies.', Response::HTTP_BAD_REQUEST);
    }

    /**
     * Convenience: 404 with a public-friendly message.
     */
    protected function notFound(string $fallbackMessage = ''): JsonResponse
    {
        return $this->error($fallbackMessage ?: 'La page demandée est introuvable.', Response::HTTP_NOT_FOUND);
    }

    /**
     * Convenience: 422 with a public-friendly message.
     */
    protected function unprocessable(string $fallbackMessage = ''): JsonResponse
    {
        return $this->error($fallbackMessage ?: 'Certaines informations fournies ne sont pas valides.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
