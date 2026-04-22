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

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PersonalizationSession $session,
        string $originalFilename,
        string $storedFilename,
        string $mimeType,
        int $fileSize,
        string $publicPath,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->session = $session;
        $this->status = UploadedPhotoStatus::Uploaded;
        $this->originalFilename = $originalFilename;
        $this->storedFilename = $storedFilename;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->publicPath = $publicPath;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
