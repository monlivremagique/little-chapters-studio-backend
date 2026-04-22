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
}
