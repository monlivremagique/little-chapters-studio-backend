<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_order_item_link')]
#[ORM\UniqueConstraint(name: 'uniq_personalization_link_session', columns: ['personalization_session_id'])]
#[ORM\UniqueConstraint(name: 'uniq_personalization_link_order_item', columns: ['order_item_id'])]
class PersonalizationOrderItemLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\Column(name: 'order_item_id')]
    private int $orderItemId;

    #[ORM\Column(name: 'order_token_value', length: 255, nullable: true)]
    private ?string $orderTokenValue = null;

    #[ORM\Column(name: 'variant_code', length: 255, nullable: true)]
    private ?string $variantCode = null;

    #[ORM\Column(name: 'product_name', length: 255, nullable: true)]
    private ?string $productName = null;

    #[ORM\Column(name: 'unit_price', nullable: true)]
    private ?int $unitPrice = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column(name: 'currency_code', length: 3, nullable: true)]
    private ?string $currencyCode = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(PersonalizationSession $session, int $orderItemId)
    {
        $this->session = $session;
        $this->orderItemId = $orderItemId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getOrderItemId(): int
    {
        return $this->orderItemId;
    }

    public function setOrderItemId(int $orderItemId): void
    {
        $this->orderItemId = $orderItemId;
    }

    /**
     * @param array{
     *     order_item_id:int,
     *     order_token_value:string,
     *     variant_code:string,
     *     product_name:string|null,
     *     unit_price:int,
     *     quantity:int,
     *     currency_code:string
     * } $orderItem
     */
    public function snapshotOrderItem(array $orderItem): void
    {
        $this->orderItemId = $orderItem['order_item_id'];
        $this->orderTokenValue = trim($orderItem['order_token_value']);
        $this->variantCode = trim($orderItem['variant_code']);
        $this->productName = null !== $orderItem['product_name'] ? trim($orderItem['product_name']) : null;
        $this->unitPrice = $orderItem['unit_price'];
        $this->quantity = $orderItem['quantity'];
        $this->currencyCode = mb_strtoupper(trim($orderItem['currency_code']));
    }

    public function getOrderTokenValue(): ?string
    {
        return $this->orderTokenValue;
    }

    public function getVariantCode(): ?string
    {
        return $this->variantCode;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }
}
