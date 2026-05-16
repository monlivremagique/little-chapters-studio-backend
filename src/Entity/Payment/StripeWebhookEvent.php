<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_stripe_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_app_stripe_webhook_event_provider_event_id', columns: ['provider_event_id'])]
class StripeWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'provider_event_id', type: 'string', length: 255)]
    private string $providerEventId;

    #[ORM\Column(name: 'type', type: 'string', length: 191)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $providerEventId, string $type, array $payload)
    {
        $this->providerEventId = $providerEventId;
        $this->type = $type;
        $this->payload = $payload;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderEventId(): string
    {
        return $this->providerEventId;
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

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
