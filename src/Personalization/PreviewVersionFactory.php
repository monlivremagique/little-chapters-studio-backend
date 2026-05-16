<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PreviewVersion;
use App\FrontCatalog\FrontCatalogMetadata;
use App\FrontCatalog\FrontCatalogProvider;
use Doctrine\ORM\EntityManagerInterface;

final class PreviewVersionFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
        private readonly FrontCatalogProvider $frontCatalogProvider,
    ) {
    }

    public function createApprovedVersion(PersonalizationSession $session, PersonalizationGenerationJob $generationJob): PreviewVersion
    {
        $existing = $this->entityManager->getRepository(PreviewVersion::class)->findOneBy([
            'session' => $session,
            'generationJob' => $generationJob,
        ]);

        if ($existing instanceof PreviewVersion) {
            return $existing;
        }

        $payload = $this->buildSnapshotPayload($session, $generationJob);

        $version = new PreviewVersion($session, $generationJob, $payload);
        $this->entityManager->persist($version);

        return $version;
    }

    public function findLatestApprovedVersion(PersonalizationSession $session): ?PreviewVersion
    {
        return $this->entityManager->getRepository(PreviewVersion::class)->findOneBy(
            ['session' => $session],
            ['createdAt' => 'DESC'],
        );
    }

    /** @return array<string, mixed> */
    private function buildSnapshotPayload(PersonalizationSession $session, PersonalizationGenerationJob $generationJob): array
    {
        $book = $this->getBookBySession($session);
        $blueprint = is_array($book['bookBlueprint'] ?? null) ? $book['bookBlueprint'] : [];
        $blueprintPages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];

        $compiledBookTitle = PronounResolver::resolve(
            (string) ($blueprint['title_template'] ?? ''),
            (string) $session->getChildName(),
            $this->resolveGender($session),
            $session->getBookLocale(),
        );

        $artifacts = $this->findPreviewArtifacts($generationJob);
        $artifactsByPage = [];
        foreach ($artifacts as $artifact) {
            $artifactsByPage[$artifact->getPageNumber()] = $artifact;
        }

        $pages = [];
        foreach ($blueprintPages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageType = (string) ($page['type'] ?? 'story');
            $pageNumber = (int) ($page['pageNumber'] ?? 0);
            $pageId = (string) ($page['id'] ?? sprintf('page_%d', $pageNumber));

            $compiledTitle = PronounResolver::resolve(
                (string) ($page['title_template'] ?? ''),
                (string) $session->getChildName(),
                $this->resolveGender($session),
                $session->getBookLocale(),
            );
            $compiledText = $this->compilePageText($page, $session);
            $artifact = $artifactsByPage[$pageNumber] ?? null;
            $defaultImagePath = trim((string) ($page['default_image_path'] ?? ''));
            $label = $compiledTitle !== ''
                ? $compiledTitle
                : ($compiledText !== '' ? $compiledText : ucfirst($pageId));

            if ('cover' === $pageType) {
                $label = 'Couverture';
            } elseif ('dedication' === $pageType) {
                $label = 'Dedicace';
            } elseif ('summary' === $pageType) {
                $label = 'Resume';
            } elseif ('backCover' === $pageType) {
                $label = 'Quatrieme de couverture';
            }

            $pages[] = [
                'id' => $pageId,
                'type' => $pageType,
                'pageNumber' => $pageNumber,
                'title' => 'cover' === $pageType ? ($compiledTitle !== '' ? $compiledTitle : $compiledBookTitle) : ($compiledTitle !== '' ? $compiledTitle : null),
                'text' => $compiledText !== '' ? $compiledText : null,
                'imageUrl' => $artifact instanceof PersonalizationPreviewArtifact
                    ? $artifact->getPublicPath()
                    : ($defaultImagePath !== '' ? $defaultImagePath : null),
                'isPersonalized' => $artifact instanceof PersonalizationPreviewArtifact,
                'label' => $artifact instanceof PersonalizationPreviewArtifact ? $artifact->getLabel() : $label,
            ];
        }

        if ([] === $pages) {
            foreach ($artifactsByPage as $pageNumber => $artifact) {
                $pages[] = [
                    'id' => sprintf('personalized_page_%d', $pageNumber),
                    'type' => 'story',
                    'pageNumber' => $pageNumber,
                    'title' => null,
                    'text' => $artifact->getLabel(),
                    'imageUrl' => $artifact->getPublicPath(),
                    'isPersonalized' => true,
                    'label' => $artifact->getLabel(),
                ];
            }
        }

        return [
            'sessionId' => $session->getId(),
            'bookId' => $session->getBookId(),
            'bookSlug' => (string) ($book['slug'] ?? ''),
            'bookTitle' => $compiledBookTitle,
            'childName' => (string) $session->getChildName(),
            'dedication' => $session->getDedication(),
            'generationJobId' => $generationJob->getId(),
            'pages' => $pages,
        ];
    }

    /** @return array<string, mixed> */
    private function getBookBySession(PersonalizationSession $session): array
    {
        $bookLocale = $session->getResolvedBookLocale();
        foreach ($this->frontCatalogMetadata->books() as $slug => $metadata) {
            if (($metadata['id'] ?? null) === $session->getBookId()) {
                return $this->frontCatalogProvider->getBookBySlug($slug, $bookLocale);
            }
        }
        foreach ($this->frontCatalogProvider->getBooks($bookLocale) as $bookCard) {
            if (($bookCard['id'] ?? null) === $session->getBookId()) {
                return $this->frontCatalogProvider->getBookBySlug((string) ($bookCard['slug'] ?? ''), $bookLocale);
            }
        }
        return ['id' => $session->getBookId(), 'slug' => '', 'title' => '', 'bookBlueprint' => ['pages' => []]];
    }

    /** @return list<PersonalizationPreviewArtifact> */
    private function findPreviewArtifacts(PersonalizationGenerationJob $generationJob): array
    {
        return $this->entityManager->getRepository(PersonalizationPreviewArtifact::class)->findBy(
            ['generationJob' => $generationJob],
            ['pageNumber' => 'ASC', 'id' => 'ASC'],
        );
    }

    /** @param array<string, mixed> $page */
    private function compilePageText(array $page, PersonalizationSession $session): string
    {
        $pageType = (string) ($page['type'] ?? 'story');
        if ('dedication' === $pageType && null !== $session->getDedication() && '' !== trim($session->getDedication())) {
            return trim($session->getDedication());
        }
        return PronounResolver::resolve(
            (string) ($page['text_template'] ?? ''),
            (string) $session->getChildName(),
            $this->resolveGender($session),
            $session->getBookLocale(),
        );
    }

    private function resolveGender(PersonalizationSession $session): string
    {
        $extraFields = $session->getExtraFields();
        $gender = trim((string) ($extraFields['childGender'] ?? ''));
        return in_array($gender, ['M', 'F'], true) ? $gender : 'neutral';
    }
}
