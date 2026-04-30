<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Entity\Payment\Payment;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\Payment\StripeWebhookEvent;
use App\Personalization\PersonalizationOrderLinker;
use App\Production\PostPaymentProductionOrchestrator;
use App\Support\CriticalAlertDispatcher;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\PaymentTransitions;

final class StripeCheckoutSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeCheckoutClientInterface $stripeCheckoutClient,
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly PostPaymentProductionOrchestrator $postPaymentProductionOrchestrator,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        private readonly CriticalAlertDispatcher $criticalAlertDispatcher,
    ) {
    }

    public function synchronizeFromProviderSessionId(string $providerSessionId): ?StripeCheckoutSession
    {
        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'providerSessionId' => $providerSessionId,
        ]);

        if (!$checkoutSession instanceof StripeCheckoutSession) {
            return null;
        }

        if (in_array($checkoutSession->getStatus(), ['failed', 'expired'], true)) {
            return $checkoutSession;
        }

        $payload = $this->stripeCheckoutClient->retrieveCheckoutSession($providerSessionId);

        $this->applyProviderPayload($checkoutSession, $payload, null, null);
        $this->entityManager->flush();

        return $checkoutSession;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handleWebhookEvent(array $event): ?StripeCheckoutSession
    {
        $providerEventId = trim((string) ($event['id'] ?? ''));
        $eventType = trim((string) ($event['type'] ?? ''));
        $payload = is_array($event) ? $event : [];

        if ('' === $providerEventId || '' === $eventType) {
            throw new \RuntimeException('Stripe webhook payload is missing required event metadata.');
        }

        /** @var StripeWebhookEvent|null $existingEvent */
        $existingEvent = $this->entityManager->getRepository(StripeWebhookEvent::class)->findOneBy([
            'providerEventId' => $providerEventId,
        ]);

        if ($existingEvent instanceof StripeWebhookEvent) {
            $object = $payload['data']['object'] ?? null;
            $providerSessionId = is_array($object) ? trim((string) ($object['id'] ?? '')) : '';

            if ('' === $providerSessionId) {
                return null;
            }

            /** @var StripeCheckoutSession|null $existingCheckoutSession */
            $existingCheckoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
                'providerSessionId' => $providerSessionId,
            ]);

            return $existingCheckoutSession;
        }

        $object = $payload['data']['object'] ?? null;
        $providerSessionId = is_array($object) ? trim((string) ($object['id'] ?? '')) : '';

        if ('' === $providerSessionId) {
            throw new \RuntimeException('Stripe webhook payload does not contain a checkout session id.');
        }

        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'providerSessionId' => $providerSessionId,
        ]);

        if (!$checkoutSession instanceof StripeCheckoutSession) {
            $this->logger->warning('Stripe webhook received for an unknown checkout session.', [
                'provider_event_id' => $providerEventId,
                'provider_session_id' => $providerSessionId,
                'type' => $eventType,
            ]);

            return null;
        }

        $this->entityManager->persist(new StripeWebhookEvent($providerEventId, $eventType, $payload));
        $this->applyProviderPayload($checkoutSession, $object, $providerEventId, $eventType);
        $this->entityManager->flush();

        return $checkoutSession;
    }

    public function forceFailureForSupport(StripeCheckoutSession $checkoutSession, string $reason = 'Support forced failure.'): void
    {
        $checkoutSession->markFailed($reason, $checkoutSession->getPaymentStatus(), $checkoutSession->getProviderPaymentIntentId());
        $this->markPaymentFailed($checkoutSession, 'support-forced', 'support.forced_failure');
        $this->entityManager->flush();
    }

    public function forceExpiryForSupport(StripeCheckoutSession $checkoutSession): void
    {
        $checkoutSession->markExpired($checkoutSession->getPaymentStatus(), $checkoutSession->getProviderPaymentIntentId());
        $this->markPaymentFailed($checkoutSession, 'support-expired', 'support.forced_expiry');
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $providerPayload
     */
    private function applyProviderPayload(
        StripeCheckoutSession $checkoutSession,
        array $providerPayload,
        ?string $providerEventId,
        ?string $providerEventType,
    ): void {
        $status = trim((string) ($providerPayload['status'] ?? ''));
        $paymentStatus = trim((string) ($providerPayload['payment_status'] ?? 'unpaid'));
        $paymentIntentId = isset($providerPayload['payment_intent']) ? trim((string) $providerPayload['payment_intent']) : null;

        if ($paymentStatus === 'paid') {
            $checkoutSession->markCompleted($paymentStatus, $paymentIntentId);
            $this->markPaymentCompleted($checkoutSession, $providerEventId, $providerEventType);

            return;
        }

        if ($providerEventType === 'checkout.session.async_payment_failed') {
            $checkoutSession->markFailed('Stripe reported an asynchronous payment failure.', $paymentStatus, $paymentIntentId);
            $this->markPaymentFailed($checkoutSession, $providerEventId, $providerEventType);

            return;
        }

        if ($status === 'expired' || $providerEventType === 'checkout.session.expired') {
            $checkoutSession->markExpired($paymentStatus, $paymentIntentId);
            $this->markPaymentFailed($checkoutSession, $providerEventId, $providerEventType);

            return;
        }

        $checkoutSession->markOpen(
            (string) ($providerPayload['url'] ?? $checkoutSession->getCheckoutUrl() ?? ''),
            $paymentStatus !== '' ? $paymentStatus : 'unpaid',
            $paymentIntentId,
        );
    }

    private function markPaymentCompleted(
        StripeCheckoutSession $checkoutSession,
        ?string $providerEventId,
        ?string $providerEventType,
    ): void {
        /** @var Payment|null $payment */
        $payment = $this->entityManager->getRepository(Payment::class)->find($checkoutSession->getSyliusPaymentId());

        if (!$payment instanceof Payment) {
            return;
        }

        $details = is_array($payment->getDetails()) ? $payment->getDetails() : [];
        $details['stripe'] = [
            'checkout_session_id' => $checkoutSession->getProviderSessionId(),
            'payment_intent_id' => $checkoutSession->getProviderPaymentIntentId(),
            'status' => $checkoutSession->getStatus(),
            'payment_status' => $checkoutSession->getPaymentStatus(),
            'last_event_id' => $providerEventId,
            'last_event_type' => $providerEventType,
        ];
        $payment->setDetails($details);

        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
        }

        $sessions = $this->personalizationOrderLinker->synchronizeSessionsWithOrderToken($checkoutSession->getSyliusOrderTokenValue());
        $this->operationalEventRecorder->record('stripe.payment_completed', 'info', null, $checkoutSession->getSyliusOrderNumber(), [
            'payment_id' => (string) $payment->getId(),
            'stripe_checkout_session_id' => $checkoutSession->getProviderSessionId(),
            'stripe_payment_intent_id' => $checkoutSession->getProviderPaymentIntentId(),
            'provider_event_id' => $providerEventId,
            'provider_event_type' => $providerEventType,
            'personalization_session_count' => count($sessions),
        ]);
        $this->postPaymentProductionOrchestrator->processPaidSessions($sessions);
    }

    private function markPaymentFailed(
        StripeCheckoutSession $checkoutSession,
        ?string $providerEventId,
        ?string $providerEventType,
    ): void {
        /** @var Payment|null $payment */
        $payment = $this->entityManager->getRepository(Payment::class)->find($checkoutSession->getSyliusPaymentId());

        if (!$payment instanceof Payment) {
            return;
        }

        $details = is_array($payment->getDetails()) ? $payment->getDetails() : [];
        $details['stripe'] = [
            'checkout_session_id' => $checkoutSession->getProviderSessionId(),
            'payment_intent_id' => $checkoutSession->getProviderPaymentIntentId(),
            'status' => $checkoutSession->getStatus(),
            'payment_status' => $checkoutSession->getPaymentStatus(),
            'last_event_id' => $providerEventId,
            'last_event_type' => $providerEventType,
            'error_message' => $checkoutSession->getErrorMessage(),
        ];
        $payment->setDetails($details);
        $this->operationalEventRecorder->record('stripe.payment_failed', 'warning', null, $checkoutSession->getSyliusOrderNumber(), [
            'payment_id' => (string) $payment->getId(),
            'stripe_checkout_session_id' => $checkoutSession->getProviderSessionId(),
            'stripe_payment_intent_id' => $checkoutSession->getProviderPaymentIntentId(),
            'provider_event_id' => $providerEventId,
            'provider_event_type' => $providerEventType,
            'error_message' => $checkoutSession->getErrorMessage(),
        ]);
        $this->criticalAlertDispatcher->dispatch('stripe.payment_failed', [
            'session_id' => null,
            'order_number' => $checkoutSession->getSyliusOrderNumber(),
            'payment_id' => (string) $payment->getId(),
            'provider_order_id' => null,
            'message' => $checkoutSession->getErrorMessage(),
            'stripe_checkout_session_id' => $checkoutSession->getProviderSessionId(),
            'stripe_payment_intent_id' => $checkoutSession->getProviderPaymentIntentId(),
            'provider_event_id' => $providerEventId,
            'provider_event_type' => $providerEventType,
        ]);
    }
}
