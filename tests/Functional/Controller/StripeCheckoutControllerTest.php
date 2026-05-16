<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Payment\Payment;
use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\Payment\StripePendingWebhookEvent;
use App\Entity\Payment\StripeWebhookEvent;
use App\Tests\Double\Stripe\FakeStripeCheckoutClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StripeCheckoutControllerTest extends WebTestCase
{
    public function testApprovedLinkedSessionCanCreateStripeCheckoutSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('sessionId', $payload);
        self::assertArrayHasKey('checkoutUrl', $payload);
        self::assertSame('open', $payload['status']);
        self::assertSame('unpaid', $payload['paymentStatus']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $checkoutSession = $entityManager->getRepository(StripeCheckoutSession::class)->findOneBy([
            'providerSessionId' => $payload['sessionId'],
        ]);

        self::assertInstanceOf(StripeCheckoutSession::class, $checkoutSession);
        self::assertSame($orderTokenValue, $checkoutSession->getSyliusOrderTokenValue());
    }

    public function testCheckoutSessionCreationFailsWhenLinkedSessionIsNotApproved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue] = $this->createCompletedOrderLinkedToSession($sessionId, false);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'approved',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testCheckoutSessionCreationFailsWhenApprovedContentWasModifiedAfterCartAttachment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('PATCH', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'childName' => 'Nora modifiee',
            'dedication' => 'Nouveau texte',
            'step' => 3,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Illegal personalization session transition', (string) $client->getResponse()->getContent());
    }

    public function testCheckoutSessionCreationFailsWhenCartItemChangedAfterApproval(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue, 'orderItemId' => $orderItemId] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE sylius_order_item SET unit_price = 5990, units_total = 5990, total = 5990 WHERE id = :orderItemId',
            ['orderItemId' => $orderItemId],
        );

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('changed after approval', (string) $client->getResponse()->getContent());
    }

    public function testReadingStripeCheckoutSessionSynchronizesSuccessfulPayment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue, 'paymentId' => $paymentId] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $providerSessionId = (string) $payload['sessionId'];

        /** @var FakeStripeCheckoutClient $fakeStripe */
        $fakeStripe = static::getContainer()->get(FakeStripeCheckoutClient::class);
        $fakeStripe->markSessionPaid($providerSessionId);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', sprintf('/api/custom/payments/stripe/checkout-sessions/%s', $providerSessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $statusPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($statusPayload['paid']);
        self::assertSame('paid', $statusPayload['paymentStatus']);
        self::assertSame('complete', $statusPayload['status']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var Payment $payment */
        $payment = $entityManager->getRepository(Payment::class)->find($paymentId);
        self::assertSame('completed', $payment->getState());
    }

    public function testReadingStripeCheckoutSessionSynchronizesExpiredPaymentFailure(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue, 'paymentId' => $paymentId] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $providerSessionId = (string) $payload['sessionId'];

        /** @var FakeStripeCheckoutClient $fakeStripe */
        $fakeStripe = static::getContainer()->get(FakeStripeCheckoutClient::class);
        $fakeStripe->markSessionExpired($providerSessionId);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', sprintf('/api/custom/payments/stripe/checkout-sessions/%s', $providerSessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $statusPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($statusPayload['paid']);
        self::assertSame('expired', $statusPayload['status']);
        self::assertSame('unpaid', $statusPayload['paymentStatus']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var Payment $payment */
        $payment = $entityManager->getRepository(Payment::class)->find($paymentId);
        self::assertNotSame('completed', $payment->getState());
    }

    public function testCreatingStripeCheckoutSessionTwiceReusesOpenSessionForSameOrder(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $firstPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($firstPayload['sessionId'], $secondPayload['sessionId']);
        self::assertSame($firstPayload['checkoutUrl'], $secondPayload['checkoutUrl']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $sessions = $entityManager->getRepository(StripeCheckoutSession::class)->findBy([
            'syliusOrderTokenValue' => $orderTokenValue,
        ]);

        self::assertCount(1, $sessions);
    }

    public function testStripeWebhookIsIdempotent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue, 'paymentId' => $paymentId] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $providerSessionId = (string) $payload['sessionId'];

        /** @var FakeStripeCheckoutClient $fakeStripe */
        $fakeStripe = static::getContainer()->get(FakeStripeCheckoutClient::class);
        $fakeStripe->markSessionPaid($providerSessionId, 'pi_test_paid_001');
        $providerSessionPayload = $fakeStripe->retrieveCheckoutSession($providerSessionId);

        $providerEventId = sprintf('evt_test_checkout_completed_%s', bin2hex(random_bytes(6)));
        $webhookPayload = [
            'id' => $providerEventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => $providerSessionPayload,
            ],
        ];

        $client->request('POST', '/api/custom/payments/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 'test-signature',
        ], content: json_encode($webhookPayload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/custom/payments/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 'test-signature',
        ], content: json_encode($webhookPayload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var Payment $payment */
        $payment = $entityManager->getRepository(Payment::class)->find($paymentId);
        self::assertSame('completed', $payment->getState());

        $webhookEvents = $entityManager->getRepository(StripeWebhookEvent::class)->findBy([
            'providerEventId' => $providerEventId,
        ]);
        self::assertCount(1, $webhookEvents);
    }

    public function testUnknownPaidStripeWebhookIsStoredThenReplayedAfterLocalSessionCreation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        [
            'orderTokenValue' => $orderTokenValue,
            'paymentId' => $paymentId,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
        ] = $this->createCompletedOrderLinkedToSession($sessionId, true);
        $providerSessionId = sprintf('cs_test_pending_%s', bin2hex(random_bytes(5)));
        $providerEventId = sprintf('evt_test_pending_%s', bin2hex(random_bytes(5)));

        $webhookPayload = [
            'id' => $providerEventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $providerSessionId,
                    'status' => 'complete',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_test_pending_replay',
                    'amount_total' => 5480,
                    'currency' => 'eur',
                    'metadata' => [
                        'sylius_order_id' => (string) $orderId,
                        'sylius_order_number' => $orderNumber,
                        'sylius_order_token_value' => $orderTokenValue,
                        'sylius_payment_id' => (string) $paymentId,
                    ],
                ],
            ],
        ];

        $client->request('POST', '/api/custom/payments/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 'test-signature',
        ], content: json_encode($webhookPayload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $pendingEvent = $entityManager->getRepository(StripePendingWebhookEvent::class)->findOneBy([
            'providerEventId' => $providerEventId,
        ]);
        self::assertInstanceOf(StripePendingWebhookEvent::class, $pendingEvent);
        self::assertTrue($pendingEvent->isPending());

        /** @var FakeStripeCheckoutClient $fakeStripe */
        $fakeStripe = static::getContainer()->get(FakeStripeCheckoutClient::class);
        $fakeStripe->setNextSessionId($providerSessionId);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($providerSessionId, $payload['sessionId']);
        self::assertTrue($payload['paid']);
        self::assertSame('complete', $payload['status']);

        $entityManager->clear();
        /** @var Payment $payment */
        $payment = $entityManager->getRepository(Payment::class)->find($paymentId);
        self::assertSame('completed', $payment->getState());
        /** @var StripePendingWebhookEvent $processedEvent */
        $processedEvent = $entityManager->getRepository(StripePendingWebhookEvent::class)->findOneBy([
            'providerEventId' => $providerEventId,
        ]);
        self::assertFalse($processedEvent->isPending());
    }

    public function testPaidStripeSessionWithAmountMismatchDoesNotCompletePayment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createOwnedSession($client);
        ['orderTokenValue' => $orderTokenValue, 'paymentId' => $paymentId] = $this->createCompletedOrderLinkedToSession($sessionId, true);

        $client->request('POST', '/api/custom/payments/stripe/checkout-sessions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'orderTokenValue' => $orderTokenValue,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $providerSessionId = (string) $payload['sessionId'];

        /** @var FakeStripeCheckoutClient $fakeStripe */
        $fakeStripe = static::getContainer()->get(FakeStripeCheckoutClient::class);
        $fakeStripe->markSessionPaid($providerSessionId, 'pi_test_bad_amount');
        $fakeStripe->patchSession($providerSessionId, ['amount_total' => 1]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('GET', sprintf('/api/custom/payments/stripe/checkout-sessions/%s', $providerSessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $statusPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($statusPayload['paid']);
        self::assertSame('failed', $statusPayload['status']);
        self::assertStringContainsString('amount_total expected 5480', (string) $statusPayload['errorMessage']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var Payment $payment */
        $payment = $entityManager->getRepository(Payment::class)->find($paymentId);
        self::assertNotSame('completed', $payment->getState());
    }

    /**
     * @return array{id:string, ownerToken:string}
     */
    private function createOwnedSession(object $client): array
    {
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
            'bookLocale' => 'fr',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $payload['id'],
            'ownerToken' => (string) $payload['ownerToken'],
        ];
    }

    /**
     * @return array{orderTokenValue:string,paymentId:int,orderItemId:int,orderId:int,orderNumber:string}
     */
    private function createCompletedOrderLinkedToSession(string $sessionId, bool $approved): array
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $nextOrderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $nextOrderItemId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order_item') + random_int(1000, 5000);
        $nextPaymentId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_payment') + random_int(1000, 5000);
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $variantId = (int) $connection->fetchOne('SELECT id FROM sylius_product_variant ORDER BY id ASC LIMIT 1');
        $variantCode = (string) $connection->fetchOne('SELECT code FROM sylius_product_variant WHERE id = :variantId', [
            'variantId' => $variantId,
        ]);
        $paymentMethodId = (int) $connection->fetchOne('SELECT id FROM sylius_payment_method ORDER BY id ASC LIMIT 1');
        $orderNumber = sprintf('IMP003-%d', $nextOrderId);
        $orderTokenValue = sprintf('imp003-cart-token-%d', $nextOrderId);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order (
    id, channel_id, number, state, items_total, adjustments_total, total, created_at, updated_at,
    currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest,
    abandoned_email, token_value, checkout_completed_at
) VALUES (
    :id, :channelId, :number, 'new', 4990, 0, 5480, :createdAt, :updatedAt,
    'EUR', 'fr_FR', 'completed', 'awaiting_payment', 'ready', TRUE, FALSE, :tokenValue, :completedAt
)
SQL,
            [
                'id' => $nextOrderId,
                'channelId' => $channelId,
                'number' => $orderNumber,
                'createdAt' => $now,
                'updatedAt' => $now,
                'tokenValue' => $orderTokenValue,
                'completedAt' => $now,
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order_item (
    id, order_id, variant_id, quantity, unit_price, units_total, adjustments_total, total,
    is_immutable, product_name, variant_name, version
) VALUES (
    :id, :orderId, :variantId, 1, 4990, 4990, 0, 4990,
    FALSE, 'Test personalization book', 'Edition standard', 1
)
SQL,
            [
                'id' => $nextOrderItemId,
                'orderId' => $nextOrderId,
                'variantId' => $variantId,
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_payment (
    id, method_id, order_id, currency_code, amount, state, details, created_at, updated_at
) VALUES (
    :id, :methodId, :orderId, 'EUR', 5480, 'new', :details, :createdAt, :updatedAt
)
SQL,
            [
                'id' => $nextPaymentId,
                'methodId' => $paymentMethodId,
                'orderId' => $nextOrderId,
                'details' => json_encode([], JSON_THROW_ON_ERROR),
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
        );

        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $session->saveContent('Nora', 'Pour toi', [], 3);

        if ($approved) {
            $session->markGenerationRequested();
            $session->markGenerating();
            $session->markPreviewReady();
            $session->approve();
        }

        if ($approved) {
            $session->attachToCart($orderTokenValue, (string) $nextOrderItemId);
            $session->markCheckoutCompleted($nextOrderId, $orderNumber);
        }

        $link = new PersonalizationOrderItemLink($session, $nextOrderItemId);
        $link->snapshotOrderItem([
            'order_item_id' => $nextOrderItemId,
            'order_token_value' => $orderTokenValue,
            'variant_code' => $variantCode,
            'product_name' => 'Test personalization book',
            'unit_price' => 4990,
            'quantity' => 1,
            'currency_code' => 'EUR',
        ]);
        $entityManager->persist($link);
        $entityManager->flush();

        return [
            'orderTokenValue' => $orderTokenValue,
            'paymentId' => $nextPaymentId,
            'orderItemId' => $nextOrderItemId,
            'orderId' => $nextOrderId,
            'orderNumber' => $orderNumber,
        ];
    }
}
