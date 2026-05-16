<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Entity\Customer\Customer;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\Payment\StripeCheckoutSessionStatus;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\User\ShopUser;
use App\Personalization\PersonalizationPrePaymentGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeCheckoutManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeCheckoutClientInterface $stripeCheckoutClient,
        private readonly Security $security,
        private readonly PersonalizationPrePaymentGuard $personalizationPrePaymentGuard,
        #[Autowire('%env(default::FRONTEND_BASE_URL)%')]
        ?string $frontendBaseUrl,
    ) {
        $this->frontendBaseUrl = '' !== trim((string) $frontendBaseUrl) ? trim((string) $frontendBaseUrl) : 'http://localhost:8080';
    }

    private readonly string $frontendBaseUrl;

    /**
     * @param list<PersonalizationSession> $linkedSessions
     *
     * @return array{checkoutSession: StripeCheckoutSession, payload: array<string, mixed>}
     */
    public function createForOrder(Order $order, array $linkedSessions): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($order, $linkedSessions): array {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getCheckoutState() !== 'completed') {
                throw new \RuntimeException('The order must be completed before creating a Stripe checkout session.');
            }

            if (null === $order->getNumber() || '' === trim($order->getNumber())) {
                throw new \RuntimeException('The order number is missing.');
            }

            $payment = $this->resolvePayment($order);

            if (null === $payment) {
                throw new \RuntimeException('No payable payment exists for this order.');
            }

            $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);

            if ($payment->getState() === 'completed' || $order->getPaymentState() === 'paid') {
                throw new \RuntimeException('This order has already been paid.');
            }

            $this->personalizationPrePaymentGuard->assertOrderCanStartPayment($order, $linkedSessions);

            $reusableCheckoutSession = $this->findReusableCheckoutSession($payment);

            if ($reusableCheckoutSession instanceof StripeCheckoutSession) {
                return [
                    'checkoutSession' => $reusableCheckoutSession,
                    'payload' => [],
                ];
            }

            [$ownerCustomer, $guestOwnerToken] = $this->resolveOwnershipContext($linkedSessions);
            $checkoutSessionPayload = $this->stripeCheckoutClient->createCheckoutSession([
                'mode' => 'payment',
                'payment_method_types' => ['card', 'bancontact'],
                'success_url' => $this->buildSuccessUrl(),
                'cancel_url' => $this->buildCancelUrl(),
                'customer_email' => $order->getCustomer()?->getEmail(),
                'line_items' => $this->buildLineItems($order),
                'metadata' => [
                    'sylius_order_id' => (string) $order->getId(),
                    'sylius_order_number' => (string) $order->getNumber(),
                    'sylius_order_token_value' => (string) $order->getTokenValue(),
                    'sylius_payment_id' => (string) $payment->getId(),
                    'personalization_session_ids' => implode(',', array_map(
                        static fn (PersonalizationSession $session): string => $session->getId(),
                        $linkedSessions,
                    )),
                ],
            ]);

            $stripeCheckoutSession = new StripeCheckoutSession(
                (string) $checkoutSessionPayload['id'],
                (int) $order->getId(),
                (string) $order->getNumber(),
                (string) $order->getTokenValue(),
                (int) $payment->getId(),
                (int) $order->getTotal(),
                (string) $order->getCurrencyCode(),
                $ownerCustomer,
                $guestOwnerToken,
            );
            $stripeCheckoutSession->markOpen(
                (string) ($checkoutSessionPayload['url'] ?? ''),
                (string) ($checkoutSessionPayload['payment_status'] ?? 'unpaid'),
                isset($checkoutSessionPayload['payment_intent']) ? (string) $checkoutSessionPayload['payment_intent'] : null,
            );

            $this->entityManager->persist($stripeCheckoutSession);
            $this->entityManager->flush();

            return [
                'checkoutSession' => $stripeCheckoutSession,
                'payload' => $checkoutSessionPayload,
            ];
        });
    }

    private function findReusableCheckoutSession(Payment $payment): ?StripeCheckoutSession
    {
        /** @var StripeCheckoutSession|null $checkoutSession */
        $checkoutSession = $this->entityManager->getRepository(StripeCheckoutSession::class)->findOneBy(
            [
                'syliusPaymentId' => (int) $payment->getId(),
                'status' => StripeCheckoutSessionStatus::Open->value,
            ],
            ['id' => 'DESC'],
        );

        if (
            !$checkoutSession instanceof StripeCheckoutSession
            || null === $checkoutSession->getCheckoutUrl()
            || '' === trim($checkoutSession->getCheckoutUrl())
        ) {
            return null;
        }

        return $checkoutSession;
    }

    private function buildSuccessUrl(): string
    {
        return rtrim($this->frontendBaseUrl, '/').'/confirmation?stripe_session_id={CHECKOUT_SESSION_ID}';
    }

    private function buildCancelUrl(): string
    {
        return rtrim($this->frontendBaseUrl, '/').'/confirmation?stripe_session_id={CHECKOUT_SESSION_ID}&payment_cancelled=1';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(Order $order): array
    {
        $lineItems = [];
        $computedTotal = 0;

        foreach ($order->getItems() as $item) {
            $unitAmount = (int) $item->getUnitPrice();
            $quantity = (int) $item->getQuantity();
            $computedTotal += $unitAmount * $quantity;

            $lineItems[] = [
                'price_data' => [
                    'currency' => mb_strtolower((string) $order->getCurrencyCode()),
                    'unit_amount' => $unitAmount,
                    'product_data' => [
                        'name' => (string) $item->getProductName(),
                    ],
                ],
                'quantity' => $quantity,
            ];
        }

        if ($order->getShippingTotal() > 0) {
            $computedTotal += (int) $order->getShippingTotal();
            $lineItems[] = [
                'price_data' => [
                    'currency' => mb_strtolower((string) $order->getCurrencyCode()),
                    'unit_amount' => (int) $order->getShippingTotal(),
                    'product_data' => [
                        'name' => 'Livraison standard',
                    ],
                ],
                'quantity' => 1,
            ];
        }

        $adjustmentDelta = (int) $order->getTotal() - $computedTotal;

        if ($adjustmentDelta < 0) {
            throw new \RuntimeException('Stripe checkout does not support negative order adjustments in the current MVP flow.');
        }

        if ($adjustmentDelta > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => mb_strtolower((string) $order->getCurrencyCode()),
                    'unit_amount' => $adjustmentDelta,
                    'product_data' => [
                        'name' => 'Ajustement de commande',
                    ],
                ],
                'quantity' => 1,
            ];
        }

        return $lineItems;
    }

    private function resolvePayment(Order $order): ?Payment
    {
        foreach ($order->getPayments() as $payment) {
            if ($payment instanceof Payment && !in_array($payment->getState(), ['completed', 'cancelled'], true)) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param list<PersonalizationSession> $linkedSessions
     *
     * @return array{0:?Customer,1:?string}
     */
    private function resolveOwnershipContext(array $linkedSessions): array
    {
        if ([] === $linkedSessions) {
            $user = $this->security->getUser();

            if ($user instanceof ShopUser && $user->getCustomer() instanceof Customer) {
                return [$user->getCustomer(), null];
            }

            return [null, null];
        }

        $firstSession = $linkedSessions[0];
        $ownerCustomer = $firstSession->getOwnerCustomer();
        $guestOwnerToken = $firstSession->getGuestOwnerToken();

        foreach ($linkedSessions as $linkedSession) {
            $sameCustomer = (
                (null === $ownerCustomer && null === $linkedSession->getOwnerCustomer())
                || (null !== $ownerCustomer && null !== $linkedSession->getOwnerCustomer() && $ownerCustomer->getId() === $linkedSession->getOwnerCustomer()?->getId())
            );

            $sameGuestToken = $guestOwnerToken === $linkedSession->getGuestOwnerToken();

            if (!$sameCustomer || !$sameGuestToken) {
                throw new \RuntimeException('All linked personalization sessions must share the same ownership context.');
            }
        }

        return [$ownerCustomer, $guestOwnerToken];
    }
}
