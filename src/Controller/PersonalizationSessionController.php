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
use App\Personalization\PersonalizationPreviewGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PersonalizationSessionController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FrontCatalogProvider $frontCatalogProvider,
        private readonly FrontCatalogMetadata $frontCatalogMetadata,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PersonalizationPreviewGenerator $personalizationPreviewGenerator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
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

        $session = new PersonalizationSession($bookId);
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
    public function readSession(string $id): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

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

        $payload = $this->readJsonPayload($request);
        $childName = array_key_exists('childName', $payload) ? (string) $payload['childName'] : $session->getChildName();
        $dedication = array_key_exists('dedication', $payload) ? (string) $payload['dedication'] : $session->getDedication();
        $extraFields = is_array($payload['extraFields'] ?? null) ? $payload['extraFields'] : $session->getExtraFields();
        $step = is_numeric($payload['step'] ?? null) ? (int) $payload['step'] : $session->getStep();

        $session->saveContent($childName, $dedication, $extraFields, $step);
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

        $uploadedFile = $request->files->get('photo');

        if (!$uploadedFile instanceof UploadedFile) {
            return $this->errorResponse('The "photo" file is required.', Response::HTTP_BAD_REQUEST);
        }

        if (!str_starts_with((string) $uploadedFile->getMimeType(), 'image/')) {
            return $this->errorResponse('Only image uploads are supported.', Response::HTTP_BAD_REQUEST);
        }

        $uploadDirectory = $this->projectDir . '/public/uploads/personalizations';

        if (!is_dir($uploadDirectory)) {
            mkdir($uploadDirectory, 0775, true);
        }

        $extension = $uploadedFile->guessExtension() ?? $uploadedFile->getClientOriginalExtension() ?? 'bin';
        $mimeType = $uploadedFile->getMimeType() ?? 'application/octet-stream';
        $fileSize = (int) ($uploadedFile->getSize() ?? 0);
        $storedFilename = sprintf('%s-%s.%s', $session->getId(), Uuid::v7()->toBase32(), $extension);
        $uploadedFile->move($uploadDirectory, $storedFilename);

        $publicPath = '/uploads/personalizations/' . $storedFilename;
        $photo = new UploadedPhoto(
            $session,
            $uploadedFile->getClientOriginalName(),
            $storedFilename,
            $mimeType,
            $fileSize,
            $publicPath,
        );

        $session->addPhoto($photo);
        $session->setStep(max($session->getStep(), 2));
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session), Response::HTTP_CREATED);
    }

    #[Route(
        '/api/personalization/sessions/{id}/preview',
        name: 'app_personalization_sessions_preview',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readPreview(string $id): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

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
    public function approveSession(string $id): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

        $generationJob = $this->findLatestGenerationJob($session);

        if (!$this->hasCompletePersistedPreview($session, $generationJob)) {
            return $this->errorResponse('Preview cannot be approved before the generated preview is completed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->approve();
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeSession($session));
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

        return new JsonResponse($this->buildGenerationContract($session, $generationJob), Response::HTTP_ACCEPTED);
    }

    #[Route(
        '/api/personalization/sessions/{id}/generation-status',
        name: 'app_personalization_sessions_generation_status',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readGenerationStatus(string $id): JsonResponse
    {
        $session = $this->findSession($id);

        if (null === $session) {
            return $this->errorResponse('Personalization session not found.', Response::HTTP_NOT_FOUND);
        }

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
            'step' => $session->getStep(),
            'childName' => $session->getChildName() ?? '',
            'childPhoto' => null !== $latestPhoto ? $this->absoluteUrl($latestPhoto->getPublicPath()) : null,
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
        $pages = [];
        $artifacts = $this->findPreviewArtifacts($generationJob);

        foreach ($artifacts as $artifact) {
            $pages[] = [
                'id' => sprintf('artifact-page-%d', $artifact->getPageNumber()),
                'pageNumber' => $artifact->getPageNumber(),
                'imageUrl' => $this->absoluteUrl($artifact->getPublicPath()),
                'isPersonalized' => $artifact->isPersonalized(),
                'label' => $artifact->getLabel(),
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
                'title' => (string) ($book['title'] ?? ''),
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
        return rtrim($this->defaultUri, '/') . $path;
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
