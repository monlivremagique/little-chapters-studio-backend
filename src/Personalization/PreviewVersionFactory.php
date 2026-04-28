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
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
    ) {
    }

    public function createApprovedVersion(PersonalizationSession $session, PersonalizationGenerationJob $generationJob): PreviewVersion
    {
        /** @var PreviewVersion|null $latestVersion */
        $latestVersion = $this->entityManager->getRepository(PreviewVersion::class)->findOneBy(
            ['session' => $session],
            ['versionNumber' => 'DESC', 'id' => 'DESC'],
        );
        $versionNumber = (null !== $latestVersion ? $latestVersion->getVersionNumber() : 0) + 1;

        $payload = $this->buildSnapshotPayload($session, $generationJob);
        $version = new PreviewVersion(
            $session,
            $generationJob,
            $versionNumber,
            (string) $session->getChildName(),
            $session->getDedication(),
            $payload,
        );

        $this->entityManager->persist($version);

        return $version;
    }

    public function findLatestApprovedVersion(PersonalizationSession $session): ?PreviewVersion
    {
        /** @var PreviewVersion|null $version */
        $version = $this->entityManager->getRepository(PreviewVersion::class)->findOneBy(
            ['session' => $session],
            ['versionNumber' => 'DESC', 'id' => 'DESC'],
        );

        return $version;
    }

    /** @return array<string, mixed> */
    private function buildSnapshotPayload(PersonalizationSession $session, PersonalizationGenerationJob $generationJob): array
    {
        $book = $this->getBookBySession($session);
        $blueprint = is_array($book['bookBlueprint'] ?? null) ? $book['bookBlueprint'] : [];
        $blueprintPages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];
        $compiledBookTitle = $this->replacePlaceholders(
            (string) ($blueprint['title_template'] ?? ($book['title'] ?? 'Livre personnalise')),
            (string) $session->getChildName(),
        );
        $artifactsByPage = [];

        foreach ($this->findPreviewArtifacts($generationJob) as $artifact) {
            $artifactsByPage[$artifact->getPageNumber()] = $artifact;
        }

        $pages = [];

        foreach ($blueprintPages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageNumber = (int) ($page['page_number'] ?? $page['pageNumber'] ?? ($index + 1));
            $pageType = (string) ($page['type'] ?? 'story');
            $compiledTitle = $this->replacePlaceholders((string) ($page['title_template'] ?? ''), (string) $session->getChildName());
            $compiledText = $this->compilePageText($page, $session);
            $artifact = $artifactsByPage[$pageNumber] ?? null;
            $defaultImagePath = trim((string) ($page['default_image_path'] ?? ''));
            $label = $compiledTitle !== ''
                ? $compiledTitle
                : ($compiledText !== '' ? $compiledText : ucfirst((string) ($page['id'] ?? 'Page')));

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
                'id' => (string) ($page['id'] ?? sprintf('page_%d', $pageNumber)),
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
        foreach ($this->frontCatalogMetadata->books() as $slug => $metadata) {
            if (($metadata['id'] ?? null) === $session->getBookId()) {
                return $this->frontCatalogProvider->getBookBySlug($slug);
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

        return $this->replacePlaceholders((string) ($page['text_template'] ?? ''), (string) $session->getChildName());
    }

    private function replacePlaceholders(string $template, string $childName): string
    {
        return str_replace('{child_name}', trim($childName) !== '' ? trim($childName) : 'votre enfant', trim($template));
    }
}
