<?php

declare(strict_types=1);

namespace App\Entity\Fulfillment;

use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_fulfillment_order')]
#[ORM\UniqueConstraint(name: 'uniq_fulfillment_order_session', columns: ['personalization_session_id'])]
class FulfillmentOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\ManyToOne(targetEntity: PdfArtifact::class)]
    #[ORM\JoinColumn(name: 'pdf_artifact_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PdfArtifact $pdfArtifact;

    #[ORM\Column(length: 32)]
    private string $provider = 'gelato';

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(name: 'order_number', length: 255)]
    private string $orderNumber;

    #[ORM\Column(name: 'provider_order_id', length: 255, nullable: true)]
    private ?string $providerOrderId = null;

    #[ORM\Column(name: 'tracking_url', length: 512, nullable: true)]
    private ?string $trackingUrl = null;

    #[ORM\Column(name: 'tracking_number', length: 255, nullable: true)]
    private ?string $trackingNumber = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'request_payload', type: 'json')]
    private array $requestPayload = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'response_payload', type: 'json', nullable: true)]
    private ?array $responsePayload = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $requestPayload */
    public function __construct(PersonalizationSession $session, PdfArtifact $pdfArtifact, string $orderNumber, array $requestPayload)
    {
        $this->session = $session;
        $this->pdfArtifact = $pdfArtifact;
        $this->orderNumber = trim($orderNumber);
        $this->requestPayload = $requestPayload;
        $this->status = 'pending';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getPdfArtifact(): PdfArtifact
    {
        return $this->pdfArtifact;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    /** @return array<string, mixed> */
    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    /** @return array<string, mixed>|null */
    public function getResponsePayload(): ?array
    {
        return $this->responsePayload;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<string, mixed> $responsePayload */
    public function markSubmitted(string $providerOrderId, array $responsePayload): void
    {
        $this->providerOrderId = trim($providerOrderId);
        $this->responsePayload = $responsePayload;
        $this->status = 'submitted';
        $this->errorMessage = null;
        $this->touch();
    }

    /** @param array<string, mixed> $responsePayload */
    public function markFailed(string $message, ?array $responsePayload = null): void
    {
        $this->status = 'failed';
        $this->errorMessage = trim($message);
        $this->responsePayload = $responsePayload;
        $this->touch();
    }

    /** @param array<string, mixed> $payload */
    public function applyWebhookStatus(string $status, array $payload): void
    {
        $this->status = trim($status);
        $this->responsePayload = $payload;
        $trackingUrl = $payload['trackingUrl'] ?? $payload['tracking_url'] ?? null;
        $trackingNumber = $payload['trackingNumber'] ?? $payload['tracking_number'] ?? null;
        $this->trackingUrl = null !== $trackingUrl ? trim((string) $trackingUrl) : $this->trackingUrl;
        $this->trackingNumber = null !== $trackingNumber ? trim((string) $trackingNumber) : $this->trackingNumber;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
