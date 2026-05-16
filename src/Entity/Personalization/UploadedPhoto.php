<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'app_uploaded_photo')]
class UploadedPhoto
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\Column(length: 32, enumType: UploadedPhotoStatus::class)]
    private UploadedPhotoStatus $status;

    #[ORM\Column(name: 'original_filename', length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'stored_filename', length: 255)]
    private string $storedFilename;

    #[ORM\Column(name: 'mime_type', length: 64, options: ['default' => 'image/jpeg'])]
    private string $mimeType;

    #[ORM\Column(name: 'optimized_width')]
    private int $optimizedWidth;

    #[ORM\Column(name: 'optimized_height')]
    private int $optimizedHeight;

    #[ORM\Column(name: 'original_width', nullable: true)]
    private ?int $originalWidth = null;

    #[ORM\Column(name: 'original_height', nullable: true)]
    private ?int $originalHeight = null;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize;

    #[ORM\Column(name: 'storage_path', length: 512, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(name: 'file_hash', length: 64, nullable: true)]
    private ?string $fileHash = null;

    #[ORM\Column(name: 'access_token', length: 64, unique: true)]
    private string $accessToken;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'deleted_reason', length: 64, nullable: true)]
    private ?string $deletedReason = null;

    public function __construct(
        PersonalizationSession $session,
        string $originalFilename,
        string $storedFilename,
        string $mimeType,
        int $optimizedWidth,
        int $optimizedHeight,
        ?int $originalWidth = null,
        ?int $originalHeight = null,
        ?int $fileSize = null,
        ?string $storagePath = null,
        ?string $fileHash = null,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->session = $session;
        $this->status = UploadedPhotoStatus::Uploaded;
        $this->originalFilename = $originalFilename;
        $this->storedFilename = $storedFilename;
        $this->mimeType = '' !== trim($mimeType) ? trim($mimeType) : 'image/jpeg';
        $this->optimizedWidth = max(1, $optimizedWidth);
        $this->optimizedHeight = max(1, $optimizedHeight);
        $this->originalWidth = $originalWidth;
        $this->originalHeight = $originalHeight;
        $this->fileSize = max(0, $fileSize ?? 0);
        $this->storagePath = $storagePath;
        $this->fileHash = $fileHash;
        $this->accessToken = strtolower(Uuid::v7()->toBase32());
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getStatus(): UploadedPhotoStatus
    {
        return $this->status;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOptimizedWidth(): int
    {
        return $this->optimizedWidth;
    }

    public function getOptimizedHeight(): int
    {
        return $this->optimizedHeight;
    }

    public function getOriginalWidth(): ?int
    {
        return $this->originalWidth;
    }

    public function getOriginalHeight(): ?int
    {
        return $this->originalHeight;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDeletedReason(): ?string
    {
        return $this->deletedReason;
    }

    public function isDeleted(): bool
    {
        return $this->status === UploadedPhotoStatus::Deleted || null !== $this->deletedAt;
    }

    public function markDeleted(string $reason = 'deleted_by_user', ?\DateTimeImmutable $deletedAt = null): void
    {
        $this->status = UploadedPhotoStatus::Deleted;
        $this->deletedAt = $deletedAt ?? new \DateTimeImmutable();
        $this->deletedReason = '' !== trim($reason) ? trim($reason) : 'deleted_by_user';
    }
}
