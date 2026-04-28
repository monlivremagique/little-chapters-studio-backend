<?php

declare(strict_types=1);

namespace App\Entity\Fulfillment;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_fulfillment_webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_fulfillment_webhook_event_key', columns: ['event_key'])]
class FulfillmentWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_key', length: 128)]
    private string $eventKey;

    #[ORM\Column(name: 'provider_name', length: 32)]
    private string $providerName;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload', type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(string $eventKey, string $providerName, array $payload)
    {
        $this->eventKey = trim($eventKey);
        $this->providerName = trim($providerName);
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }
}
