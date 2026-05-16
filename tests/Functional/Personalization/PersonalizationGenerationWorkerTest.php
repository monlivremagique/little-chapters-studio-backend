<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personalization;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Entity\Personalization\UploadedPhoto;
use App\Personalization\PersonalizationPreviewGenerator;
use App\Tests\Double\Replicate\FakeReplicatePredictionClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersonalizationGenerationWorkerTest extends KernelTestCase
{
    public function testWorkerResumesQueuedJobAcrossProcessRestartUntilPreviewReady(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var PersonalizationPreviewGenerator $generator */
        $generator = $container->get(PersonalizationPreviewGenerator::class);
        /** @var FakeReplicatePredictionClient $fakeReplicate */
        $fakeReplicate = $container->get(FakeReplicatePredictionClient::class);
        $fakeReplicate->reset();

        foreach (range(1, 6) as $pageNumber) {
            $url = sprintf('https://replicate.example.test/page-%d.png', $pageNumber);
            $fakeReplicate->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [$url]],
            ]);
            $fakeReplicate->registerDownload($url, sprintf('png-binary-%d', $pageNumber));
        }

        $session = $this->createReadySession($entityManager);
        $queuedJob = $generator->trigger($session);
        self::assertSame(PersonalizationGenerationJobStatus::Queued, $queuedJob->getStatus());

        $generator->processJob($queuedJob);

        $entityManager->clear();
        /** @var PersonalizationGenerationJob $startedJob */
        $startedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($queuedJob->getId());
        self::assertSame(PersonalizationGenerationJobStatus::Processing, $startedJob->getStatus());
        self::assertNotNull($startedJob->getProviderJobId());

        $entityManager->clear();

        for ($attempt = 0; $attempt < 12; ++$attempt) {
            /** @var PersonalizationGenerationJob $currentJob */
            $currentJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($queuedJob->getId());
            $container->get(PersonalizationPreviewGenerator::class)->processJob($currentJob);
            $entityManager->clear();
            /** @var PersonalizationGenerationJob $currentJob */
            $currentJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($queuedJob->getId());

            if ($currentJob->getStatus() === PersonalizationGenerationJobStatus::Completed) {
                break;
            }
        }

        $entityManager->clear();
        /** @var PersonalizationGenerationJob $completedJob */
        $completedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($queuedJob->getId());
        /** @var PersonalizationSession $completedSession */
        $completedSession = $entityManager->getRepository(PersonalizationSession::class)->find($session->getId());
        $artifacts = $entityManager->getRepository(PersonalizationPreviewArtifact::class)->findBy(['generationJob' => $completedJob]);
        $responsePayload = $completedJob->getResponsePayload();
        $state = is_array($responsePayload['state'] ?? null) ? $responsePayload['state'] : [];
        $expectedArtifactCount = (int) ($state['totalPageCount'] ?? 0);

        self::assertSame(PersonalizationGenerationJobStatus::Completed, $completedJob->getStatus());
        self::assertSame(PersonalizationSessionStatus::PreviewReady, $completedSession->getStatus());
        self::assertGreaterThan(0, $expectedArtifactCount);
        self::assertCount($expectedArtifactCount, $artifacts);
    }

    public function testWorkerRetriesTimedOutProviderPage(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var PersonalizationPreviewGenerator $generator */
        $generator = $container->get(PersonalizationPreviewGenerator::class);
        /** @var FakeReplicatePredictionClient $fakeReplicate */
        $fakeReplicate = $container->get(FakeReplicatePredictionClient::class);
        $fakeReplicate->reset();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'processing'],
        ]);
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
        ]);

        $session = $this->createReadySession($entityManager, 'Mila');
        $job = $generator->trigger($session);
        $generator->processJob($job);
        $entityManager->clear();

        /** @var PersonalizationGenerationJob $startedJob */
        $startedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($job->getId());
        $payload = $startedJob->getResponsePayload();
        $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
        $currentPageId = (string) ($state['currentPageId'] ?? '');
        $state['pageRuns'][$currentPageId]['requestedAt'] = (new \DateTimeImmutable('-10 minutes'))->format(DATE_ATOM);
        $startedJob->recordProviderState('processing', [
            'state' => $state,
            'prediction' => ['id' => $startedJob->getProviderJobId(), 'status' => 'processing'],
        ]);
        $entityManager->flush();

        $generator->processJob($startedJob);
        $entityManager->clear();

        /** @var PersonalizationGenerationJob $retriedJob */
        $retriedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($job->getId());
        self::assertSame(PersonalizationGenerationJobStatus::Processing, $retriedJob->getStatus());
        self::assertSame(2, count($fakeReplicate->getCreateInputs()));
        self::assertSame('fake_prediction_2', $retriedJob->getProviderJobId());
    }

    private function createReadySession(EntityManagerInterface $entityManager, string $childName = 'Nora'): PersonalizationSession
    {
        $session = new PersonalizationSession('espace-robot', sprintf('worker-token-%s', bin2hex(random_bytes(4))));
        $session->setBookLocale('fr');
        $photoPath = sprintf('var/storage/personalizations/photos/%s-test-photo.png', $session->getId());
        $absolutePhotoPath = dirname(__DIR__, 3).'/'.$photoPath;
        $directory = dirname($absolutePhotoPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePhotoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sX8Z1AAAAAASUVORK5CYII=', true));

        $photo = new UploadedPhoto(
            $session,
            'test-photo.png',
            basename($photoPath),
            'image/png',
            filesize($absolutePhotoPath) ?: 68,
            '/uploads/test-photo.png',
            $photoPath,
            'photo-access-token',
            1,
            1,
            hash_file('sha256', $absolutePhotoPath),
        );
        $session->addPhoto($photo);
        $session->saveContent($childName, 'Pour toi', [], 3);

        $entityManager->persist($session);
        $entityManager->persist($photo);
        $entityManager->flush();

        return $session;
    }
}
