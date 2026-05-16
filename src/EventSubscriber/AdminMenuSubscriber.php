<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AdminMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['sylius.menu.admin.main' => 'buildMenu'];
    }

    public function buildMenu(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();
        $catalog = $menu->getChild('catalog');

        if (null !== $catalog) {
            $catalog->addChild('book_creation', [
                'route' => 'app_admin_book_creation_index',
                'label' => 'Création de livres',
            ])->setLabelAttribute('icon', 'book');
        }
    }
}
