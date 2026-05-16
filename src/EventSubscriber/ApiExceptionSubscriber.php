<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Error\ErrorEnvelopeFactory;
use App\RateLimiting\RateLimitSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Catches ALL uncaught exceptions and returns a sanitized JSON envelope.
 *
 * No stack traces, no internal paths, no service names reach the client.
 * Full details are logged server-side with a correlation ID.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    /**
     * @var array<int,string> Map status codes to log levels
     */
    private const LOG_LEVEL_MAP = [
        Response::HTTP_INTERNAL_SERVER_ERROR => 'error',
        Response::HTTP_BAD_GATEWAY => 'error',
        Response::HTTP_SERVICE_UNAVAILABLE => 'error',
        Response::HTTP_TOO_MANY_REQUESTS => 'warning',
        Response::HTTP_UNAUTHORIZED => 'info',
        Response::HTTP_FORBIDDEN => 'info',
        Response::HTTP_NOT_FOUND => 'info',
        Response::HTTP_CONFLICT => 'warning',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'warning',
    ];

    public function __construct(
        private readonly ErrorEnvelopeFactory $errorEnvelopeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();
        $request = $event->getRequest();

        $correlationId = $request->headers->get('X-Correlation-ID', '');
        if ('' === $correlationId) {
            $correlationId = bin2hex(random_bytes(16));
        }

        $httpCode = $this->resolveHttpCode($exception);

        // Log full details server-side (NEVER sent to client)
        $logLevel = self::LOG_LEVEL_MAP[$httpCode] ?? 'error';
        $this->logger->log($logLevel, 'API exception (uncaught): ' . $exception->getMessage(), [
            'correlation_id' => $correlationId,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $exception->getTraceAsString(),
            'previous_exception' => null !== $exception->getPrevious() ? [
                'class' => $exception->getPrevious()::class,
                'message' => $exception->getPrevious()->getMessage(),
            ] : null,
            'route' => $request->attributes->get('_route', 'N/A'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'client_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent', 'N/A'),
        ]);

        // Build sanitized response
        // If the request explicitly wants HTML (e.g. admin back-office), let Symfony handle it
        $acceptHeader = $request->headers->get('Accept', 'application/json');
        $isApiRequest = str_contains($acceptHeader, 'application/json')
            || str_starts_with($request->getPathInfo(), '/api/');

        if (!$isApiRequest) {
            // Let Symfony's default HTML error page handle non-API requests
            return;
        }

        $extraHeaders = [];

        if ($exception instanceof TooManyRequestsHttpException) {
            $retryAfter = $exception->getRetryAfter();
            if ($retryAfter instanceof \DateTimeInterface) {
                $retryAfter = $retryAfter->getTimestamp() - time();
            }
            $retryAfter = max(1, (int) $retryAfter);
            $extraHeaders = [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) (time() + $retryAfter),
            ];
        }

        $response = $this->errorEnvelopeFactory->fromException(
            $exception,
            $httpCode,
            $correlationId,
            extraHeaders: $extraHeaders,
        );

        $event->setResponse($response);
    }

    private function resolveHttpCode(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof \DomainException) {
            return Response::HTTP_CONFLICT;
        }

        if ($exception instanceof \InvalidArgumentException) {
            return Response::HTTP_BAD_REQUEST;
        }

        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
