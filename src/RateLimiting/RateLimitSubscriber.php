<?php

declare(strict_types=1);

namespace App\RateLimiting;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RateLimitSubscriber implements EventSubscriberInterface
{
    /** @var array<int, array{limit:int, remaining:int, reset:int}> */
    private array $rateLimitHeaders = [];

    public function __construct(
        #[Autowire(service: 'limiter.generation')]
        private readonly RateLimiterFactory $generationRateLimiter,
        #[Autowire(service: 'limiter.photo_upload')]
        private readonly RateLimiterFactory $photoUploadRateLimiter,
        #[Autowire(service: 'limiter.session_mutation')]
        private readonly RateLimiterFactory $sessionMutationRateLimiter,
        #[Autowire(service: 'limiter.auth')]
        private readonly RateLimiterFactory $authRateLimiter,
        #[Autowire(service: 'limiter.checkout')]
        private readonly RateLimiterFactory $checkoutRateLimiter,
        #[Autowire(service: 'limiter.read')]
        private readonly RateLimiterFactory $readRateLimiter,
        #[Autowire(service: 'limiter.webhook')]
        private readonly RateLimiterFactory $webhookRateLimiter,
        #[Autowire(service: 'limiter.support')]
        private readonly RateLimiterFactory $supportRateLimiter,
        #[Autowire(service: 'limiter.global')]
        private readonly RateLimiterFactory $globalRateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 7],
            KernelEvents::RESPONSE => ['onKernelResponse', -7],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();

        if (!\is_array($controller) || 2 !== \count($controller)) {
            return;
        }

        [$controllerObject, $methodName] = $controller;

        $refClass = new \ReflectionClass($controllerObject);
        $refMethod = $refClass->getMethod($methodName);

        $classAttr = $refClass->getAttributes(RateLimit::class);
        $methodAttr = $refMethod->getAttributes(RateLimit::class);

        $attr = $methodAttr[0] ?? $classAttr[0] ?? null;

        if (null === $attr) {
            return;
        }

        /** @var RateLimit $config */
        $config = $attr->newInstance();
        $request = $event->getRequest();

        $key = $this->buildKey($request, $config->keyStrategy);
        if (null === $key) {
            return;
        }

        $limiter = $this->resolveLimiter($config->limiter);
        if (null === $limiter) {
            return;
        }

        $limit = $limiter->create($key);
        $resolved = $limit->consume(1);

        if (!$resolved->isAccepted()) {
            $retryAfter = max(1, $resolved->getRetryAfter()->getTimestamp() - time());

            $this->logger->warning('Rate limit exceeded', [
                'limiter' => $config->limiter,
                'key' => $this->sanitizeKey($key),
                'route' => $request->attributes->get('_route'),
                'method' => $request->getMethod(),
                'retry_after' => $retryAfter,
                'client_ip' => $request->getClientIp(),
            ]);

            $message = 1 === $retryAfter
                ? 'Trop de requêtes. Veuillez réessayer dans 1 seconde.'
                : \sprintf('Trop de requêtes. Veuillez réessayer dans %d secondes.', $retryAfter);

            $response = new JsonResponse(
                ['message' => $message, 'retryAfter' => $retryAfter],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $resolved->getLimit(),
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) ($resolved->getRetryAfter()->getTimestamp()),
                ],
            );

            $event->setResponse($response);
            return;
        }

        $this->rateLimitHeaders[spl_object_id($request)] = [
            'limit' => $resolved->getLimit(),
            'remaining' => $resolved->getRemainingTokens(),
            'reset' => $resolved->getRetryAfter()->getTimestamp(),
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $oid = spl_object_id($request);

        if (!isset($this->rateLimitHeaders[$oid])) {
            return;
        }

        $headers = $this->rateLimitHeaders[$oid];
        $response = $event->getResponse();

        $response->headers->set('X-RateLimit-Limit', (string) $headers['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $headers['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $headers['reset']);

        if ($headers['remaining'] <= max(1, (int) round($headers['limit'] * 0.2))) {
            $response->headers->set('X-RateLimit-Warning', 'approaching_limit');
        }

        unset($this->rateLimitHeaders[$oid]);
    }

    private function buildKey(Request $request, string $strategy): ?string
    {
        return match ($strategy) {
            'ip' => \sprintf('ip:%s', $request->getClientIp() ?? 'unknown'),
            'token' => \sprintf('token:%s', $request->headers->get('X-Personalization-Owner-Token', 'missing')),
            'user' => $this->buildUserKey($request),
            'session' => $this->buildSessionKey($request),
            'webhook' => \sprintf('webhook:%s:%s', $request->getClientIp() ?? 'unknown', \substr($request->headers->get('User-Agent', ''), 0, 32)),
            'global' => 'global',
            default => \sprintf('ip:%s', $request->getClientIp() ?? 'unknown'),
        };
    }

    private function buildUserKey(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $parts = explode('.', $matches[1]);
        if (3 !== \count($parts)) {
            return \sprintf('user:hash:%s', \substr(\hash('sha256', $matches[1]), 0, 16));
        }

        $payload = \json_decode(
            \base64_decode(\strtr($parts[1], '-_', '+/'), true) ?: '{}',
            true,
        );

        if (\is_array($payload) && isset($payload['sub'])) {
            return \sprintf('user:%s', $payload['sub']);
        }

        return \sprintf('user:hash:%s', \substr(\hash('sha256', $matches[1]), 0, 16));
    }

    private function buildSessionKey(Request $request): string
    {
        $ownerToken = $request->headers->get('X-Personalization-Owner-Token', '');
        $sessionId = $request->attributes->get('id', 'unknown');
        $orderNumber = $request->attributes->get('orderNumber', '');

        if ('' !== $orderNumber) {
            return \sprintf('session:%s:order:%s', $ownerToken, $orderNumber);
        }

        return \sprintf('session:%s:%s', $ownerToken, $sessionId);
    }

    private function resolveLimiter(string $name): ?RateLimiterFactory
    {
        return match ($name) {
            'generation' => $this->generationRateLimiter,
            'photo_upload' => $this->photoUploadRateLimiter,
            'session_mutation' => $this->sessionMutationRateLimiter,
            'auth' => $this->authRateLimiter,
            'checkout' => $this->checkoutRateLimiter,
            'read' => $this->readRateLimiter,
            'webhook' => $this->webhookRateLimiter,
            'support' => $this->supportRateLimiter,
            'global' => $this->globalRateLimiter,
            default => null,
        };
    }

    private function sanitizeKey(string $key): string
    {
        if (str_starts_with($key, 'session:')) {
            $parts = explode(':', $key);
            if (\count($parts) >= 3) {
                $parts[1] = \substr(\hash('sha256', $parts[1]), 0, 8);
                return implode(':', $parts);
            }
        }
        if (str_starts_with($key, 'token:')) {
            return 'token:' . \substr(\hash('sha256', \substr($key, 6)), 0, 8);
        }
        return $key;
    }
}
