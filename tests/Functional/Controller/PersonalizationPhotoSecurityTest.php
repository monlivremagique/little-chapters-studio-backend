<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\UploadedPhoto;
use App\Personalization\PersonalizationPhotoManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PersonalizationPhotoSecurityTest extends WebTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }

        $this->temporaryFiles = [];
        parent::tearDown();
    }

    public function testValidPhotoUploadIsStoredPrivatelyAndReadableOnlyWithToken(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $photoFile = $this->createTemporaryJpeg(640, 640);

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($photoFile, 'child.jpg', 'image/jpeg', null, true),
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($payload['childPhoto'] ?? null);
        self::assertStringContainsString('/api/personalization/photos/', (string) $payload['childPhoto']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $storedPhoto = $session->getLatestPhoto();

        self::assertInstanceOf(UploadedPhoto::class, $storedPhoto);
        self::assertNotNull($storedPhoto->getStoragePath());
        self::assertFileExists(dirname(__DIR__, 3) . '/' . ltrim((string) $storedPhoto->getStoragePath(), '/'));
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/public/uploads/personalizations/' . $storedPhoto->getStoredFilename());

        $url = parse_url((string) $payload['childPhoto']);
        self::assertIsArray($url);
        self::assertArrayHasKey('path', $url);
        self::assertArrayHasKey('query', $url);

        $client->request('GET', (string) $url['path']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('%s?%s', $url['path'], $url['query']));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/', (string) $client->getResponse()->headers->get('content-type'));
    }

    public function testNonImageUploadIsRejected(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $textFile = $this->createTemporaryTextFile();

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($textFile, 'notes.txt', 'text/plain', null, true),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('not a valid image', (string) $client->getResponse()->getContent());
    }

    public function testOversizedImageUploadIsRejected(): void
    {
        $largeImage = $this->createOversizedPng();
        /** @var PersonalizationPhotoManager $photoManager */
        $photoManager = static::getContainer()->get(PersonalizationPhotoManager::class);
        $uploadedFile = new UploadedFile($largeImage, 'too-large.png', 'image/png', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('10 MB limit');
        $photoManager->validateUploadedPhoto($uploadedFile);
    }

    public function testTooSmallImageUploadIsRejected(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $smallImage = $this->createTemporaryJpeg(128, 128);

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($smallImage, 'too-small.jpg', 'image/jpeg', null, true),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Minimum dimensions are 256x256', (string) $client->getResponse()->getContent());
    }

    public function testReplacingPhotoSoftDeletesPreviousUploadWithReason(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $firstPhoto = $this->createTemporaryJpeg(640, 640);
        $secondPhoto = $this->createTemporaryJpeg(800, 800);

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($firstPhoto, 'first.jpg', 'image/jpeg', null, true),
        ]);
        self::assertResponseStatusCodeSame(201);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $initialPhoto = $session->getLatestPhoto();
        self::assertInstanceOf(UploadedPhoto::class, $initialPhoto);
        $initialPhotoId = $initialPhoto->getId();

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($secondPhoto, 'second.jpg', 'image/jpeg', null, true),
        ]);
        self::assertResponseStatusCodeSame(201);

        $entityManager->clear();

        /** @var UploadedPhoto $replacedPhoto */
        $replacedPhoto = $entityManager->getRepository(UploadedPhoto::class)->find($initialPhotoId);
        self::assertInstanceOf(UploadedPhoto::class, $replacedPhoto);
        self::assertTrue($replacedPhoto->isDeleted());
        self::assertSame('replaced_by_new_upload', $replacedPhoto->getDeletedReason());
    }

    public function testDeletingPhotoRemovesBinaryAccess(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $photoFile = $this->createTemporaryJpeg(512, 512);

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($photoFile, 'child.jpg', 'image/jpeg', null, true),
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $url = parse_url((string) $payload['childPhoto']);
        self::assertIsArray($url);

        $client->request('DELETE', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);
        self::assertResponseIsSuccessful();
        $deletePayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($deletePayload['childPhoto']);

        $client->request('GET', sprintf('%s?%s', (string) $url['path'], (string) $url['query']));
        self::assertResponseStatusCodeSame(404);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $deletedPhoto = $session->getPhotos()->first();

        self::assertInstanceOf(UploadedPhoto::class, $deletedPhoto);
        self::assertTrue($deletedPhoto->isDeleted());
        self::assertSame('deleted_by_user', $deletedPhoto->getDeletedReason());
    }

    public function testCleanupCommandPurgesSoftDeletedPhotosPastGracePeriod(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $photoFile = $this->createTemporaryJpeg(512, 512);

        $client->request('POST', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], files: [
            'photo' => new UploadedFile($photoFile, 'cleanup.jpg', 'image/jpeg', null, true),
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->request('DELETE', sprintf('/api/personalization/sessions/%s/photo', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $deletedPhoto = $session->getPhotos()->first();
        self::assertInstanceOf(UploadedPhoto::class, $deletedPhoto);

        $deletedPhoto->markDeleted('deleted_by_user', new \DateTimeImmutable('-10 days'));
        $entityManager->flush();
        $deletedPhotoId = $deletedPhoto->getId();

        $application = new Application(static::$kernel);
        $command = $application->find('app:cleanup-personalization-photos');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--deleted-grace-days' => '7',
        ]);

        self::assertStringContainsString('Purged 1 soft-deleted personalization photo record(s)', $commandTester->getDisplay());

        $entityManager->clear();
        self::assertNull($entityManager->getRepository(UploadedPhoto::class)->find($deletedPhotoId));
    }

    /**
     * @return array{id:string, ownerToken:string}
     */
    private function createSession(object $client): array
    {
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
            'bookLocale' => 'fr',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $payload['id'],
            'ownerToken' => (string) $payload['ownerToken'],
        ];
    }

    private function createTemporaryJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 242, 213, 191);
        imagefill($image, 0, 0, $background);

        $path = tempnam(sys_get_temp_dir(), 'lc-photo-');
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function createOversizedPng(): string
    {
        $width = 2200;
        $height = 2200;
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 1) {
            for ($y = 0; $y < $height; $y += 1) {
                $color = imagecolorallocate($image, ($x * 13 + $y * 7) % 255, ($x * 3 + $y * 17) % 255, ($x * 19 + $y * 11) % 255);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'lc-photo-large-');
        imagepng($image, $path, 0);
        imagedestroy($image);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function createTemporaryTextFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lc-note-');
        file_put_contents($path, 'not an image');
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
