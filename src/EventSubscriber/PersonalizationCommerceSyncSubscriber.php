<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Personalization\PersonalizationOrderLinker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class PersonalizationCommerceSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        $path = $request->getPathInfo();

        if (
            $request->isMethod('PATCH')
            && preg_match('#^/api/v2/shop/orders/(?P<token>[^/]+)/complete$#', $path, $matches) === 1
        ) {
            $this->personalizationOrderLinker->synchronizeSessionsWithOrderToken((string) $matches['token']);

            return;
        }

        if (
            $request->isMethod('DELETE')
            && preg_match('#^/api/v2/shop/orders/(?P<token>[^/]+)/items/(?P<itemId>\d+)$#', $path, $matches) === 1
        ) {
            $this->personalizationOrderLinker->detachSessionFromCartItem(
                (string) $matches['token'],
                (string) $matches['itemId'],
            );
        }
    }
}
