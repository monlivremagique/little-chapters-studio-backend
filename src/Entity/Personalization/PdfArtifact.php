<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_pdf_artifact')]
#[ORM\UniqueConstraint(name: 'uniq_pdf_preview_version', columns: ['preview_version_id'])]
class PdfArtifact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PersonalizationSession::class)]
    #[ORM\JoinColumn(name: 'personalization_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonalizationSession $session;

    #[ORM\ManyToOne(targetEntity: PreviewVersion::class)]
    #[ORM\JoinColumn(name: 'preview_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PreviewVersion $previewVersion;

    #[ORM\Column(length: 32)]
    private string $status = 'ready';

    #[ORM\Column(name: 'storage_path', length: 255)]
    private string $storagePath;

    #[ORM\Column(name: 'public_path', length: 255)]
    private string $publicPath;

    #[ORM\Column(name: 'access_token', length: 64, unique: true)]
    private string $accessToken;

    #[ORM\Column(name: 'file_hash', length: 64)]
    private string $fileHash;

    #[ORM\Column(name: 'file_size')]
    private int $fileSize;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        PersonalizationSession $session,
        PreviewVersion $previewVersion,
        string $storagePath,
        string $publicPath,
        string $accessToken,
        string $fileHash,
        int $fileSize,
    ) {
        $this->session = $session;
        $this->previewVersion = $previewVersion;
        $this->storagePath = trim($storagePath);
        $this->publicPath = trim($publicPath);
        $this->accessToken = trim($accessToken);
        $this->fileHash = trim($fileHash);
        $this->fileSize = max(0, $fileSize);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): PersonalizationSession
    {
        return $this->session;
    }

    public function getPreviewVersion(): PreviewVersion
    {
        return $this->previewVersion;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getPublicPath(): string
    {
        return $this->publicPath;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
