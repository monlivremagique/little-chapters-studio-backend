<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Support\OperationalEvent;
use App\Personalization\PersonalizationPreviewGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SupportOperationsController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalizationPreviewGenerator $personalizationPreviewGenerator,
        #[Autowire('%env(default::SUPPORT_OPERATIONS_TOKEN)%')]
        private readonly ?string $supportToken,
    ) {
    }

    #[Route(
        '/api/custom/support/orders/{orderNumber}/events',
        name: 'app_custom_support_order_events_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readOrderEvents(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return new JsonResponse(['message' => 'A valid support token is required.'], Response::HTTP_FORBIDDEN);
        }

        $events = $this->entityManager->getRepository(OperationalEvent::class)->findBy(
            ['orderNumber' => $orderNumber],
            ['id' => 'ASC'],
        );

        return new JsonResponse(array_map(
            static fn (OperationalEvent $event): array => [
                'type' => $event->getType(),
                'level' => $event->getLevel(),
                'sessionId' => $event->getSessionId(),
                'orderNumber' => $event->getOrderNumber(),
                'context' => (object) $event->getContext(),
                'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
            ],
            $events,
        ));
    }

    #[Route(
        '/api/custom/support/personalization/generation-jobs',
        name: 'app_custom_support_generation_jobs_list',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function listGenerationJobs(Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return new JsonResponse(['message' => 'A valid support token is required.'], Response::HTTP_FORBIDDEN);
        }

        $failedOnly = filter_var($request->query->get('failedOnly', '0'), FILTER_VALIDATE_BOOL);
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('job')
            ->from(PersonalizationGenerationJob::class, 'job')
            ->orderBy('job.requestedAt', 'DESC')
            ->addOrderBy('job.id', 'DESC')
            ->setMaxResults(50);

        if ($failedOnly) {
            $queryBuilder
                ->andWhere('job.status = :failedStatus')
                ->setParameter('failedStatus', PersonalizationGenerationJobStatus::Failed);
        }

        $jobs = $queryBuilder->getQuery()->getResult();

        return new JsonResponse(array_map(
            static fn (PersonalizationGenerationJob $job): array => [
                'id' => $job->getId(),
                'sessionId' => $job->getSession()->getId(),
                'status' => $job->getStatus()->value,
                'provider' => $job->getProvider(),
                'providerJobId' => $job->getProviderJobId(),
                'providerStatus' => $job->getProviderStatus(),
                'attemptNumber' => $job->getAttemptNumber(),
                'requestedAt' => $job->getRequestedAt()->format(DATE_ATOM),
                'startedAt' => $job->getStartedAt()?->format(DATE_ATOM),
                'completedAt' => $job->getCompletedAt()?->format(DATE_ATOM),
                'lastPolledAt' => $job->getLastPolledAt()?->format(DATE_ATOM),
                'errorMessage' => $job->getErrorMessage(),
            ],
            $jobs,
        ));
    }

    #[Route(
        '/api/custom/support/personalization/generation-jobs/{jobId}/retry',
        name: 'app_custom_support_generation_job_retry',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function retryGenerationJob(string $jobId, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return new JsonResponse(['message' => 'A valid support token is required.'], Response::HTTP_FORBIDDEN);
        }

        /** @var PersonalizationGenerationJob|null $job */
        $job = $this->entityManager->getRepository(PersonalizationGenerationJob::class)->find($jobId);

        if (!$job instanceof PersonalizationGenerationJob) {
            return new JsonResponse(['message' => 'Generation job not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $retriedJob = $this->personalizationPreviewGenerator->retryFailedJob($job);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'jobId' => $retriedJob->getId(),
            'status' => $retriedJob->getStatus()->value,
            'attemptNumber' => $retriedJob->getAttemptNumber(),
            'sessionId' => $retriedJob->getSession()->getId(),
        ]);
    }

    private function isSupportTokenValid(Request $request): bool
    {
        $expectedToken = trim((string) $this->supportToken);

        return '' !== $expectedToken
            && hash_equals($expectedToken, trim((string) $request->headers->get('X-Support-Token', '')));
    }
}
