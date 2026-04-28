<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order\Order;
use App\Entity\Payment\StripeCheckoutSession;
use App\Personalization\PersonalizationOrderLinker;
use App\Personalization\PersonalizationSessionOwnershipGuard;
use App\Stripe\StripeCheckoutClientInterface;
use App\Stripe\StripeCheckoutManager;
use App\Stripe\StripeCheckoutOwnershipGuard;
use App\Stripe\StripeCheckoutSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeCheckoutController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonalizationOrderLinker $personalizationOrderLinker,
        private readonly StripeCheckoutManager $stripeCheckoutManager,
        private readonly StripeCheckoutSynchronizer $stripeCheckoutSynchronizer,
        private readonly StripeCheckoutClientInterface $stripeCheckoutClient,
        private readonly PersonalizationSessionOwnershipGuard $personalizationSessionOwnershipGuard,
        private readonly StripeCheckoutOwnershipGuard $stripeCheckoutOwnershipGuard,
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    #[Route(
        '/api/custom/payments/stripe/checkout-sessions',
        name: 'app_custom_stripe_checkout_sessions_create',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $payload = $this->readJsonPayload($request);
        $orderTokenValue = trim((string) ($payload['orderTokenValue'] ?? ''));

        if ('' === $orderTokenValue) {
            return $this->errorResponse('The "orderTokenValue" field is required.', Response::HTTP_BAD_REQUEST);
        }

        /** @var Order|null $order */
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'tokenValue' => $orderTokenValue,
        ]);

        if (!$order instanceof Order) {
            return $this->errorResponse('Order not found.', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->completeCheckoutIfPossible($order);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null === $order->getNumber()) {
            return $this->errorResponse('Order number was not assigned after checkout completion.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $linkedSessions = $this->personalizationOrderLinker->findSessionsByOrderNumber((string) $order->getNumber());
        $this->personalizationSessionOwnershipGuard->assertCanAccessSessions($linkedSessions, $request);

        try {
            $result = $this->stripeCheckoutManager->createForOrder($order, $linkedSessions);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var StripeCheckoutSession $checkoutSession */
        $checkoutSession = $result['checkoutSession'];

        return new JsonResponse($this->normalizeCheckoutSession($checkoutSession));
    }

    #[Route(
        '/api/custom/payments/stripe/checkout-sessions/{providerSessionId}',
        name: 'app_custom_stripe_checkout_sessions_read',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function readCheckoutSession(string $providerSessionId, Request $request): JsonResponse
    {
        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'providerSessionId' => $providerSessionId,
        ]);

        if (!$checkoutSession instanceof StripeCheckoutSession) {
            return $this->errorResponse('Stripe checkout session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->stripeCheckoutOwnershipGuard->assertCanAccessCheckoutSession($checkoutSession, $request);

        try {
            $checkoutSession = $this->stripeCheckoutSynchronizer->synchronizeFromProviderSessionId($providerSessionId) ?? $checkoutSession;
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalizeCheckoutSession($checkoutSession));
    }

    #[Route(
        '/api/custom/payments/stripe/webhook',
        name: 'app_custom_stripe_checkout_webhook',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = (string) $request->getContent();
        $signatureHeader = trim((string) $request->headers->get('Stripe-Signature', ''));

        try {
            $event = $this->stripeCheckoutClient->constructWebhookEvent($payload, $signatureHeader);
            $this->stripeCheckoutSynchronizer->handleWebhookEvent($event);
        } catch (\Throwable $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['received' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCheckoutSession(StripeCheckoutSession $checkoutSession): array
    {
        return [
            'sessionId' => $checkoutSession->getProviderSessionId(),
            'checkoutUrl' => $checkoutSession->getCheckoutUrl(),
            'status' => $checkoutSession->getStatus(),
            'paymentStatus' => $checkoutSession->getPaymentStatus(),
            'paid' => $checkoutSession->isPaid(),
            'orderId' => $checkoutSession->getSyliusOrderTokenValue(),
            'orderNumber' => $checkoutSession->getSyliusOrderNumber(),
            'paymentId' => $checkoutSession->getSyliusPaymentId(),
            'amountTotal' => $checkoutSession->getAmountTotal(),
            'currencyCode' => $checkoutSession->getCurrencyCode(),
            'providerPaymentIntentId' => $checkoutSession->getProviderPaymentIntentId(),
            'errorMessage' => $checkoutSession->getErrorMessage(),
            'completedAt' => $checkoutSession->getCompletedAt()?->format(DATE_ATOM),
            'expiredAt' => $checkoutSession->getExpiredAt()?->format(DATE_ATOM),
        ];
    }

    private function errorResponse(string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse(['message' => $message], $statusCode);
    }

    private function completeCheckoutIfPossible(Order $order): void
    {
        if ($order->getCheckoutState() === 'completed') {
            return;
        }

        foreach ([OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT, OrderCheckoutTransitions::TRANSITION_COMPLETE] as $transition) {
            if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition)) {
                $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
            }
        }

        $this->entityManager->flush();
    }
}
