<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Payment\Payment;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\Payment\StripePendingWebhookEvent;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Support\OperationalEvent;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationPreviewGenerator;
use App\RateLimiting\RateLimit;
use App\Stripe\StripeCheckoutSynchronizer;
use App\Trait\ApiErrorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RateLimit('support', 'ip')]
final class SupportOperationsController
{
    use ApiErrorTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalizationPreviewGenerator $personalizationPreviewGenerator,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly StripeCheckoutSynchronizer $stripeCheckoutSynchronizer,
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
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
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
        '/api/custom/support/orders/{orderNumber}/trace',
        name: 'app_custom_support_order_trace_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readOrderTrace(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
        }

        $sessions = $this->personalizationOrderLinker->findSessionsByOrderNumber($orderNumber);
        $sessionIds = array_map(static fn (PersonalizationSession $session): string => $session->getId(), $sessions);
        $events = $this->entityManager->getRepository(OperationalEvent::class)->findBy([
            'orderNumber' => $orderNumber,
        ], ['id' => 'ASC']);
        $stripeCheckoutSessions = $this->entityManager->getRepository(StripeCheckoutSession::class)->findBy([
            'syliusOrderNumber' => $orderNumber,
        ], ['id' => 'ASC']);
        $pendingStripeWebhooks = [];
        $payments = [];

        foreach ($stripeCheckoutSessions as $stripeCheckoutSession) {
            /** @var Payment|null $payment */
            $payment = $this->entityManager->getRepository(Payment::class)->find($stripeCheckoutSession->getSyliusPaymentId());

            if ($payment instanceof Payment) {
                $payments[$payment->getId()] = $payment;
            }

            $pendingStripeWebhooks = [
                ...$pendingStripeWebhooks,
                ...$this->entityManager->getRepository(StripePendingWebhookEvent::class)->findBy([
                    'providerSessionId' => $stripeCheckoutSession->getProviderSessionId(),
                ], ['id' => 'ASC']),
            ];
        }

        $generationJobs = [];
        $pdfArtifacts = [];
        $fulfillments = [];

        foreach ($sessions as $session) {
            $generationJobs[$session->getId()] = $this->entityManager->getRepository(PersonalizationGenerationJob::class)->findBy([
                'session' => $session,
            ], ['id' => 'ASC']);
            $pdfArtifacts[$session->getId()] = $this->entityManager->getRepository(PdfArtifact::class)->findBy([
                'session' => $session,
            ], ['id' => 'ASC']);
            $fulfillments[$session->getId()] = $this->entityManager->getRepository(FulfillmentOrder::class)->findBy([
                'session' => $session,
            ], ['id' => 'ASC']);
        }

        return new JsonResponse([
            'orderNumber' => $orderNumber,
            'sessionIds' => $sessionIds,
            'stripeCheckoutSessions' => array_map(static fn (StripeCheckoutSession $checkoutSession): array => [
                'providerSessionId' => $checkoutSession->getProviderSessionId(),
                'providerPaymentIntentId' => $checkoutSession->getProviderPaymentIntentId(),
                'paymentId' => $checkoutSession->getSyliusPaymentId(),
                'status' => $checkoutSession->getStatus(),
                'paymentStatus' => $checkoutSession->getPaymentStatus(),
                'errorMessage' => $checkoutSession->getErrorMessage(),
            ], $stripeCheckoutSessions),
            'payments' => array_map(static fn (Payment $payment): array => [
                'id' => $payment->getId(),
                'state' => $payment->getState(),
                'amount' => $payment->getAmount(),
                'details' => $payment->getDetails(),
            ], array_values($payments)),
            'pendingStripeWebhooks' => array_map(static fn (StripePendingWebhookEvent $event): array => [
                'providerEventId' => $event->getProviderEventId(),
                'providerSessionId' => $event->getProviderSessionId(),
                'type' => $event->getType(),
                'pending' => $event->isPending(),
            ], $pendingStripeWebhooks),
            'sessions' => array_map(static fn (PersonalizationSession $session): array => [
                'id' => $session->getId(),
                'status' => $session->getStatus()->value,
                'bookId' => $session->getBookId(),
                'childName' => $session->getChildName(),
                'cartItemId' => $session->getCartItemId(),
                'syliusOrderId' => $session->getSyliusOrderId(),
            ], $sessions),
            'generationJobs' => array_map(static fn (array $jobs): array => array_map(static fn (PersonalizationGenerationJob $job): array => [
                'id' => $job->getId(),
                'status' => $job->getStatus()->value,
                'providerJobId' => $job->getProviderJobId(),
                'providerStatus' => $job->getProviderStatus(),
                'attemptNumber' => $job->getAttemptNumber(),
                'errorMessage' => $job->getErrorMessage(),
            ], $jobs), $generationJobs),
            'pdfArtifacts' => array_map(static fn (array $artifacts): array => array_map(static fn (PdfArtifact $artifact): array => [
                'id' => $artifact->getId(),
                'status' => $artifact->getStatus(),
                'publicPath' => $artifact->getPublicPath(),
                'fileHash' => $artifact->getFileHash(),
                'preflightStatus' => $artifact->getPreflightStatus(),
                'preflightReport' => $artifact->getPreflightReport(),
            ], $artifacts), $pdfArtifacts),
            'fulfillments' => array_map(static fn (array $items): array => array_map(static fn (FulfillmentOrder $fulfillment): array => [
                'id' => $fulfillment->getId(),
                'status' => $fulfillment->getStatus(),
                'providerOrderId' => $fulfillment->getProviderOrderId(),
                'trackingUrl' => $fulfillment->getTrackingUrl(),
                'trackingNumber' => $fulfillment->getTrackingNumber(),
                'errorMessage' => $fulfillment->getErrorMessage(),
            ], $items), $fulfillments),
            'events' => array_map(static fn (OperationalEvent $event): array => [
                'type' => $event->getType(),
                'level' => $event->getLevel(),
                'sessionId' => $event->getSessionId(),
                'orderNumber' => $event->getOrderNumber(),
                'context' => $event->getContext(),
                'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
            ], $events),
        ]);
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
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
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
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
        }

        /** @var PersonalizationGenerationJob|null $job */
        $job = $this->entityManager->getRepository(PersonalizationGenerationJob::class)->find($jobId);

        if (!$job instanceof PersonalizationGenerationJob) {
            return $this->error('Tâche de génération introuvable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $retriedJob = $this->personalizationPreviewGenerator->retryFailedJob($job);
        } catch (\RuntimeException $exception) {
            return $this->errorFromException($exception, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'jobId' => $retriedJob->getId(),
            'status' => $retriedJob->getStatus()->value,
            'attemptNumber' => $retriedJob->getAttemptNumber(),
            'sessionId' => $retriedJob->getSession()->getId(),
        ]);
    }

    #[Route(
        '/api/custom/support/personalization/sessions/{sessionId}/generation-fail',
        name: 'app_custom_support_generation_job_force_fail',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function forceGenerationFailure(string $sessionId, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
        }

        /** @var PersonalizationSession|null $session */
        $session = $this->entityManager->getRepository(PersonalizationSession::class)->find($sessionId);

        if (!$session instanceof PersonalizationSession) {
            return $this->error('Session de personnalisation introuvable.', Response::HTTP_NOT_FOUND);
        }

        $message = trim((string) (($request->toArray()['message'] ?? 'Forced provider failure.') ?: 'Forced provider failure.'));
        $job = $this->personalizationPreviewGenerator->forceLatestJobFailure($session, $message);

        if (!$job instanceof PersonalizationGenerationJob) {
            return $this->error('Tâche de génération introuvable.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'errorMessage' => $job->getErrorMessage(),
            'sessionId' => $session->getId(),
        ]);
    }

    #[Route(
        '/api/custom/support/stripe/checkout-sessions/{providerSessionId}/force-failed',
        name: 'app_custom_support_stripe_checkout_force_failed',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function forceStripeCheckoutFailure(string $providerSessionId, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
        }

        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'providerSessionId' => $providerSessionId,
        ]);

        if (!$checkoutSession instanceof StripeCheckoutSession) {
            return $this->error('Session de paiement introuvable.', Response::HTTP_NOT_FOUND);
        }

        $this->stripeCheckoutSynchronizer->forceFailureForSupport($checkoutSession, 'Support forced failure.');

        return new JsonResponse([
            'sessionId' => $checkoutSession->getProviderSessionId(),
            'status' => $checkoutSession->getStatus(),
            'paymentStatus' => $checkoutSession->getPaymentStatus(),
            'errorMessage' => $checkoutSession->getErrorMessage(),
        ]);
    }

    #[Route(
        '/api/custom/support/stripe/checkout-sessions/by-order-token/{orderTokenValue}',
        name: 'app_custom_support_stripe_checkout_by_order_token',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readStripeCheckoutSessionByOrderToken(string $orderTokenValue, Request $request): JsonResponse
    {
        if (!$this->isSupportTokenValid($request)) {
            return $this->error('Authentification support requise.', Response::HTTP_FORBIDDEN);
        }

        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'syliusOrderTokenValue' => $orderTokenValue,
        ], ['id' => 'DESC']);

        if (!$checkoutSession instanceof StripeCheckoutSession) {
            return $this->error('Session de paiement introuvable.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'sessionId' => $checkoutSession->getProviderSessionId(),
            'status' => $checkoutSession->getStatus(),
            'paymentStatus' => $checkoutSession->getPaymentStatus(),
            'orderNumber' => $checkoutSession->getSyliusOrderNumber(),
            'orderTokenValue' => $checkoutSession->getSyliusOrderTokenValue(),
            'errorMessage' => $checkoutSession->getErrorMessage(),
        ]);
    }

    private function isSupportTokenValid(Request $request): bool
    {
        $expectedToken = trim((string) $this->supportToken);

        return '' !== $expectedToken
            && hash_equals($expectedToken, trim((string) $request->headers->get('X-Support-Token', '')));
    }
}
