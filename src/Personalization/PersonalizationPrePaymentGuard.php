<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Order\Order;
use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class PersonalizationPrePaymentGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
    }

    /** @param list<PersonalizationSession> $linkedSessions */
    public function assertOrderCanStartPayment(Order $order, array $linkedSessions): void
    {
        if ([] === $linkedSessions) {
            return;
        }

        foreach ($linkedSessions as $session) {
            $this->assertSessionCanStartPayment($order, $session);
        }
    }

    private function assertSessionCanStartPayment(Order $order, PersonalizationSession $session): void
    {
        if (null === $session->getApprovedAt()) {
            throw new \RuntimeException('The personalization preview must be approved before starting payment.');
        }

        if (!in_array($session->getStatus(), [PersonalizationSessionStatus::CartAttached, PersonalizationSessionStatus::CheckoutCompleted], true)) {
            throw new \RuntimeException('The personalization session must be attached to the cart before starting payment.');
        }

        if (null === $session->getCartTokenValue() || null === $session->getCartItemId()) {
            throw new \RuntimeException('The personalization cart linkage is incomplete.');
        }

        if ($session->getCartTokenValue() !== (string) $order->getTokenValue()) {
            throw new \RuntimeException('The personalization session is attached to a different cart.');
        }

        /** @var PersonalizationOrderItemLink|null $link */
        $link = $this->entityManager->getRepository(PersonalizationOrderItemLink::class)->findOneBy([
            'session' => $session,
        ]);

        if (!$link instanceof PersonalizationOrderItemLink) {
            throw new \RuntimeException('The personalization cart item link is missing.');
        }

        if ((string) $link->getOrderItemId() !== $session->getCartItemId()) {
            throw new \RuntimeException('The personalization cart item link is inconsistent.');
        }

        $currentItem = $this->findCurrentOrderItem((int) $order->getId(), $link->getOrderItemId());

        if (null === $currentItem) {
            throw new \RuntimeException('The linked personalization cart item is no longer present in the order.');
        }

        $this->assertSnapshotMatchesCurrentOrderItem($link, $currentItem);
    }

    /**
     * @return array{
     *     order_token_value:string,
     *     variant_code:string,
     *     product_name:string|null,
     *     unit_price:int,
     *     quantity:int,
     *     currency_code:string
     * }|null
     */
    private function findCurrentOrderItem(int $orderId, int $orderItemId): ?array
    {
        $sql = <<<'SQL'
SELECT
    o.token_value AS order_token_value,
    pv.code AS variant_code,
    oi.product_name,
    oi.unit_price,
    oi.quantity,
    o.currency_code
FROM sylius_order_item oi
INNER JOIN sylius_order o ON o.id = oi.order_id
INNER JOIN sylius_product_variant pv ON pv.id = oi.variant_id
WHERE o.id = :orderId
  AND oi.id = :orderItemId
LIMIT 1
SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
        ]);

        if (false === $row) {
            return null;
        }

        return [
            'order_token_value' => (string) $row['order_token_value'],
            'variant_code' => (string) $row['variant_code'],
            'product_name' => null !== $row['product_name'] ? (string) $row['product_name'] : null,
            'unit_price' => (int) $row['unit_price'],
            'quantity' => (int) $row['quantity'],
            'currency_code' => (string) $row['currency_code'],
        ];
    }

    /** @param array{order_token_value:string,variant_code:string,product_name:string|null,unit_price:int,quantity:int,currency_code:string} $currentItem */
    private function assertSnapshotMatchesCurrentOrderItem(PersonalizationOrderItemLink $link, array $currentItem): void
    {
        if (
            null === $link->getOrderTokenValue()
            || null === $link->getVariantCode()
            || null === $link->getUnitPrice()
            || null === $link->getQuantity()
            || null === $link->getCurrencyCode()
        ) {
            throw new \RuntimeException('The personalization cart item snapshot is missing.');
        }

        $sameSnapshot = $link->getOrderTokenValue() === $currentItem['order_token_value']
            && $link->getVariantCode() === $currentItem['variant_code']
            && (null === $link->getProductName() || $link->getProductName() === $currentItem['product_name'])
            && $link->getUnitPrice() === $currentItem['unit_price']
            && $link->getQuantity() === $currentItem['quantity']
            && $link->getCurrencyCode() === mb_strtoupper($currentItem['currency_code']);

        if (!$sameSnapshot) {
            throw new \RuntimeException('The personalized cart item changed after approval. The preview must be approved again before payment.');
        }
    }
}
