<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Entity\Personalization\UploadedPhoto;
use App\FrontCatalog\FrontCatalogMetadata;
use App\FrontCatalog\FrontCatalogProvider;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationPhotoManager;
use App\Personalization\PersonalizationPreviewGenerator;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use App\Personalization\PreviewVersionFactory;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PersonalizationSessionController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PersonalizationPhotoManager $personalizationPhotoManager,
        private readonly PersonalizationPreviewGenerator $personalizationPreviewGenerator,
        private readonly PreviewVersionFactory $previewVersionFactory,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        private readonly PersonalizationSessionOwnershipGuard $personalizationSessionOwnershipGuard,
    ) {
    }

    #[Route(
        '/api/personalization/sessions',
        name: 'app_personalization_sessions_create',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function createSession(Request $request): JsonResponse
    {
        $payload = $this->readJsonPayload($request);
        $bookId = trim((string) ($payload['bookId'] ?? ''));

        if ('' === $bookId) {
            return $this->errorResponse('The "bookId" field is required.', Response::HTTP_BAD_REQUEST);
        }

        $session = new PersonalizationSession(
            $bookId,
            $this->personalizationSessionOwnershipGuard->resolveOrCreateGuestOwnerToken($request),
        );
        $this->personalizationSessionOwnershipGuard->assignOwnerOnCreate($session);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session), Response::HTTP_CREATED);
    }

    #[Route(
        '/api/personalization/sessions/{id}',
        name: 'app_personalization_sessions_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readSession(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        return new JsonResponse($this->normalizeSession($session));
    }

    #[Route(
        '/api/personalization/sessions/{id}',
        name: 'app_personalization_sessions_update',
        methods: ['PATCH'],
        defaults: ['_profiler_collect' => false],
    )]
    public function updateSession(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        $payload = $this->readJsonPayload($request);
        $childName = array_key_exists('childName', $payload) ? (string) $payload['childName'] : $session->getChildName();
        $dedication = array_key_exists('dedication', $payload) ? (string) $payload['dedication'] : $session->getDedication();
        $extraFields = is_array($payload['extraFields'] ?? null) ? $payload['extraFields'] : $session->getExtraFields();
        $step = is_numeric($payload['step'] ?? null) ? (int) $payload['step'] : $session->getStep();

        try {
            if ($session->isApproved() || null !== $session->getCartItemId()) {
                $session->invalidateApprovalAndCommerce(PersonalizationSessionStatus::ContentCompleted);
            }

            $session->saveContent($childName, $dedication, $extraFields, $step);
        } catch (\DomainException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session));
    }

    #[Route(
        '/api/personalization/sessions/{id}/photo',
        name: 'app_personalization_sessions_upload_photo',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function uploadPhoto(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        $uploadedFile = $request->files->get('photo');

        if (!$uploadedFile instanceof UploadedFile) {
            return $this->errorResponse('The "photo" file is required.', Response::HTTP_BAD_REQUEST);
        }

        if ($session->isApproved() || null !== $session->getCartItemId()) {
            $session->invalidateApprovalAndCommerce(PersonalizationSessionStatus::PhotoUploaded);
        }

        try {
            $photo = $this->personalizationPhotoManager->createStoredPhoto($session, $uploadedFile);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        foreach ($session->getPhotos() as $existingPhoto) {
            if ($existingPhoto->isDeleted()) {
                continue;
            }

            $this->personalizationPhotoManager->deleteStoredPhoto($existingPhoto, 'replaced_by_new_upload');
        }

        $session->addPhoto($photo);
        $session->setStep(max($session->getStep(), 2));
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        $this->logger->info('Personalization photo uploaded.', [
            'session_id' => $session->getId(),
            'photo_id' => $photo->getId(),
            'mime_type' => $photo->getMimeType(),
            'file_size' => $photo->getFileSize(),
            'image_width' => $photo->getImageWidth(),
            'image_height' => $photo->getImageHeight(),
        ]);
        $this->operationalEventRecorder->record('personalization.photo_uploaded', 'info', $session->getId(), null, [
            'photo_id' => $photo->getId(),
            'mime_type' => $photo->getMimeType(),
            'file_size' => $photo->getFileSize(),
        ]);

        return new JsonResponse($this->normalizeSession($session), Response::HTTP_CREATED);
    }

    #[Route(
        '/api/personalization/photos/{photoId}',
        name: 'app_personalization_photos_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readPhoto(string $photoId, Request $request): Response
    {
        /** @var UploadedPhoto|null $photo */
        $photo = $this->entityManager->getRepository(UploadedPhoto::class)->find($photoId);

        if (null === $photo || $photo->isDeleted()) {
            return $this->errorResponse('Uploaded photo not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->personalizationPhotoManager->isAccessTokenValid($photo, $request->query->get('token'))) {
            return $this->errorResponse('A valid photo access token is required.', Response::HTTP_FORBIDDEN);
        }

        $filePath = $this->personalizationPhotoManager->resolveStoredPhotoPath($photo);

        if (null === $filePath || !is_file($filePath)) {
            return $this->errorResponse('Uploaded photo binary not found in private storage.', Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('Personalization photo accessed.', [
            'session_id' => $photo->getSession()->getId(),
            'photo_id' => $photo->getId(),
        ]);

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $photo->getMimeType());
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s"', addslashes($photo->getStoredFilename())));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    #[Route(
        '/api/personalization/sessions/{id}/photo',
        name: 'app_personalization_sessions_delete_photo',
        methods: ['DELETE'],
        defaults: ['_profiler_collect' => false],
    )]
    public function deleteLatestPhoto(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        $photo = $session->getLatestPhoto();

        if (null === $photo) {
            return $this->errorResponse('No uploaded photo is available for this personalization session.', Response::HTTP_NOT_FOUND);
        }

        if ($session->isApproved() || null !== $session->getCartItemId()) {
            $session->invalidateApprovalAndCommerce(PersonalizationSessionStatus::ContentCompleted);
        }

        $this->personalizationPhotoManager->deleteStoredPhoto($photo, 'deleted_by_user');
        $session->syncAfterPhotoDeletion();
        $this->entityManager->flush();

        $this->logger->info('Personalization photo deleted.', [
            'session_id' => $session->getId(),
            'photo_id' => $photo->getId(),
        ]);
        $this->operationalEventRecorder->record('personalization.photo_deleted', 'info', $session->getId(), null, [
            'photo_id' => $photo->getId(),
        ]);

        return new JsonResponse($this->normalizeSession($session));
    }

    #[Route(
        '/api/personalization/sessions/{id}/preview',
        name: 'app_personalization_sessions_preview',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readPreview(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        $generationJob = $this->findLatestGenerationJob($session);

        if (!$this->hasAnyPersistedPreview($generationJob)) {
            return $this->errorResponse('Preview generation must start before the persisted preview can be read.', Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->buildPreviewPayload($session, $generationJob));
    }

    #[Route(
        '/api/personalization/sessions/{id}/approve',
        name: 'app_personalization_sessions_approve',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function approveSession(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        $generationJob = $this->findLatestGenerationJob($session);

        if (!$this->hasCompletePersistedPreview($session, $generationJob)) {
            return $this->errorResponse('Preview cannot be approved before the generated preview is completed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->approve();
        $previewVersion = $this->previewVersionFactory->createApprovedVersion($session, $generationJob);
        $this->operationalEventRecorder->record('personalization.preview_approved', 'info', $session->getId(), null, [
            'generation_job_id' => $generationJob->getId(),
            'preview_version_number' => $previewVersion->getVersionNumber(),
            'preview_version_hash' => $previewVersion->getContentHash(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session) + [
            'previewVersionId' => $previewVersion->getId(),
            'previewVersionNumber' => $previewVersion->getVersionNumber(),
            'previewVersionHash' => $previewVersion->getContentHash(),
        ]);
    }

    #[Route(
        '/api/personalization/sessions/{id}/attach-to-cart',
        name: 'app_personalization_sessions_attach_to_cart',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function attachToCart(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        if (!$session->isApproved()) {
            return $this->errorResponse('The preview must be approved before attaching the session to a cart item.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $this->readJsonPayload($request);
        $cartTokenValue = trim((string) ($payload['cartTokenValue'] ?? ''));
        $cartItemId = trim((string) ($payload['cartItemId'] ?? ''));

        if ('' === $cartTokenValue || '' === $cartItemId) {
            return $this->errorResponse('The "cartTokenValue" and "cartItemId" fields are required.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->personalizationOrderLinker->attachSessionToCartItem($session, $cartTokenValue, $cartItemId);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $this->operationalEventRecorder->record('personalization.cart_attached', 'info', $session->getId(), null, [
            'cart_token_value' => $cartTokenValue,
            'cart_item_id' => $cartItemId,
        ]);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session));
    }

    #[Route(
        '/api/personalization/sessions/{id}/generation-requests',
        name: 'app_personalization_sessions_generation_request',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function createGenerationRequest(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        if (!$this->canBuildPreview($session)) {
            return new JsonResponse($this->buildGenerationContract($session), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $this->readJsonPayload($request);
        $force = (bool) ($payload['force'] ?? false);

        if (!$force && $this->personalizationPreviewGenerator->hasReachedRetryLimit($session)) {
            return new JsonResponse($this->buildGenerationContract($session, $this->findLatestGenerationJob($session)), Response::HTTP_CONFLICT);
        }

        try {
            $generationJob = $this->personalizationPreviewGenerator->trigger($session, $force);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->operationalEventRecorder->record('personalization.generation_requested', 'info', $session->getId(), null, [
            'generation_job_id' => $generationJob->getId(),
            'force' => $force,
            'provider' => $generationJob->getProvider(),
            'model' => $generationJob->getModelReference(),
        ]);
        $this->entityManager->flush();

        return new JsonResponse($this->buildGenerationContract($session, $generationJob), Response::HTTP_ACCEPTED);
    }

    #[Route(
        '/api/personalization/sessions/{id}/generation-status',
        name: 'app_personalization_sessions_generation_status',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readGenerationStatus(string $id, Request $request): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->personalizationSessionOwnershipGuard->assertCanAccessSession($session, $request);

        try {
            $generationJob = $this->personalizationPreviewGenerator->synchronizeLatestForSession($session);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($this->buildGenerationContract($session, $generationJob));
    }

    private function findSession(string $id): ?PersonalizationSession
    {
        return $this->entityManager->getRepository(PersonalizationSession::class)->find($id);
    }

    /** @return array<string, mixed> */
    private function readJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function errorResponse(string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse(['message' => $message], $statusCode);
    }

    /** @return array<string, mixed> */
    private function normalizeSession(PersonalizationSession $session): array
    {
        $latestPhoto = $session->getLatestPhoto();
        $extraFields = $session->getExtraFields();

        return [
            'id' => $session->getId(),
            'bookId' => $session->getBookId(),
            'ownerToken' => $session->getGuestOwnerToken(),
            'step' => $session->getStep(),
            'childName' => $session->getChildName() ?? '',
            'childPhoto' => null !== $latestPhoto ? $this->personalizationPhotoManager->createAbsoluteAccessUrl($latestPhoto) : null,
            'dedication' => $session->getDedication(),
            'extraFields' => (object) $extraFields,
            'createdAt' => $session->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $session->getUpdatedAt()->format(DATE_ATOM),
            'approvedAt' => $session->getApprovedAt()?->format(DATE_ATOM),
            'cartTokenValue' => $session->getCartTokenValue(),
            'cartItemId' => $session->getCartItemId(),
            'syliusOrderId' => $session->getSyliusOrderId(),
            'syliusOrderNumber' => $session->getSyliusOrderNumber(),
            'status' => $session->getStatus()->value,
        ];
    }

    private function canBuildPreview(PersonalizationSession $session): bool
    {
        return '' !== trim((string) $session->getChildName()) && null !== $session->getLatestPhoto();
    }

    /** @return array<string, mixed> */
    private function buildPreviewPayload(PersonalizationSession $session, PersonalizationGenerationJob $generationJob): array
    {
        $book = $this->getBookBySession($session);
        $blueprint = is_array($book['bookBlueprint'] ?? null) ? $book['bookBlueprint'] : [];
        $blueprintPages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];
        $compiledBookTitle = $this->replacePlaceholders(
            (string) ($blueprint['title_template'] ?? ($book['title'] ?? 'Livre personnalise')),
            $session->getChildName(),
        );
        $artifactsByPageNumber = [];
        $artifacts = $this->findPreviewArtifacts($generationJob);

        foreach ($artifacts as $artifact) {
            $artifactsByPageNumber[$artifact->getPageNumber()] = $artifact;
        }

        $pages = [];

        foreach ($blueprintPages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageNumber = (int) ($page['page_number'] ?? $page['pageNumber'] ?? ($index + 1));
            $pageType = (string) ($page['type'] ?? 'story');
            $compiledTitle = $this->replacePlaceholders((string) ($page['title_template'] ?? ''), $session->getChildName());
            $compiledText = $this->compilePreviewText($page, $session);
            $defaultImagePath = trim((string) ($page['default_image_path'] ?? ''));
            /** @var PersonalizationPreviewArtifact|null $artifact */
            $artifact = $artifactsByPageNumber[$pageNumber] ?? null;

            $pages[] = [
                'id' => (string) ($page['id'] ?? sprintf('page_%d', $pageNumber)),
                'type' => $pageType,
                'pageNumber' => $pageNumber,
                'imageUrl' => $artifact instanceof PersonalizationPreviewArtifact
                    ? $this->absoluteUrl($artifact->getPublicPath())
                    : ('' !== $defaultImagePath ? $this->absoluteUrl($defaultImagePath) : null),
                'isPersonalized' => $artifact instanceof PersonalizationPreviewArtifact,
                'label' => $artifact instanceof PersonalizationPreviewArtifact
                    ? $artifact->getLabel()
                    : $this->resolvePreviewLabel($pageType, $compiledTitle, $compiledText, (string) ($page['id'] ?? 'Page')),
                'title' => 'cover' === $pageType ? ($compiledTitle !== '' ? $compiledTitle : $compiledBookTitle) : ($compiledTitle !== '' ? $compiledTitle : null),
                'text' => $compiledText !== '' ? $compiledText : null,
            ];
        }

        $lastArtifact = [] !== $artifacts ? $artifacts[array_key_last($artifacts)] : null;

        return [
            'sessionId' => $session->getId(),
            'generationJobId' => $generationJob->getId(),
            'generatedAt' => $generationJob->getCompletedAt()?->format(DATE_ATOM) ?? $lastArtifact?->getCreatedAt()->format(DATE_ATOM),
            'status' => $session->getStatus()->value,
            'approved' => $session->isApproved(),
            'approvedAt' => $session->getApprovedAt()?->format(DATE_ATOM),
            'book' => [
                'id' => (string) ($book['id'] ?? ''),
                'slug' => (string) ($book['slug'] ?? ''),
                'title' => $compiledBookTitle,
                'coverImage' => (string) ($book['coverImage'] ?? ''),
            ],
            'pages' => $pages,
        ];
    }

    /** @return array<string, mixed> */
    private function buildGenerationContract(PersonalizationSession $session, ?PersonalizationGenerationJob $generationJob = null): array
    {
        $completePersistedPreview = $this->hasCompletePersistedPreview($session, $generationJob);
        $partialPersistedPreview = $this->hasAnyPersistedPreview($generationJob);
        $jobStatus = $generationJob?->getStatus();
        $previewReady = $completePersistedPreview;
        $responsePayload = $generationJob?->getResponsePayload();
        $state = is_array($responsePayload['state'] ?? null) ? $responsePayload['state'] : [];
        $artifactCount = null !== $generationJob ? count($this->findPreviewArtifacts($generationJob)) : 0;
        $generatedPageCount = max($artifactCount, (int) ($state['generatedPageCount'] ?? 0));
        $totalPageCount = (int) ($state['totalPageCount'] ?? 0);
        $retryLimitReached = null !== $generationJob
            && $jobStatus === PersonalizationGenerationJobStatus::Failed
            && $generationJob->getAttemptNumber() >= $this->personalizationPreviewGenerator->getMaxRetries();
        $status = match (true) {
            !$this->canBuildPreview($session) => 'content_incomplete',
            null === $generationJob => 'not_requested',
            $jobStatus === PersonalizationGenerationJobStatus::Failed => 'failed',
            !$partialPersistedPreview && !$completePersistedPreview && $session->getStatus() === PersonalizationSessionStatus::ContentCompleted => 'not_requested',
            $jobStatus === PersonalizationGenerationJobStatus::Queued => 'queued',
            $jobStatus === PersonalizationGenerationJobStatus::Processing => 'processing',
            default => 'completed',
        };

        return [
            'sessionId' => $session->getId(),
            'generationJobId' => $generationJob?->getId(),
            'mode' => 'persisted_preview',
            'provider' => $generationJob?->getProvider() ?? 'replicate',
            'status' => $status,
            'terminal' => $status === 'failed' || $status === 'completed',
            'previewReady' => $previewReady,
            'partialPreviewReady' => $partialPersistedPreview,
            'canTriggerRealGeneration' => !$retryLimitReached,
            'canRetry' => $status === 'failed',
            'retryLimitReached' => $retryLimitReached,
            'retryLimit' => $this->personalizationPreviewGenerator->getMaxRetries(),
            'previewUrl' => sprintf('/api/personalization/sessions/%s/preview', $session->getId()),
            'artifactCount' => $artifactCount,
            'generatedPageCount' => $generatedPageCount,
            'totalPageCount' => $totalPageCount,
            'currentPageId' => isset($state['currentPageId']) ? (string) $state['currentPageId'] : null,
            'currentPageNumber' => isset($state['currentPageNumber']) ? (int) $state['currentPageNumber'] : null,
            'providerJobId' => $generationJob?->getProviderJobId(),
            'providerStatus' => $generationJob?->getProviderStatus(),
            'modelReference' => $generationJob?->getModelReference(),
            'attemptNumber' => $generationJob?->getAttemptNumber(),
            'startedAt' => $generationJob?->getStartedAt()?->format(DATE_ATOM),
            'completedAt' => $generationJob?->getCompletedAt()?->format(DATE_ATOM),
            'errorMessage' => $generationJob?->getErrorMessage(),
            'nextContract' => [
                'trigger' => sprintf('/api/personalization/sessions/%s/generation-requests', $session->getId()),
                'status' => sprintf('/api/personalization/sessions/%s/generation-status', $session->getId()),
            ],
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

        return [
            'id' => $session->getBookId(),
            'slug' => '',
            'title' => '',
            'coverImage' => '',
            'previewPages' => [],
        ];
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->defaultUri, '/') . $path;
    }

    /** @param array<string, mixed> $page */
    private function compilePreviewText(array $page, PersonalizationSession $session): string
    {
        $pageType = (string) ($page['type'] ?? 'story');

        if ('dedication' === $pageType && null !== $session->getDedication() && '' !== trim($session->getDedication())) {
            return trim($session->getDedication());
        }

        return $this->replacePlaceholders((string) ($page['text_template'] ?? ''), $session->getChildName());
    }

    private function replacePlaceholders(string $template, ?string $childName): string
    {
        return str_replace('{child_name}', trim((string) $childName) !== '' ? trim((string) $childName) : 'votre enfant', trim($template));
    }

    private function resolvePreviewLabel(string $pageType, string $compiledTitle, string $compiledText, string $pageId): string
    {
        return match ($pageType) {
            'cover' => 'Couverture',
            'dedication' => 'Dedicace',
            'summary' => 'Resume',
            'backCover' => 'Quatrieme de couverture',
            default => $compiledTitle !== '' ? $compiledTitle : ($compiledText !== '' ? $compiledText : ucfirst($pageId)),
        };
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

    private function hasCompletePersistedPreview(
        PersonalizationSession $session,
        ?PersonalizationGenerationJob $generationJob,
    ): bool {
        if (
            $session->getStatus() !== PersonalizationSessionStatus::PreviewReady
            && $session->getStatus() !== PersonalizationSessionStatus::Approved
            && $session->getStatus() !== PersonalizationSessionStatus::CartAttached
            && $session->getStatus() !== PersonalizationSessionStatus::CheckoutCompleted
        ) {
            return false;
        }

        if (null === $generationJob || $generationJob->getStatus() !== PersonalizationGenerationJobStatus::Completed) {
            return false;
        }

        return [] !== $this->findPreviewArtifacts($generationJob);
    }

    private function hasAnyPersistedPreview(?PersonalizationGenerationJob $generationJob): bool
    {
        if (null === $generationJob) {
            return false;
        }

        return [] !== $this->findPreviewArtifacts($generationJob);
    }
}
