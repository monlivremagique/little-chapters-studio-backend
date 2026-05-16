<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_stripe_pending_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_pending_webhook_event_provider_event_id', columns: ['provider_event_id'])]
#[ORM\Index(name: 'idx_stripe_pending_webhook_event_provider_session_id', columns: ['provider_session_id'])]
class StripePendingWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'provider_event_id', length: 255)]
    private string $providerEventId;

    #[ORM\Column(name: 'provider_session_id', length: 255)]
    private string $providerSessionId;

    #[ORM\Column(name: 'type', length: 191)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload;

    #[ORM\Column(length: 32)]
    private string $status = 'pending';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** @param array<string, mixed> $payload */
    public function __construct(string $providerEventId, string $providerSessionId, string $type, array $payload)
    {
        $this->providerEventId = trim($providerEventId);
        $this->providerSessionId = trim($providerSessionId);
        $this->type = trim($type);
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getProviderEventId(): string
    {
        return $this->providerEventId;
    }

    public function getProviderSessionId(): string
    {
        return $this->providerSessionId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function markProcessed(): void
    {
        $this->status = 'processed';
        $this->processedAt = new \DateTimeImmutable();
    }
}
