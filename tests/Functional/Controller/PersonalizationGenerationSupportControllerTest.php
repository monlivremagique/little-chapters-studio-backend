<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\UploadedPhoto;
use App\Personalization\PersonalizationPreviewGenerator;
use App\Tests\Double\Replicate\FakeReplicatePredictionClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonalizationGenerationSupportControllerTest extends WebTestCase
{
    public function testFailedGenerationJobIsListedAndCanBeRetried(): void
    {
        putenv('SUPPORT_OPERATIONS_TOKEN=test-support-token');
        $_ENV['SUPPORT_OPERATIONS_TOKEN'] = 'test-support-token';
        $_SERVER['SUPPORT_OPERATIONS_TOKEN'] = 'test-support-token';

        $client = static::createClient();
        $client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationPreviewGenerator $generator */
        $generator = static::getContainer()->get(PersonalizationPreviewGenerator::class);
        /** @var FakeReplicatePredictionClient $fakeReplicate */
        $fakeReplicate = static::getContainer()->get(FakeReplicatePredictionClient::class);
        $fakeReplicate->reset();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'failed', 'error' => 'provider exploded'],
        ]);
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
        ]);

        $session = $this->createReadySession($entityManager);
        $job = $generator->trigger($session);
        $generator->processJob($job);
        $entityManager->clear();
        /** @var PersonalizationGenerationJob $processingJob */
        $processingJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($job->getId());
        $generator = static::getContainer()->get(PersonalizationPreviewGenerator::class);
        $generator->processJob($processingJob);
        $entityManager->clear();

        /** @var PersonalizationGenerationJob $failedJob */
        $failedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($job->getId());
        self::assertSame(PersonalizationGenerationJobStatus::Failed, $failedJob->getStatus());

        $client->request('GET', '/api/custom/support/personalization/generation-jobs?failedOnly=1', server: [
            'HTTP_X_SUPPORT_TOKEN' => 'test-support-token',
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload);
        self::assertSame($failedJob->getId(), $payload[0]['id']);

        $client->request('POST', sprintf('/api/custom/support/personalization/generation-jobs/%d/retry', $failedJob->getId()), server: [
            'HTTP_X_SUPPORT_TOKEN' => 'test-support-token',
        ]);
        self::assertResponseIsSuccessful();
        $retryPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $retryPayload['status']);
        self::assertSame(2, $retryPayload['attemptNumber']);
    }

    private function createReadySession(EntityManagerInterface $entityManager): PersonalizationSession
    {
        $session = new PersonalizationSession('b1', sprintf('support-token-%s', bin2hex(random_bytes(4))));
        $photoPath = sprintf('var/storage/personalizations/photos/%s-support-photo.png', $session->getId());
        $absolutePhotoPath = dirname(__DIR__, 3).'/'.$photoPath;
        $directory = dirname($absolutePhotoPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePhotoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sX8Z1AAAAAASUVORK5CYII=', true));
        $photo = new UploadedPhoto(
            $session,
            'support-photo.png',
            basename($photoPath),
            'image/png',
            filesize($absolutePhotoPath) ?: 68,
            '/uploads/support-photo.png',
            $photoPath,
            'support-photo-token',
            1,
            1,
            hash_file('sha256', $absolutePhotoPath),
        );
        $session->addPhoto($photo);
        $session->saveContent('Lina', 'Pour toi', [], 3);

        $entityManager->persist($session);
        $entityManager->persist($photo);
        $entityManager->flush();

        return $session;
    }
}
