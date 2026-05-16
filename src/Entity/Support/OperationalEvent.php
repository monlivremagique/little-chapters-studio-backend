<?php

declare(strict_types=1);

namespace App\Entity\Support;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_operational_event')]
#[ORM\Index(name: 'idx_operational_event_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_operational_event_order', columns: ['order_number'])]
class OperationalEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 32)]
    private string $level;

    #[ORM\Column(name: 'session_id', length: 36, nullable: true)]
    private ?string $sessionId;

    #[ORM\Column(name: 'order_number', length: 255, nullable: true)]
    private ?string $orderNumber;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(string $type, string $level = 'info', ?string $sessionId = null, ?string $orderNumber = null, array $context = [])
    {
        $this->type = trim($type);
        $this->level = trim($level);
        $this->sessionId = null !== $sessionId ? trim($sessionId) : null;
        $this->orderNumber = null !== $orderNumber ? trim($orderNumber) : null;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
