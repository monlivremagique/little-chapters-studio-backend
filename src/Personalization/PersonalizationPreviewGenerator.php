<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\FrontCatalog\FrontCatalogMetadata;
use App\FrontCatalog\FrontCatalogProvider;
use App\Integration\Replicate\ReplicatePredictionClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class PersonalizationPreviewGenerator
{
    private const PROVIDER_PAGE_TIMEOUT_SECONDS = 120;
    private const PROVIDER_PAGE_MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
        private readonly ReplicatePredictionClient $replicatePredictionClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(int:REPLICATE_MAX_RETRIES)%')]
        private readonly int $maxRetries,
    ) {
    }

    public function getMaxRetries(): int
    {
        return max(1, $this->maxRetries);
    }

    public function hasReachedRetryLimit(PersonalizationSession $session): bool
    {
        $latestJob = $this->findLatestGenerationJob($session);

        return null !== $latestJob
            && $latestJob->getStatus() === PersonalizationGenerationJobStatus::Failed
            && $latestJob->getAttemptNumber() >= $this->getMaxRetries();
    }

    public function trigger(PersonalizationSession $session, bool $force = false): PersonalizationGenerationJob
    {
        $this->assertPreviewGenerationReady($session);
        $this->replicatePredictionClient->assertConfigured();

        $latestJob = $this->findLatestGenerationJob($session);

        if (null !== $latestJob && $latestJob->getStatus() === PersonalizationGenerationJobStatus::Completed && $this->hasCompletePreviewArtifacts($latestJob)) {
            return $latestJob;
        }

        if (
            null !== $latestJob
            && in_array($latestJob->getStatus(), [PersonalizationGenerationJobStatus::Processing, PersonalizationGenerationJobStatus::Queued], true)
            && null !== $latestJob->getProviderJobId()
        ) {
            return $this->synchronize($latestJob);
        }

        $attemptNumber = null !== $latestJob ? $latestJob->getAttemptNumber() + 1 : 1;

        if (!$force && $attemptNumber > $this->getMaxRetries()) {
            throw new \RuntimeException(sprintf('Preview generation reached the retry limit (%d).', $this->getMaxRetries()));
        }

        $book = $this->getBookBySession($session);
        $generationPlan = $this->buildGenerationPlan($session, $book);

        if ([] === $generationPlan) {
            throw new \RuntimeException('The selected book blueprint does not expose any illustrated page to generate.');
        }

        $job = new PersonalizationGenerationJob(
            $session,
            'replicate',
            $attemptNumber,
            $this->replicatePredictionClient->getModelReference(),
            $this->buildRequestPayload($book, $generationPlan),
        );

        $this->entityManager->persist($job);
        $session->markGenerationRequested();
        $this->entityManager->flush();

        try {
            $state = $this->initialState($generationPlan);
            $this->startPredictionForCurrentPage($job, $session, $book, $generationPlan, $state);
        } catch (\Throwable $exception) {
            $job->fail($exception->getMessage(), 'request_failed', [
                'state' => $this->initialState($generationPlan),
            ]);
            $session->saveContent($session->getChildName(), $session->getDedication(), $session->getExtraFields(), max($session->getStep(), 3));
            $this->entityManager->flush();

            $this->logger->error('Replicate generation request failed.', [
                'session_id' => $session->getId(),
                'generation_job_id' => $job->getId(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $job;
    }

    public function synchronize(PersonalizationGenerationJob $job): PersonalizationGenerationJob
    {
        $session = $job->getSession();

        if (null === $job->getProviderJobId()) {
            throw new \RuntimeException('The generation job has no provider job id.');
        }

        $book = $this->getBookBySession($session);
        $generationPlan = $this->resolveGenerationPlan($job, $session, $book);
        $state = $this->resolveGenerationState($job, $generationPlan);

        if ($job->getStatus() === PersonalizationGenerationJobStatus::Completed && $this->hasCompletePreviewArtifacts($job, $generationPlan)) {
            return $job;
        }

        $prediction = $this->replicatePredictionClient->getPrediction($job->getProviderJobId());
        $providerStatus = trim((string) ($prediction['status'] ?? 'processing'));
        $state['providerJobId'] = $job->getProviderJobId();
        $state['providerStatus'] = $providerStatus;

        if (in_array($providerStatus, ['starting', 'processing'], true)) {
            if ($this->hasCurrentProviderPageTimedOut($state)) {
                return $this->retryOrFailTimedOutPage($job, $session, $book, $generationPlan, $state, $prediction);
            }

            $job->recordProviderState($providerStatus, [
                'state' => $state,
                'prediction' => $prediction,
            ]);
            $this->syncSessionProgress($session, $job, $generationPlan, $state);
            $this->entityManager->flush();

            return $job;
        }

        if ('succeeded' === $providerStatus) {
            $currentPage = $generationPlan[$state['currentPageIndex']] ?? null;

            if (null === $currentPage) {
                throw new \RuntimeException('The current generation page could not be resolved.');
            }

            $outputUrls = $this->extractOutputUrls($prediction);

            if ([] === $outputUrls) {
                $job->fail('Replicate completed without usable output URLs.', $providerStatus, [
                    'state' => $state,
                    'prediction' => $prediction,
                ]);
                $this->syncSessionProgress($session, $job, $generationPlan, $state);
                $this->entityManager->flush();

                return $job;
            }

            $downloaded = $this->replicatePredictionClient->downloadFile($outputUrls[0]);
            $this->replaceArtifactForPage($job, $session, $currentPage, $downloaded['content'], $downloaded['mimeType']);

            $completedPageNumbers = array_values(array_unique([...$state['completedPageNumbers'], $currentPage['pageNumber']]));
            sort($completedPageNumbers);
            $completedPageIds = array_values(array_unique([...$state['completedPageIds'], $currentPage['id']]));
            $state['completedPageNumbers'] = $completedPageNumbers;
            $state['completedPageIds'] = $completedPageIds;
            $state['generatedPageCount'] = count($completedPageNumbers);
            $state['pageRuns'][$currentPage['id']] = [
                'pageId' => $currentPage['id'],
                'pageNumber' => $currentPage['pageNumber'],
                'status' => 'completed',
                'providerJobId' => $job->getProviderJobId(),
                'providerStatus' => $providerStatus,
                'completedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'outputUrlCount' => count($outputUrls),
            ];

            if ($state['generatedPageCount'] >= $state['totalPageCount']) {
                $state['currentPageIndex'] = $state['totalPageCount'];
                $state['currentPageId'] = null;
                $state['currentPageNumber'] = null;
                $job->complete($providerStatus, [
                    'state' => $state,
                    'prediction' => $prediction,
                ]);
                $session->markPreviewReady();
                $this->entityManager->flush();

                $this->logger->info('Replicate generation completed page by page.', [
                    'session_id' => $session->getId(),
                    'generation_job_id' => $job->getId(),
                    'provider_job_id' => $job->getProviderJobId(),
                    'generated_page_count' => $state['generatedPageCount'],
                ]);

                return $job;
            }

            $state['currentPageIndex'] += 1;
            $this->startPredictionForCurrentPage($job, $session, $book, $generationPlan, $state);

            return $job;
        }

        $errorMessage = trim((string) ($prediction['error'] ?? 'Replicate generation failed.'));
        $currentPage = $generationPlan[$state['currentPageIndex']] ?? null;

        if (null !== $currentPage) {
            $state['pageRuns'][$currentPage['id']] = [
                'pageId' => $currentPage['id'],
                'pageNumber' => $currentPage['pageNumber'],
                'status' => 'failed',
                'providerJobId' => $job->getProviderJobId(),
                'providerStatus' => $providerStatus,
                'error' => $errorMessage,
                'failedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        }

        $job->fail($errorMessage, $providerStatus, [
            'state' => $state,
            'prediction' => $prediction,
        ]);
        $this->syncSessionProgress($session, $job, $generationPlan, $state);
        $this->entityManager->flush();

        $this->logger->warning('Replicate generation failed.', [
            'session_id' => $session->getId(),
            'generation_job_id' => $job->getId(),
            'provider_job_id' => $job->getProviderJobId(),
            'provider_status' => $providerStatus,
            'error' => $errorMessage,
        ]);

        return $job;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasCurrentProviderPageTimedOut(array $state): bool
    {
        $currentPageId = (string) ($state['currentPageId'] ?? '');

        if ('' === $currentPageId || !is_array($state['pageRuns'][$currentPageId] ?? null)) {
            return false;
        }

        $requestedAt = trim((string) ($state['pageRuns'][$currentPageId]['requestedAt'] ?? ''));

        if ('' === $requestedAt) {
            return false;
        }

        try {
            $requestedAtDate = new \DateTimeImmutable($requestedAt);
        } catch (\Throwable) {
            return false;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $requestedAtDate->getTimestamp() >= self::PROVIDER_PAGE_TIMEOUT_SECONDS;
    }

    /**
     * @param array<string, mixed> $book
     * @param list<array<string, mixed>> $generationPlan
     * @param array<string, mixed> $state
     * @param array<string, mixed> $prediction
     */
    private function retryOrFailTimedOutPage(
        PersonalizationGenerationJob $job,
        PersonalizationSession $session,
        array $book,
        array $generationPlan,
        array $state,
        array $prediction,
    ): PersonalizationGenerationJob {
        $currentPage = $generationPlan[$state['currentPageIndex']] ?? null;

        if (null === $currentPage) {
            throw new \RuntimeException('The timed out generation page could not be resolved.');
        }

        $pageId = (string) $currentPage['id'];
        $attemptsByPage = is_array($state['providerAttemptsByPage'] ?? null) ? $state['providerAttemptsByPage'] : [];
        $currentAttempts = max(1, (int) ($attemptsByPage[$pageId] ?? 1));
        $timeoutMessage = sprintf('Replicate page generation timed out after %d seconds.', self::PROVIDER_PAGE_TIMEOUT_SECONDS);

        $state['pageRuns'][$pageId] = array_merge(
            is_array($state['pageRuns'][$pageId] ?? null) ? $state['pageRuns'][$pageId] : [],
            [
                'status' => 'timeout',
                'providerStatus' => (string) ($state['providerStatus'] ?? 'timeout'),
                'timedOutAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'timeoutSeconds' => self::PROVIDER_PAGE_TIMEOUT_SECONDS,
                'providerAttempt' => $currentAttempts,
            ],
        );

        if ($currentAttempts >= self::PROVIDER_PAGE_MAX_ATTEMPTS) {
            $job->fail($timeoutMessage, 'timeout', [
                'state' => $state,
                'prediction' => $prediction,
            ]);
            $this->syncSessionProgress($session, $job, $generationPlan, $state);
            $this->entityManager->flush();

            $this->logger->warning('Replicate generation page retry limit reached.', [
                'session_id' => $session->getId(),
                'generation_job_id' => $job->getId(),
                'page_id' => $pageId,
                'page_number' => $currentPage['pageNumber'] ?? null,
                'provider_attempts' => $currentAttempts,
            ]);

            return $job;
        }

        $this->logger->warning('Replicate generation page timed out; retrying page.', [
            'session_id' => $session->getId(),
            'generation_job_id' => $job->getId(),
            'page_id' => $pageId,
            'page_number' => $currentPage['pageNumber'] ?? null,
            'provider_attempts' => $currentAttempts,
        ]);

        $this->startPredictionForCurrentPage($job, $session, $book, $generationPlan, $state);

        return $job;
    }

    public function synchronizeLatestForSession(PersonalizationSession $session): ?PersonalizationGenerationJob
    {
        $latestJob = $this->findLatestGenerationJob($session);

        if (null === $latestJob) {
            return null;
        }

        if (in_array($latestJob->getStatus(), [PersonalizationGenerationJobStatus::Processing, PersonalizationGenerationJobStatus::Queued], true)) {
            return $this->synchronize($latestJob);
        }

        return $latestJob;
    }

    private function assertPreviewGenerationReady(PersonalizationSession $session): void
    {
        if ('' === trim((string) $session->getChildName()) || null === $session->getLatestPhoto()) {
            throw new \RuntimeException('Photo and personalized content must be completed before preview generation.');
        }
    }

    /**
     * @param array<string, mixed> $book
     * @return list<array<string, mixed>>
     */
    private function buildGenerationPlan(PersonalizationSession $session, array $book): array
    {
        $bookBlueprint = $book['bookBlueprint'] ?? null;

        if (!is_array($bookBlueprint) || !is_array($bookBlueprint['pages'] ?? null)) {
            return [];
        }

        $plan = [];
        $globalNegativePrompt = trim((string) ($bookBlueprint['negative_prompt_default'] ?? ''));

        foreach ($bookBlueprint['pages'] as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageType = (string) ($page['type'] ?? '');

            if (!in_array($pageType, ['cover', 'story', 'backCover'], true)) {
                continue;
            }

            $promptTemplate = trim((string) ($page['prompt_template'] ?? ''));
            $defaultImagePath = trim((string) ($page['default_image_path'] ?? ''));

            if ('' === $promptTemplate || '' === $defaultImagePath) {
                continue;
            }

            $pageNegativePrompt = trim((string) ($page['negative_prompt'] ?? ''));
            $finalNegativePrompt = trim(implode(', ', array_filter([$globalNegativePrompt, $pageNegativePrompt])));
            $compiledTitle = $this->replacePlaceholders((string) ($page['title_template'] ?? ''), $session->getChildName());
            $compiledText = $this->replacePlaceholders((string) ($page['text_template'] ?? ''), $session->getChildName());
            $label = $compiledTitle !== '' ? $compiledTitle : ($compiledText !== '' ? $compiledText : ucfirst((string) ($page['id'] ?? 'Page')));

            if ('cover' === $pageType) {
                $label = sprintf('Couverture personnalisée pour %s', $session->getChildName() ?: 'votre enfant');
            } elseif ('backCover' === $pageType) {
                $label = 'Quatrième de couverture';
            }

            $plan[] = [
                'id' => (string) ($page['id'] ?? sprintf('page_%d', $index + 1)),
                'pageNumber' => $index + 1,
                'type' => $pageType,
                'label' => $label,
                'defaultImagePath' => $defaultImagePath,
                'promptTemplate' => $promptTemplate,
                'pageNegativePrompt' => $pageNegativePrompt !== '' ? $pageNegativePrompt : null,
                'globalNegativePrompt' => $globalNegativePrompt !== '' ? $globalNegativePrompt : null,
                'finalNegativePrompt' => $finalNegativePrompt !== '' ? $finalNegativePrompt : null,
                'aspectRatio' => trim((string) ($page['aspect_ratio'] ?? '3:4')) ?: '3:4',
                'compiledTitle' => $compiledTitle !== '' ? $compiledTitle : null,
                'compiledText' => $compiledText !== '' ? $compiledText : null,
                'isPersonalized' => (bool) ($page['personalizable'] ?? true),
            ];
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $book
     * @param list<array<string, mixed>> $generationPlan
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $book, array $generationPlan): array
    {
        return [
            'bookId' => (string) ($book['id'] ?? ''),
            'bookSlug' => (string) ($book['slug'] ?? ''),
            'bookTitle' => (string) ($book['title'] ?? ''),
            'bookBlueprintVersion' => (int) (($book['bookBlueprint']['version'] ?? 1)),
            'generationPlan' => $generationPlan,
        ];
    }

    /**
     * @param list<array<string, mixed>> $generationPlan
     * @return array<string, mixed>
     */
    private function initialState(array $generationPlan): array
    {
        return [
            'currentPageIndex' => 0,
            'currentPageId' => $generationPlan[0]['id'] ?? null,
            'currentPageNumber' => $generationPlan[0]['pageNumber'] ?? null,
            'generatedPageCount' => 0,
            'totalPageCount' => count($generationPlan),
            'completedPageIds' => [],
            'completedPageNumbers' => [],
            'pageRuns' => [],
        ];
    }

    /**
     * @param array<string, mixed> $book
     * @param list<array<string, mixed>> $generationPlan
     * @param array<string, mixed> $state
     */
    private function startPredictionForCurrentPage(
        PersonalizationGenerationJob $job,
        PersonalizationSession $session,
        array $book,
        array $generationPlan,
        array $state,
    ): void {
        $currentPage = $generationPlan[$state['currentPageIndex'] ?? 0] ?? null;

        if (null === $currentPage) {
            throw new \RuntimeException('No generation page is available for the current job state.');
        }

        $input = $this->buildReplicateInput($session, $book, $currentPage);
        $prediction = $this->replicatePredictionClient->createPrediction($input);
        $providerJobId = trim((string) ($prediction['id'] ?? ''));
        $providerStatus = trim((string) ($prediction['status'] ?? 'starting'));

        if ('' === $providerJobId) {
            throw new \RuntimeException('Replicate did not return a provider job id.');
        }

        $state['currentPageId'] = $currentPage['id'];
        $state['currentPageNumber'] = $currentPage['pageNumber'];
        $state['providerJobId'] = $providerJobId;
        $state['providerStatus'] = $providerStatus;
        $attemptsByPage = is_array($state['providerAttemptsByPage'] ?? null) ? $state['providerAttemptsByPage'] : [];
        $attemptsByPage[$currentPage['id']] = ((int) ($attemptsByPage[$currentPage['id']] ?? 0)) + 1;
        $state['providerAttemptsByPage'] = $attemptsByPage;
        $state['pageRuns'][$currentPage['id']] = [
            'pageId' => $currentPage['id'],
            'pageNumber' => $currentPage['pageNumber'],
            'status' => 'processing',
            'providerJobId' => $providerJobId,
            'providerStatus' => $providerStatus,
            'requestedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'providerAttempt' => $attemptsByPage[$currentPage['id']],
            'input' => $input,
        ];

        $job->start($providerJobId, $providerStatus, [
            'state' => $state,
            'prediction' => $prediction,
        ]);
        $this->syncSessionProgress($session, $job, $generationPlan, $state);
        $this->entityManager->flush();

        $this->logger->info('Replicate generation started for blueprint page.', [
            'session_id' => $session->getId(),
            'generation_job_id' => $job->getId(),
            'provider_job_id' => $providerJobId,
            'page_id' => $currentPage['id'],
            'page_number' => $currentPage['pageNumber'],
        ]);
    }

    /**
     * @param array<string, mixed> $book
     * @param array<string, mixed> $pagePlan
     * @return array<string, mixed>
     */
    private function buildReplicateInput(PersonalizationSession $session, array $book, array $pagePlan): array
    {
        return [
            'prompt' => $this->buildPagePrompt($session, $book, $pagePlan),
            'input_images' => [
                $this->buildPageReferenceInput($pagePlan),
                $this->buildReplicateChildImageInput($session),
            ],
            'aspect_ratio' => (string) ($pagePlan['aspectRatio'] ?? '3:4'),
            'resolution' => '1 MP',
            'output_format' => 'png',
        ];
    }

    /**
     * @param array<string, mixed> $book
     * @param array<string, mixed> $pagePlan
     */
    private function buildPagePrompt(PersonalizationSession $session, array $book, array $pagePlan): string
    {
        $bookBlueprint = is_array($book['bookBlueprint'] ?? null) ? $book['bookBlueprint'] : [];
        $titleTemplate = (string) ($bookBlueprint['title_template'] ?? ($book['title'] ?? 'Livre personnalisé'));
        $compiledBookTitle = $this->replacePlaceholders($titleTemplate, $session->getChildName());
        $styleRules = array_filter(array_map(
            static fn ($rule): string => trim((string) $rule),
            is_array($bookBlueprint['style_rules'] ?? null) ? $bookBlueprint['style_rules'] : [],
        ));
        $pagePrompt = $this->replacePlaceholders((string) ($pagePlan['promptTemplate'] ?? ''), $session->getChildName());
        $compiledTitle = trim((string) ($pagePlan['compiledTitle'] ?? ''));
        $compiledText = trim((string) ($pagePlan['compiledText'] ?? ''));
        $finalNegativePrompt = trim((string) ($pagePlan['finalNegativePrompt'] ?? ''));
        $childName = trim((string) $session->getChildName());
        $dedication = trim((string) $session->getDedication());

        $parts = [
            sprintf('Create a premium children\'s book illustration for page %d of "%s".', (int) ($pagePlan['pageNumber'] ?? 0), $compiledBookTitle),
            sprintf('Primary scene instruction: %s.', $pagePrompt),
            'Reference image 1 is the default page composition and must drive the framing, palette, and page mood.',
            'Reference image 2 is the child likeness reference and must preserve the face, age, and identity consistently across the book.',
        ];

        if ('backCover' === (string) ($pagePlan['type'] ?? '')) {
            $parts[] = 'This is the back cover. Keep the composition elegant and storybook-like, and only include the child if it feels naturally implied by the reference composition.';
        } else {
            $parts[] = sprintf('The child shown in the illustration is named %s.', '' !== $childName ? $childName : 'the child');
        }

        if ('' !== $compiledTitle) {
            $parts[] = sprintf('Page title context: %s.', $compiledTitle);
        }

        if ('' !== $compiledText) {
            $parts[] = sprintf('Narrative text context: %s.', $compiledText);
        }

        if ('' !== $dedication) {
            $parts[] = sprintf('Emotional tone may subtly reflect this dedication: %s.', $dedication);
        }

        if ([] !== $styleRules) {
            $parts[] = sprintf('Style rules: %s.', implode(', ', $styleRules));
        }

        $parts[] = 'No typography, no captions, no page text inside the generated image itself.';

        if ('' !== $finalNegativePrompt) {
            $parts[] = sprintf('Avoid these failure modes: %s.', $finalNegativePrompt);
        }

        return implode(' ', $parts);
    }

    private function buildReplicateChildImageInput(PersonalizationSession $session): string
    {
        $photo = $session->getLatestPhoto();

        if (null === $photo) {
            throw new \RuntimeException('A child photo is required before generation.');
        }

        $storagePath = $photo->getStoragePath();

        if (null === $storagePath || '' === trim($storagePath)) {
            throw new \RuntimeException('The uploaded child photo is missing its private storage path.');
        }

        $filePath = $this->projectDir . '/' . ltrim($storagePath, '/');
        $contents = @file_get_contents($filePath);

        if (false === $contents) {
            throw new \RuntimeException('The uploaded child photo could not be read from local storage.');
        }

        $mimeType = $photo->getMimeType();

        if (strlen($contents) <= 950_000) {
            return $this->toDataUri($mimeType, $contents);
        }

        if (!function_exists('imagecreatefromstring')) {
            return $this->toDataUri($mimeType, $contents);
        }

        $image = @imagecreatefromstring($contents);

        if (false === $image) {
            return $this->toDataUri($mimeType, $contents);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1024;
        $maxHeight = 1024;
        $scale = min(1, $maxWidth / max(1, $width), $maxHeight / max(1, $height));
        $workingImage = $image;

        if ($scale < 1) {
            $scaled = imagescale(
                $image,
                max(1, (int) floor($width * $scale)),
                max(1, (int) floor($height * $scale)),
                IMG_BILINEAR_FIXED,
            );

            if (false !== $scaled) {
                $workingImage = $scaled;
                imagedestroy($image);
            }
        }

        $qualities = [84, 74, 64, 54];
        $jpegContents = null;

        foreach ($qualities as $quality) {
            ob_start();
            imagejpeg($workingImage, null, $quality);
            $candidate = (string) ob_get_clean();

            if ('' !== $candidate) {
                $jpegContents = $candidate;
            }

            if ('' !== $candidate && strlen($candidate) <= 950_000) {
                break;
            }
        }

        imagedestroy($workingImage);

        if (null === $jpegContents || '' === $jpegContents) {
            throw new \RuntimeException('The uploaded child photo could not be prepared for Replicate.');
        }

        return $this->toDataUri('image/jpeg', $jpegContents);
    }

    /**
     * @param array<string, mixed> $pagePlan
     */
    private function buildPageReferenceInput(array $pagePlan): string
    {
        $defaultImagePath = trim((string) ($pagePlan['defaultImagePath'] ?? ''));

        if ('' === $defaultImagePath) {
            throw new \RuntimeException('The page blueprint is missing its default image path.');
        }

        $filePath = $this->resolvePublicFilePath($defaultImagePath);
        $contents = @file_get_contents($filePath);

        if (false === $contents) {
            throw new \RuntimeException(sprintf('The default page image "%s" could not be read from local storage.', $defaultImagePath));
        }

        $mimeType = $this->guessMimeTypeFromPath($defaultImagePath);

        if ('image/svg+xml' === $mimeType) {
            return $this->toDataUri('image/png', $this->renderSvgReferencePng($pagePlan));
        }

        return $this->toDataUri($mimeType, $contents);
    }

    /**
     * @param array<string, mixed> $pagePlan
     */
    private function renderSvgReferencePng(array $pagePlan): string
    {
        $image = imagecreatetruecolor(768, 1024);

        if (false === $image) {
            throw new \RuntimeException('The default page reference image could not be prepared.');
        }

        $backgroundStart = imagecolorallocate($image, 246, 228, 208);
        $backgroundEnd = imagecolorallocate($image, 255, 250, 244);
        $accent = imagecolorallocate($image, 122, 62, 43);
        $muted = imagecolorallocate($image, 95, 95, 95);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 768, 1024, $backgroundEnd);
        imagefilledrectangle($image, 0, 0, 768, 260, $backgroundStart);
        imagefilledrectangle($image, 64, 96, 704, 476, $white);
        imagefilledrectangle($image, 64, 528, 704, 920, $white);
        imagerectangle($image, 64, 96, 704, 476, $accent);
        imagerectangle($image, 64, 528, 704, 920, $accent);

        $title = strtoupper((string) ($pagePlan['type'] ?? 'PAGE'));
        $subtitle = trim((string) ($pagePlan['compiledTitle'] ?? $pagePlan['label'] ?? 'Little Chapters'));
        $prompt = trim((string) ($pagePlan['promptTemplate'] ?? ''));
        $negative = trim((string) ($pagePlan['finalNegativePrompt'] ?? ''));

        imagestring($image, 5, 88, 128, 'Little Chapters', $accent);
        imagestring($image, 5, 88, 184, substr($title, 0, 48), $accent);
        imagestring($image, 4, 88, 236, substr($subtitle, 0, 60), $muted);
        imagestring($image, 3, 88, 332, substr($prompt, 0, 86), $muted);
        imagestring($image, 4, 88, 564, 'Default page reference from bookBlueprint', $accent);
        imagestring($image, 3, 88, 628, substr((string) ($pagePlan['compiledText'] ?? ''), 0, 92), $muted);

        if ('' !== $negative) {
            imagestring($image, 2, 88, 886, substr($negative, 0, 104), $muted);
        }

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        if ('' === $contents) {
            throw new \RuntimeException('The default page reference PNG could not be created.');
        }

        return $contents;
    }

    private function toDataUri(string $mimeType, string $contents): string
    {
        return sprintf('data:%s;base64,%s', trim($mimeType), base64_encode($contents));
    }

    /**
     * @param array<string, mixed> $pagePlan
     */
    private function replaceArtifactForPage(
        PersonalizationGenerationJob $job,
        PersonalizationSession $session,
        array $pagePlan,
        string $contents,
        string $mimeType,
    ): void {
        foreach ($this->findPreviewArtifacts($job) as $artifact) {
            if ($artifact->getPageNumber() === (int) $pagePlan['pageNumber']) {
                $this->entityManager->remove($artifact);
            }
        }

        $publicPath = $this->persistArtifactBinary($session, $job, (int) $pagePlan['pageNumber'], $contents, $mimeType);
        $artifact = new PersonalizationPreviewArtifact(
            $session,
            $job,
            (int) $pagePlan['pageNumber'],
            (string) $pagePlan['label'],
            (bool) ($pagePlan['isPersonalized'] ?? true),
            $publicPath,
            $mimeType,
        );

        $this->entityManager->persist($artifact);
    }

    private function persistArtifactBinary(
        PersonalizationSession $session,
        PersonalizationGenerationJob $job,
        int $pageNumber,
        string $contents,
        string $mimeType,
    ): string {
        $directory = $this->projectDir . '/public/uploads/personalizations';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $extension = $this->guessExtensionFromMimeType($mimeType);
        $filename = sprintf(
            '%s-preview-job-%d-page-%d-%s.%s',
            $session->getId(),
            $job->getId() ?? 0,
            $pageNumber,
            strtolower(Uuid::v7()->toBase32()),
            $extension,
        );

        $filePath = $directory . '/' . $filename;
        file_put_contents($filePath, $contents);

        return '/uploads/personalizations/' . $filename;
    }

    private function guessExtensionFromMimeType(string $mimeType): string
    {
        $normalized = strtolower(trim(explode(';', $mimeType)[0] ?? $mimeType));

        return match ($normalized) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    private function guessMimeTypeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $prediction
     * @return list<string>
     */
    private function extractOutputUrls(array $prediction): array
    {
        $output = $prediction['output'] ?? null;

        if (is_string($output) && '' !== trim($output)) {
            return [trim($output)];
        }

        if (!is_array($output)) {
            return [];
        }

        $urls = [];

        foreach ($output as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $urls[] = trim($value);
            }
        }

        return $urls;
    }

    /** @return array<string, mixed> */
    private function getBookBySession(PersonalizationSession $session): array
    {
        foreach ($this->frontCatalogMetadata->books() as $slug => $metadata) {
            if (($metadata['id'] ?? null) === $session->getBookId()) {
                return $this->frontCatalogProvider->getBookBySlug($slug);
            }
        }

        return [
            'id' => $session->getBookId(),
            'slug' => '',
            'title' => '',
            'coverImage' => '',
            'previewPages' => [],
            'theme' => 'aventure',
            'bookBlueprint' => null,
        ];
    }

    private function resolvePublicFilePath(string $publicPath): string
    {
        return $this->projectDir . '/public' . $publicPath;
    }

    private function replacePlaceholders(string $template, ?string $childName = null): string
    {
        return trim(str_replace('{child_name}', trim((string) $childName) ?: 'votre enfant', $template));
    }

    /**
     * @param array<string, mixed> $book
     * @return list<array<string, mixed>>
     */
    private function resolveGenerationPlan(PersonalizationGenerationJob $job, PersonalizationSession $session, array $book): array
    {
        $requestPayload = $job->getRequestPayload();
        $generationPlan = $requestPayload['generationPlan'] ?? null;

        if (is_array($generationPlan) && [] !== $generationPlan) {
            return array_values(array_filter($generationPlan, is_array(...)));
        }

        return $this->buildGenerationPlan($session, $book);
    }

    /**
     * @param list<array<string, mixed>> $generationPlan
     * @return array<string, mixed>
     */
    private function resolveGenerationState(PersonalizationGenerationJob $job, array $generationPlan): array
    {
        $responsePayload = $job->getResponsePayload();
        $state = is_array($responsePayload['state'] ?? null) ? $responsePayload['state'] : $this->initialState($generationPlan);
        $state['totalPageCount'] = count($generationPlan);
        $state['generatedPageCount'] = count(array_values(array_unique(array_map('intval', is_array($state['completedPageNumbers'] ?? null) ? $state['completedPageNumbers'] : []))));
        $state['completedPageIds'] = is_array($state['completedPageIds'] ?? null) ? array_values($state['completedPageIds']) : [];
        $state['completedPageNumbers'] = is_array($state['completedPageNumbers'] ?? null) ? array_values(array_map('intval', $state['completedPageNumbers'])) : [];
        $state['pageRuns'] = is_array($state['pageRuns'] ?? null) ? $state['pageRuns'] : [];

        return $state;
    }

    /**
     * @param list<array<string, mixed>> $generationPlan
     * @param array<string, mixed> $state
     */
    private function syncSessionProgress(
        PersonalizationSession $session,
        PersonalizationGenerationJob $job,
        array $generationPlan,
        array $state,
    ): void {
        if ($job->getStatus() === PersonalizationGenerationJobStatus::Completed && $this->hasCompletePreviewArtifacts($job, $generationPlan)) {
            $session->markPreviewReady();

            return;
        }

        if ([] !== $this->findPreviewArtifacts($job)) {
            $session->markPreviewPartialReady();

            return;
        }

        if (in_array($job->getStatus(), [PersonalizationGenerationJobStatus::Processing, PersonalizationGenerationJobStatus::Queued], true)) {
            $session->markGenerating();

            return;
        }

        if ($job->getStatus() === PersonalizationGenerationJobStatus::Failed) {
            $session->saveContent($session->getChildName(), $session->getDedication(), $session->getExtraFields(), max($session->getStep(), 3));
        }
    }

    private function findLatestGenerationJob(PersonalizationSession $session): ?PersonalizationGenerationJob
    {
        /** @var PersonalizationGenerationJob|null $job */
        $job = $this->entityManager->getRepository(PersonalizationGenerationJob::class)->findOneBy(
            ['session' => $session],
            ['requestedAt' => 'DESC', 'id' => 'DESC'],
        );

        return $job;
    }

    /** @return list<PersonalizationPreviewArtifact> */
    private function findPreviewArtifacts(PersonalizationGenerationJob $generationJob): array
    {
        return $this->entityManager->getRepository(PersonalizationPreviewArtifact::class)->findBy(
            ['generationJob' => $generationJob],
            ['pageNumber' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * @param list<array<string, mixed>> $generationPlan
     */
    private function hasCompletePreviewArtifacts(PersonalizationGenerationJob $generationJob, array $generationPlan = []): bool
    {
        $artifacts = $this->findPreviewArtifacts($generationJob);

        if ([] === $artifacts) {
            return false;
        }

        if ([] === $generationPlan) {
            return true;
        }

        return count($artifacts) >= count($generationPlan);
    }
}
