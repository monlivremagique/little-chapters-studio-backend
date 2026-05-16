<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personalization;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\UploadedPhoto;
use App\Personalization\PersonalizationPreviewGenerator;
use App\Personalization\PreviewVersionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookLocalePropagationTest extends KernelTestCase
{
    /**
     * @dataProvider provideBookLocales
     */
    public function testGenerationAndApprovedPreviewUseResolvedBookLocale(
        ?string $requestedLocale,
        string $expectedCoverTitle,
        string $expectedStoryText,
    ): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var PersonalizationPreviewGenerator $generator */
        $generator = $container->get(PersonalizationPreviewGenerator::class);
        /** @var PreviewVersionFactory $previewVersionFactory */
        $previewVersionFactory = $container->get(PreviewVersionFactory::class);

        $session = $this->createReadySession($entityManager, $requestedLocale);
        $job = $generator->trigger($session);
        $previewVersionFactory->createApprovedVersion($session, $job);
        $entityManager->flush();

        $requestPayload = $job->getRequestPayload();
        self::assertSame($expectedCoverTitle, $this->findGenerationPageValue($requestPayload, 'cover', 'compiledTitle'));
        self::assertSame($expectedStoryText, $this->findGenerationPageValue($requestPayload, 'page_1', 'compiledText'));

        /** @var PersonalizationGenerationJob $persistedJob */
        $persistedJob = $entityManager->getRepository(PersonalizationGenerationJob::class)->find($job->getId());
        $previewVersion = $previewVersionFactory->findLatestApprovedVersion($session);
        self::assertNotNull($previewVersion);
        self::assertSame($expectedCoverTitle, $previewVersion->getSnapshotPayload()['bookTitle'] ?? null);
        self::assertSame($expectedStoryText, $this->findGenerationPageValue($persistedJob->getRequestPayload(), 'page_1', 'compiledText'));
    }

    /**
     * @return iterable<string, array{0:?string, 1:string, 2:string}>
     */
    public static function provideBookLocales(): iterable
    {
        yield 'fr locale' => ['fr', 'L Astronaute et Son Robot avec Nora', 'Nora regardait par le grand hublot de la station. Au loin, une lumiere clignotait en rouge, la balise de signal, perdue dans le noir de l espace. A cote, BLIX afficha une carte holographique et un enorme point d interrogation sur son ecran-face.'];
        yield 'nl locale' => ['nl', 'De Astronaut en Zijn Robot met Nora', 'Nora staarde door het patrijspoort van het station. In de verte knipperde een rood licht, het signaalbaken, verloren in de duisternis van de ruimte. Naast Nora toonde BLIX een holografische kaart en een enorm vraagteken op het scherm-gezicht.'];
        yield 'en locale' => ['en', 'The Astronaut and Their Robot with Nora', 'Nora gazed through the station porthole. In the distance, a red light flickered, the signal beacon, lost in the darkness of space. Beside them, BLIX displayed a holographic map and an enormous question mark on the screen-face.'];
    }

    /** @param array<string, mixed> $requestPayload */
    private function findGenerationPageValue(array $requestPayload, string $pageId, string $field): mixed
    {
        $generationPlan = is_array($requestPayload['generationPlan'] ?? null) ? $requestPayload['generationPlan'] : [];

        foreach ($generationPlan as $page) {
            if (!is_array($page) || ($page['id'] ?? null) !== $pageId) {
                continue;
            }

            return $page[$field] ?? null;
        }

        return null;
    }

    private function createReadySession(EntityManagerInterface $entityManager, string $bookLocale): PersonalizationSession
    {
        $session = new PersonalizationSession('espace-robot', sprintf('locale-token-%s', bin2hex(random_bytes(4))));
        $session->setBookLocale($bookLocale);
        $photoPath = sprintf('var/storage/personalizations/photos/%s-locale-photo.png', $session->getId());
        $absolutePhotoPath = dirname(__DIR__, 3) . '/' . $photoPath;
        $directory = dirname($absolutePhotoPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePhotoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sX8Z1AAAAAASUVORK5CYII=', true));

        $photo = new UploadedPhoto(
            $session,
            'locale-photo.png',
            basename($photoPath),
            'image/png',
            filesize($absolutePhotoPath) ?: 68,
            '/uploads/locale-photo.png',
            $photoPath,
            'locale-photo-token',
            1,
            1,
            hash_file('sha256', $absolutePhotoPath),
        );

        $session->addPhoto($photo);
        $session->saveContent('Nora', 'Pour toi', [], 3);

        $entityManager->persist($session);
        $entityManager->persist($photo);
        $entityManager->flush();

        return $session;
    }
}
