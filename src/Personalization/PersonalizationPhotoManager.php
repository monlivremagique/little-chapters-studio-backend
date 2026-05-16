<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\UploadedPhoto;
use App\Service\EncryptionService;
use App\Service\PhotoOptimizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class PersonalizationPhotoManager
{
    private const int MAX_FILE_SIZE_BYTES = 10_000_000;
    private const int MIN_WIDTH = 256;
    private const int MIN_HEIGHT = 256;
    private const int MAX_WIDTH = 4096;
    private const int MAX_HEIGHT = 4096;

    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    private const string ENCRYPTION_CONTEXT_PHOTO = 'child_photo';
    private const string STORAGE_DIR = 'var/storage/personalizations/photos';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly PhotoOptimizer $photoOptimizer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(PHOTO_STORAGE_MAX_RETENTION_DAYS)%')]
        private readonly int $maxRetentionDays = 30,
    ) {
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

    public function createStoredPhoto(PersonalizationSession $session, UploadedFile $uploadedFile): UploadedPhoto
    {
        $realPath = $uploadedFile->getRealPath();
        if (false === $realPath) {
            throw new \RuntimeException('Uploaded file has no real path.');
        }

        $metadata = $this->validateUploadedPhoto($uploadedFile);

        $optimized = $this->photoOptimizer->optimize($realPath);
        $optimizedContent = $optimized['content'];

        $encrypted = $this->encryptionService->encrypt($optimizedContent, self::ENCRYPTION_CONTEXT_PHOTO);

        $storedFilename = sprintf('%s-%s.enc', $session->getId(), strtolower(Uuid::v7()->toBase32()));
        $storageDirectory = $this->getStorageDirectory();

        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0750, true);
        }

        $storagePath = sprintf('%s/%s', $storageDirectory, $storedFilename);
        $written = @file_put_contents($storagePath, $encrypted, LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException('Failed to write encrypted photo to private storage.');
        }

        chmod($storagePath, 0640);

        $relativePath = sprintf('%s/%s', self::STORAGE_DIR, $storedFilename);
        $encryptedSha256 = hash('sha256', $encrypted);

        $photo = new UploadedPhoto(
            $session,
            $uploadedFile->getClientOriginalName(),
            $storedFilename,
            'image/jpeg',
            $optimized['width'],
            $optimized['height'],
            $optimized['originalWidth'],
            $optimized['originalHeight'],
            filesize($storagePath) ?: 0,
            $relativePath,
            $encryptedSha256,
        );

        return $photo;
    }

    public function decryptPhotoContent(UploadedPhoto $photo): string
    {
        $storagePath = $this->resolveStoredPhotoPath($photo);
        if (null === $storagePath || !is_file($storagePath)) {
            throw new \RuntimeException('Encrypted photo file not found in private storage.');
        }

        $encrypted = @file_get_contents($storagePath);
        if (false === $encrypted) {
            throw new \RuntimeException('Failed to read encrypted photo from private storage.');
        }

        return $this->encryptionService->decrypt($encrypted, self::ENCRYPTION_CONTEXT_PHOTO);
    }

    public function deleteStoredPhoto(UploadedPhoto $photo, string $reason = 'deleted_by_user'): void
    {
        $filePath = $this->resolveStoredPhotoPath($photo);

        if (null !== $filePath && is_file($filePath)) {
            $written = @file_put_contents($filePath, random_bytes(filesize($filePath)));
            if (false !== $written) {
                @unlink($filePath);
            } else {
                @unlink($filePath);
            }
        }

        if (!$photo->isDeleted()) {
            $photo->markDeleted($reason);
        }
    }

    public function hardPurgePhoto(UploadedPhoto $photo): void
    {
        $this->deleteStoredPhoto($photo, 'hard_purge');

        $session = $photo->getSession();
        $session->getPhotos()->removeElement($photo);
        $this->entityManager->remove($photo);
    }

    public function resolveStoredPhotoPath(UploadedPhoto $photo): ?string
    {
        $storagePath = $photo->getStoragePath();

        if (null === $storagePath || '' === trim($storagePath)) {
            return null;
        }

        return $this->projectDir . '/' . ltrim($storagePath, '/');
    }

    public function isAccessTokenValid(UploadedPhoto $photo, ?string $token): bool
    {
        $expectedToken = trim((string) $photo->getAccessToken());
        $providedToken = trim((string) $token);

        return '' !== $expectedToken && '' !== $providedToken && hash_equals($expectedToken, $providedToken);
    }

    /** @return array{sessionId:string, photoCount:int, purgedCount:int} */
    public function purgeExpiredPhotos(\DateTimeImmutable $deadline): array
    {
        $expired = $this->entityManager->getRepository(UploadedPhoto::class)->createQueryBuilder('photo')
            ->where('photo.deletedAt IS NOT NULL')
            ->andWhere('photo.deletedAt <= :deadline')
            ->orWhere('(photo.deletedAt IS NULL AND photo.createdAt <= :retentionDeadline)')
            ->setParameter('deadline', $deadline)
            ->setParameter('retentionDeadline', $deadline)
            ->orderBy('photo.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $purgedCount = 0;
        $sessionIds = [];

        foreach ($expired as $photo) {
            if (!$photo instanceof UploadedPhoto) {
                continue;
            }

            $sessionIds[] = $photo->getSession()->getId();
            $this->hardPurgePhoto($photo);
            ++$purgedCount;
        }

        if ($purgedCount > 0) {
            $this->entityManager->flush();
        }

        return [
            'sessionId' => array_unique($sessionIds),
            'photoCount' => count($expired),
            'purgedCount' => $purgedCount,
        ];
    }

    public function getMaxRetentionDays(): int
    {
        return max(1, $this->maxRetentionDays);
    }

    public function getStorageDirectory(): string
    {
        return $this->projectDir . '/' . self::STORAGE_DIR;
    }
}
