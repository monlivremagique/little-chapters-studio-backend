<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_generation_job')]
class PersonalizationGenerationJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\Column(length: 32, enumType: PersonalizationGenerationJobStatus::class)]
    private PersonalizationGenerationJobStatus $status;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'provider_job_id', length: 255, nullable: true)]
    private ?string $providerJobId = null;

    #[ORM\Column(name: 'provider_status', length: 64, nullable: true)]
    private ?string $providerStatus = null;

    #[ORM\Column(name: 'model_reference', length: 255, nullable: true)]
    private ?string $modelReference = null;

    #[ORM\Column(name: 'attempt_number')]
    private int $attemptNumber = 1;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'request_payload', type: 'json')]
    private array $requestPayload = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'response_payload', type: 'json', nullable: true)]
    private ?array $responsePayload = null;

    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'last_polled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /** @var Collection<int, PersonalizationPreviewArtifact> */
    #[ORM\OneToMany(mappedBy: 'generationJob', targetEntity: PersonalizationPreviewArtifact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['pageNumber' => 'ASC'])]
    private Collection $artifacts;

    /**
     * @param array<string, mixed> $requestPayload
     */
    public function __construct(
        PersonalizationSession $session,
        string $provider,
        int $attemptNumber = 1,
        ?string $modelReference = null,
        array $requestPayload = [],
    )
    {
        $this->session = $session;
        $this->provider = trim($provider);
        $this->attemptNumber = max(1, $attemptNumber);
        $this->modelReference = null !== $modelReference ? trim($modelReference) : null;
        $this->requestPayload = $requestPayload;
        $this->status = PersonalizationGenerationJobStatus::Queued;
        $this->requestedAt = new \DateTimeImmutable();
        $this->artifacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getStatus(): PersonalizationGenerationJobStatus
    {
        return $this->status;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderJobId(): ?string
    {
        return $this->providerJobId;
    }

    public function getProviderStatus(): ?string
    {
        return $this->providerStatus;
    }

    public function getModelReference(): ?string
    {
        return $this->modelReference;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
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

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getLastPolledAt(): ?\DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** @return Collection<int, PersonalizationPreviewArtifact> */
    public function getArtifacts(): Collection
    {
        return $this->artifacts;
    }

    public function queue(?string $providerJobId = null, ?string $providerStatus = null): void
    {
        $this->status = PersonalizationGenerationJobStatus::Queued;
        $this->providerJobId = null !== $providerJobId ? trim($providerJobId) : null;
        $this->providerStatus = null !== $providerStatus ? trim($providerStatus) : null;
        $this->startedAt = null;
        $this->completedAt = null;
        $this->lastPolledAt = null;
        $this->errorMessage = null;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function start(?string $providerJobId = null, ?string $providerStatus = null, ?array $responsePayload = null): void
    {
        $this->status = PersonalizationGenerationJobStatus::Processing;
        $this->providerJobId = null !== $providerJobId ? trim($providerJobId) : $this->providerJobId;
        $this->providerStatus = null !== $providerStatus ? trim($providerStatus) : $this->providerStatus;
        $this->responsePayload = $responsePayload;
        $this->startedAt = $this->startedAt ?? new \DateTimeImmutable();
        $this->lastPolledAt = $this->startedAt;
        $this->completedAt = null;
        $this->errorMessage = null;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function recordProviderState(?string $providerStatus = null, ?array $responsePayload = null): void
    {
        $this->providerStatus = null !== $providerStatus ? trim($providerStatus) : $this->providerStatus;
        $this->responsePayload = $responsePayload ?? $this->responsePayload;
        $this->lastPolledAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function complete(?string $providerStatus = null, ?array $responsePayload = null): void
    {
        $this->status = PersonalizationGenerationJobStatus::Completed;
        $this->providerStatus = null !== $providerStatus ? trim($providerStatus) : $this->providerStatus;
        $this->responsePayload = $responsePayload ?? $this->responsePayload;
        $this->completedAt = new \DateTimeImmutable();
        $this->lastPolledAt = $this->completedAt;
        $this->errorMessage = null;
    }

    /** @param array<string, mixed>|null $responsePayload */
    public function fail(string $message, ?string $providerStatus = null, ?array $responsePayload = null): void
    {
        $this->status = PersonalizationGenerationJobStatus::Failed;
        $this->providerStatus = null !== $providerStatus ? trim($providerStatus) : $this->providerStatus;
        $this->responsePayload = $responsePayload ?? $this->responsePayload;
        $this->completedAt = new \DateTimeImmutable();
        $this->lastPolledAt = $this->completedAt;
        $this->errorMessage = trim($message);
    }
}
