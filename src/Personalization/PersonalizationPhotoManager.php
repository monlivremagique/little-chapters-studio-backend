<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\UploadedPhoto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class PersonalizationPhotoManager
{
    private const MAX_FILE_SIZE_BYTES = 10_000_000;
    private const MIN_WIDTH = 256;
    private const MIN_HEIGHT = 256;
    private const MAX_WIDTH = 4096;
    private const MAX_HEIGHT = 4096;

    /** @var array<string, list<string>> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function createStoredPhoto(PersonalizationSession $session, UploadedFile $uploadedFile): UploadedPhoto
    {
        $metadata = $this->validateUploadedPhoto($uploadedFile);
        $storedFilename = sprintf('%s-%s.%s', $session->getId(), strtolower(Uuid::v7()->toBase32()), $metadata['extension']);
        $storageDirectory = $this->getStorageDirectory();

        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0775, true);
        }

        $uploadedFile->move($storageDirectory, $storedFilename);

        $storagePath = sprintf('var/storage/personalizations/photos/%s', $storedFilename);
        $accessToken = strtolower(Uuid::v7()->toBase32());
        $photo = new UploadedPhoto(
            $session,
            $uploadedFile->getClientOriginalName(),
            $storedFilename,
            $metadata['mimeType'],
            $metadata['fileSize'],
            '',
            $storagePath,
            $accessToken,
            $metadata['imageWidth'],
            $metadata['imageHeight'],
            $metadata['sha256Checksum'],
        );
        $photo->replaceAccessPath(sprintf('/api/personalization/photos/%s?token=%s', $photo->getId(), $accessToken));

        return $photo;
    }

    public function deleteStoredPhoto(UploadedPhoto $photo, string $reason = 'deleted_by_user'): void
    {
        $filePath = $this->resolveStoredPhotoPath($photo);

        if (null !== $filePath && is_file($filePath)) {
            @unlink($filePath);
        }

        if (!$photo->isDeleted()) {
            $photo->markDeleted($reason);
        }
    }

    public function resolveStoredPhotoPath(UploadedPhoto $photo): ?string
    {
        $storagePath = $photo->getStoragePath();

        if (null === $storagePath || '' === trim($storagePath)) {
            return null;
        }

        return $this->projectDir . '/' . ltrim($storagePath, '/');
    }

    public function createAbsoluteAccessUrl(UploadedPhoto $photo): string
    {
        return rtrim($this->defaultUri, '/') . $photo->getPublicPath();
    }

    public function isAccessTokenValid(UploadedPhoto $photo, ?string $token): bool
    {
        $expectedToken = trim((string) $photo->getAccessToken());
        $providedToken = trim((string) $token);

        return '' !== $expectedToken && '' !== $providedToken && hash_equals($expectedToken, $providedToken);
    }

    /** @return array{mimeType:string,extension:string,fileSize:int,imageWidth:int,imageHeight:int,sha256Checksum:string} */
    public function validateUploadedPhoto(UploadedFile $uploadedFile): array
    {
        $fileSize = (int) ($uploadedFile->getSize() ?? 0);
        $realPath = $uploadedFile->getRealPath();

        if (false === $realPath || !is_file($realPath)) {
            throw new \RuntimeException('The uploaded photo could not be read from temporary storage.');
        }

        if ($fileSize <= 0) {
            $fileSize = max(0, (int) @filesize($realPath));
        }

        if ($fileSize <= 0) {
            throw new \RuntimeException('The uploaded photo is empty.');
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException('The uploaded photo exceeds the 10 MB limit.');
        }

        $imageInfo = @getimagesize($realPath);

        if (false === $imageInfo || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])) {
            throw new \RuntimeException('The uploaded file is not a valid image.');
        }

        $mimeType = strtolower(trim((string) $imageInfo['mime']));
        $allowedExtensions = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;

        if (null === $allowedExtensions) {
            throw new \RuntimeException('Only JPG, PNG and WEBP uploads are supported.');
        }

        $imageWidth = (int) $imageInfo[0];
        $imageHeight = (int) $imageInfo[1];

        if ($imageWidth < self::MIN_WIDTH || $imageHeight < self::MIN_HEIGHT) {
            throw new \RuntimeException('The uploaded photo is too small. Minimum dimensions are 256x256.');
        }

        if ($imageWidth > self::MAX_WIDTH || $imageHeight > self::MAX_HEIGHT) {
            throw new \RuntimeException('The uploaded photo is too large. Maximum dimensions are 4096x4096.');
        }

        $clientExtension = strtolower((string) ($uploadedFile->guessExtension() ?? $uploadedFile->getClientOriginalExtension() ?? ''));

        if ('' === $clientExtension || !in_array($clientExtension, $allowedExtensions, true)) {
            throw new \RuntimeException('The uploaded photo extension does not match the allowed image formats.');
        }

        $contents = @file_get_contents($realPath);

        if (false === $contents) {
            throw new \RuntimeException('The uploaded photo contents could not be read.');
        }

        return [
            'mimeType' => $mimeType,
            'extension' => $allowedExtensions[0],
            'fileSize' => $fileSize,
            'imageWidth' => $imageWidth,
            'imageHeight' => $imageHeight,
            'sha256Checksum' => hash('sha256', $contents),
        ];
    }

    private function getStorageDirectory(): string
    {
        return $this->projectDir . '/var/storage/personalizations/photos';
    }
}
