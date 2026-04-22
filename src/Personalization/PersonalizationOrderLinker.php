<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class PersonalizationOrderLinker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    public function attachSessionToCartItem(PersonalizationSession $session, string $cartTokenValue, string $cartItemId): void
    {
        $normalizedCartToken = trim($cartTokenValue);
        $normalizedCartItemId = trim($cartItemId);

        if ('' === $normalizedCartToken || '' === $normalizedCartItemId || !ctype_digit($normalizedCartItemId)) {
            throw new \InvalidArgumentException('A valid cart token and numeric cart item id are required.');
        }

        $orderItem = $this->findOrderItemByCartToken($normalizedCartToken, (int) $normalizedCartItemId);

        if (null === $orderItem) {
            throw new \RuntimeException('The provided cart item does not belong to the provided cart token.');
        }

        if (null !== $session->getCartItemId() && $session->getCartItemId() !== $normalizedCartItemId) {
            throw new \RuntimeException('This personalization session is already linked to another cart item.');
        }

        /** @var PersonalizationOrderItemLink|null $existingSessionLink */
        $existingSessionLink = $this->entityManager->getRepository(PersonalizationOrderItemLink::class)->findOneBy([
            'session' => $session,
        ]);

        /** @var PersonalizationOrderItemLink|null $existingOrderItemLink */
        $existingOrderItemLink = $this->entityManager->getRepository(PersonalizationOrderItemLink::class)->findOneBy([
            'orderItemId' => $orderItem['order_item_id'],
        ]);

        if (null !== $existingOrderItemLink && $existingOrderItemLink->getSession()->getId() !== $session->getId()) {
            throw new \RuntimeException('This cart item is already linked to another personalization session.');
        }

        if (null === $existingSessionLink) {
            $existingSessionLink = new PersonalizationOrderItemLink($session, $orderItem['order_item_id']);
            $this->entityManager->persist($existingSessionLink);
        } else {
            $existingSessionLink->setOrderItemId($orderItem['order_item_id']);
        }

        $session->attachToCart($normalizedCartToken, $normalizedCartItemId);
    }

    public function detachSessionFromCartItem(string $cartTokenValue, string $cartItemId): ?PersonalizationSession
    {
        $normalizedCartToken = trim($cartTokenValue);
        $normalizedCartItemId = trim($cartItemId);

        if ('' === $normalizedCartToken || '' === $normalizedCartItemId || !ctype_digit($normalizedCartItemId)) {
            return null;
        }

        /** @var PersonalizationOrderItemLink|null $link */
        $link = $this->entityManager->getRepository(PersonalizationOrderItemLink::class)->findOneBy([
            'orderItemId' => (int) $normalizedCartItemId,
        ]);

        if (null === $link) {
            return null;
        }

        $session = $link->getSession();

        if ($session->getCartTokenValue() !== $normalizedCartToken || $session->getCartItemId() !== $normalizedCartItemId) {
            return null;
        }

        $session->detachFromCart();
        $this->entityManager->remove($link);
        $this->entityManager->flush();

        return $session;
    }

    /** @return list<PersonalizationSession> */
    public function synchronizeSessionsWithOrderNumber(string $orderNumber): array
    {
        $order = $this->findOrderByNumber($orderNumber);

        if (null === $order) {
            return [];
        }

        return $this->synchronizeSessionsForOrder($order);
    }

    /** @return list<PersonalizationSession> */
    public function synchronizeSessionsWithOrderToken(string $cartTokenValue): array
    {
        $order = $this->findOrderByToken($cartTokenValue);

        if (null === $order) {
            return [];
        }

        return $this->synchronizeSessionsForOrder($order);
    }

    /** @return list<PersonalizationSession> */
    public function findSessionsByOrderNumber(string $orderNumber): array
    {
        $order = $this->findOrderByNumber($orderNumber);

        if (null === $order) {
            return [];
        }

        return array_map(
            static fn (PersonalizationOrderItemLink $link): PersonalizationSession => $link->getSession(),
            $this->findLinksByOrderId($order['order_id']),
        );
    }

    /** @return array{order_item_id:int, order_id:int}|null */
    private function findOrderItemByCartToken(string $cartTokenValue, int $orderItemId): ?array
    {
        $sql = <<<'SQL'
SELECT oi.id AS order_item_id, o.id AS order_id
FROM sylius_order_item oi
INNER JOIN sylius_order o ON o.id = oi.order_id
WHERE o.token_value = :tokenValue
  AND oi.id = :orderItemId
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'tokenValue' => $cartTokenValue,
            'orderItemId' => $orderItemId,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'order_item_id' => (int) $row['order_item_id'],
            'order_id' => (int) $row['order_id'],
        ];
    }

    /** @return array{order_id:int, order_number:string, checkout_state:string}|null */
    private function findOrderByNumber(string $orderNumber): ?array
    {
        $sql = <<<'SQL'
SELECT o.id AS order_id, o.number AS order_number, o.checkout_state
FROM sylius_order o
WHERE o.number = :orderNumber
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'orderNumber' => $orderNumber,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'order_id' => (int) $row['order_id'],
            'order_number' => (string) $row['order_number'],
            'checkout_state' => (string) $row['checkout_state'],
        ];
    }

    /** @return array{order_id:int, order_number:string, checkout_state:string}|null */
    private function findOrderByToken(string $cartTokenValue): ?array
    {
        $sql = <<<'SQL'
SELECT o.id AS order_id, o.number AS order_number, o.checkout_state
FROM sylius_order o
WHERE o.token_value = :tokenValue
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'tokenValue' => $cartTokenValue,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'order_id' => (int) $row['order_id'],
            'order_number' => (string) $row['order_number'],
            'checkout_state' => (string) $row['checkout_state'],
        ];
    }

    /** @return list<PersonalizationSession> */
    private function synchronizeSessionsForOrder(array $order): array
    {
        $links = $this->findLinksByOrderId($order['order_id']);

        if ([] === $links) {
            return [];
        }

        $sessions = [];

        foreach ($links as $link) {
            $session = $link->getSession();

            if ($order['checkout_state'] === 'completed') {
                $session->markCheckoutCompleted($order['order_id'], $order['order_number']);
            }

            $sessions[] = $session;
        }

        if ($order['checkout_state'] === 'completed') {
            $this->entityManager->flush();
        }

        return $sessions;
    }

    /** @return list<PersonalizationOrderItemLink> */
    private function findLinksByOrderId(int $orderId): array
    {
        $sql = <<<'SQL'
SELECT l.personalization_session_id
FROM app_personalization_order_item_link l
INNER JOIN sylius_order_item oi ON oi.id = l.order_item_id
WHERE oi.order_id = :orderId
ORDER BY l.id ASC
SQL;

        /** @var list<string> $sessionIds */
        $sessionIds = $this->connection->fetchFirstColumn($sql, [
            'orderId' => $orderId,
        ]);

        if ([] === $sessionIds) {
            return [];
        }

        $links = [];

        foreach ($sessionIds as $sessionId) {
            /** @var PersonalizationOrderItemLink|null $link */
            $link = $this->entityManager->getRepository(PersonalizationOrderItemLink::class)->findOneBy([
                'session' => $this->entityManager->getReference(PersonalizationSession::class, $sessionId),
            ]);

            if ($link instanceof PersonalizationOrderItemLink) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
