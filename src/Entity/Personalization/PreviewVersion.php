<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_preview_version')]
#[ORM\UniqueConstraint(name: 'uniq_preview_version_session_version', columns: ['personalization_session_id', 'version_number'])]
class PreviewVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\ManyToOne(targetEntity: PersonalizationGenerationJob::class)]
    #[ORM\JoinColumn(name: 'generation_job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationGenerationJob $generationJob;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

    #[ORM\Column(name: 'child_name', length: 255)]
    private string $childName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dedication;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'snapshot_payload', type: 'json')]
    private array $snapshotPayload;

    #[ORM\Column(name: 'content_hash', length: 64)]
    private string $contentHash;

    #[ORM\Column(name: 'approved_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $approvedAt;

    /**
     * @param array<string, mixed> $snapshotPayload
     */
    public function __construct(
        PersonalizationSession $session,
        PersonalizationGenerationJob $generationJob,
        int $versionNumber,
        string $childName,
        ?string $dedication,
        array $snapshotPayload,
    ) {
        $this->session = $session;
        $this->generationJob = $generationJob;
        $this->versionNumber = max(1, $versionNumber);
        $this->childName = trim($childName);
        $this->dedication = null !== $dedication ? trim($dedication) : null;
        $this->snapshotPayload = $snapshotPayload;
        $this->contentHash = hash('sha256', json_encode($snapshotPayload, JSON_THROW_ON_ERROR));
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getGenerationJob(): PersonalizationGenerationJob
    {
        return $this->generationJob;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getChildName(): string
    {
        return $this->childName;
    }

    public function getDedication(): ?string
    {
        return $this->dedication;
    }

    /** @return array<string, mixed> */
    public function getSnapshotPayload(): array
    {
        return $this->snapshotPayload;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getApprovedAt(): \DateTimeImmutable
    {
        return $this->approvedAt;
    }
}
