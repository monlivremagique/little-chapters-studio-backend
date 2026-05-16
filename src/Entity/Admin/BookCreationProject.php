<?php

declare(strict_types=1);

namespace App\Entity\Admin;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'book_creation_project')]
#[ORM\HasLifecycleCallbacks]
class BookCreationProject
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_VALIDATION = 'validation';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'json')]
    private array $brief;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'current_step', length: 50, nullable: true)]
    private ?string $currentStep = null;

    #[ORM\Column(name: 'progress_pct', type: 'integer')]
    private int $progressPct = 0;

    #[ORM\Column(name: 'qa_scores', type: 'json', nullable: true)]
    private ?array $qaScores = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'json')]
    private array $logs = [];

    #[ORM\Column(name: 'blueprint_path', length: 255, nullable: true)]
    private ?string $blueprintPath = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getBrief(): array { return $this->brief; }
    public function setBrief(array $brief): void { $this->brief = $brief; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getCurrentStep(): ?string { return $this->currentStep; }
    public function setCurrentStep(?string $step): void { $this->currentStep = $step; }
    public function getProgressPct(): int { return $this->progressPct; }
    public function setProgressPct(int $pct): void { $this->progressPct = min(100, max(0, $pct)); }
    public function getQaScores(): ?array { return $this->qaScores; }
    public function setQaScores(?array $scores): void { $this->qaScores = $scores; }
    public function getError(): ?string { return $this->error; }
    public function setError(?string $error): void { $this->error = $error; }
    public function getLogs(): array { return $this->logs; }
    public function setLogs(array $logs): void { $this->logs = $logs; }

    public function addLog(string $level, string $message, string $step = ''): void
    {
        $this->logs[] = [
            'step' => $step,
            'level' => $level,
            'message' => $message,
            'time' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    public function getBlueprintPath(): ?string { return $this->blueprintPath; }
    public function setBlueprintPath(?string $path): void { $this->blueprintPath = $path; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $at): void { $this->completedAt = $at; }

    public function isStatusDraft(): bool { return self::STATUS_DRAFT === $this->status; }
    public function isStatusRunning(): bool { return self::STATUS_RUNNING === $this->status; }
    public function isStatusValidation(): bool { return self::STATUS_VALIDATION === $this->status; }
    public function isStatusPublished(): bool { return self::STATUS_PUBLISHED === $this->status; }
    public function isStatusFailed(): bool { return self::STATUS_FAILED === $this->status; }
    public function canRunPipeline(): bool { return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_FAILED], true); }
}
