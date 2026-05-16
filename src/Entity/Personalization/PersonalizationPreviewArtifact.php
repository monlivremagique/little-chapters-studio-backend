<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_preview_artifact')]
class PersonalizationPreviewArtifact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\ManyToOne(targetEntity: PersonalizationGenerationJob::class, inversedBy: 'artifacts')]
    #[ORM\JoinColumn(name: 'generation_job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationGenerationJob $generationJob;

    #[ORM\Column(name: 'page_number')]
    private int $pageNumber;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(name: 'is_personalized')]
    private bool $isPersonalized;

    #[ORM\Column(name: 'public_path', length: 255)]
    private string $publicPath;

    #[ORM\Column(name: 'mime_type', length: 64)]
    private string $mimeType;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PersonalizationSession $session,
        PersonalizationGenerationJob $generationJob,
        int $pageNumber,
        string $label,
        bool $isPersonalized,
        string $publicPath,
        string $mimeType = 'image/svg+xml',
    ) {
        $this->session = $session;
        $this->generationJob = $generationJob;
        $this->pageNumber = $pageNumber;
        $this->label = trim($label);
        $this->isPersonalized = $isPersonalized;
        $this->publicPath = $publicPath;
        $this->mimeType = $mimeType;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGenerationJob(): PersonalizationGenerationJob
    {
        return $this->generationJob;
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isPersonalized(): bool
    {
        return $this->isPersonalized;
    }

    public function getPublicPath(): string
    {
        return $this->publicPath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
