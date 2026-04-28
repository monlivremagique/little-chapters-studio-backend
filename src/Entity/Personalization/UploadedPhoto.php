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

    #[ORM\Column(name: 'mime_type', length: 255)]
    private string $mimeType;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize;

    #[ORM\Column(name: 'public_path', length: 255)]
    private string $publicPath;

    #[ORM\Column(name: 'storage_path', length: 255, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(name: 'access_token', length: 128, nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(name: 'image_width', nullable: true)]
    private ?int $imageWidth = null;

    #[ORM\Column(name: 'image_height', nullable: true)]
    private ?int $imageHeight = null;

    #[ORM\Column(name: 'sha256_checksum', length: 64, nullable: true)]
    private ?string $sha256Checksum = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'deleted_reason', length: 64, nullable: true)]
    private ?string $deletedReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PersonalizationSession $session,
        string $originalFilename,
        string $storedFilename,
        string $mimeType,
        int $fileSize,
        string $publicPath,
        ?string $storagePath = null,
        ?string $accessToken = null,
        ?int $imageWidth = null,
        ?int $imageHeight = null,
        ?string $sha256Checksum = null,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->session = $session;
        $this->status = UploadedPhotoStatus::Uploaded;
        $this->originalFilename = $originalFilename;
        $this->storedFilename = $storedFilename;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->publicPath = $publicPath;
        $this->storagePath = $storagePath;
        $this->accessToken = $accessToken;
        $this->imageWidth = $imageWidth;
        $this->imageHeight = $imageHeight;
        $this->sha256Checksum = $sha256Checksum;
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

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getPublicPath(): string
    {
        return $this->publicPath;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    public function getSha256Checksum(): ?string
    {
        return $this->sha256Checksum;
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
        $this->deletedReason = trim($reason) !== '' ? trim($reason) : 'deleted_by_user';
    }

    public function replaceAccessPath(string $publicPath): void
    {
        $this->publicPath = $publicPath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
