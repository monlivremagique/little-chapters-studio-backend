<?php

declare(strict_types=1);

namespace App\Tests\Functional\MessageHandler;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Entity\Support\OperationalEvent;
use App\Message\TriggerFulfillmentMessage;
use App\MessageHandler\TriggerFulfillmentMessageHandler;
use App\Personalization\PreviewVersionFactory;
use App\Tests\Double\Gelato\FakeGelatoClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifies that TriggerFulfillmentMessageHandler correctly:
 *   - skips sessions in terminal states (idempotency)
 *   - marks Failed immediately when no preview version exists (permanent failure)
 *   - throws on Gelato API error (enabling Messenger retry via exponential backoff)
 *   - completes successfully end-to-end for a properly approved session
 *   - resets a previously-failed FulfillmentOrder on retry
 */
final class TriggerFulfillmentMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private PreviewVersionFactory $previewVersionFactory;
    private FakeGelatoClient $fakeGelato;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->previewVersionFactory = $container->get(PreviewVersionFactory::class);
        $this->fakeGelato = $container->get(FakeGelatoClient::class);
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->fakeGelato->reset();
    }

    public function testHandlerSkipsSessionAlreadyInTerminalState(): void
    {
        // Create a session and manually advance it to a terminal state.
        $session = $this->createApprovedSessionWithOrder();
        $session->markPdfRendering();
        $session->markPrintReady();
        $session->markSubmittedToGelato();
        $this->entityManager->flush();

        $message = new TriggerFulfillmentMessage($session->getId(), (string) $session->getSyliusOrderNumber());
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        // Handler must not have created a new FulfillmentOrder
        $fulfillments = $this->entityManager->getRepository(FulfillmentOrder::class)->findBy(['session' => $session->getId()]);
        self::assertCount(0, $fulfillments);
        // Gelato was never called
        self::assertCount(0, $this->fakeGelato->getCreatedOrders());
    }

    public function testHandlerMarksPermanentFailureWhenNoPreviewVersionExists(): void
    {
        // Session has a Sylius order but NO preview version — permanent failure.
        $session = new PersonalizationSession('b1', sprintf('no-preview-token-%s', bin2hex(random_bytes(4))));
        $session->saveContent('Nora', 'Pour toi', [], 3);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        [$orderId, $orderNumber] = $this->insertOrder();
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $this->entityManager->flush();

        $message = new TriggerFulfillmentMessage($session->getId(), (string) $session->getSyliusOrderNumber());
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        /** @var PersonalizationSession $reloaded */
        $reloaded = $this->entityManager->getRepository(PersonalizationSession::class)->find($session->getId());
        self::assertSame(PersonalizationSessionStatus::Failed, $reloaded->getStatus());

        // Must have recorded the failure in operational events
        $events = $this->entityManager->getRepository(OperationalEvent::class)->findBy([
            'sessionId' => $session->getId(),
            'type' => 'fulfillment.exhausted_no_preview',
        ]);
        self::assertNotEmpty($events);

        // No FulfillmentOrder should have been created
        $fulfillments = $this->entityManager->getRepository(FulfillmentOrder::class)->findBy(['session' => $session->getId()]);
        self::assertCount(0, $fulfillments);
    }

    public function testHandlerThrowsOnGelatoTransientFailureEnablingMessengerRetry(): void
    {
        $session = $this->createApprovedSessionWithOrder();
        $this->fakeGelato->setShouldFailNextCreate(true);

        $message = new TriggerFulfillmentMessage($session->getId(), (string) $session->getSyliusOrderNumber());

        // With sync:// transport, Messenger wraps handler exceptions in HandlerFailedException.
        // In production with async transport + retry_strategy, Messenger would retry the message.
        $this->expectException(HandlerFailedException::class);
        $this->messageBus->dispatch($message);
    }

    public function testHandlerSucceedsEndToEndForApprovedSession(): void
    {
        $session = $this->createApprovedSessionWithOrder();
        $orderNumber = (string) $session->getSyliusOrderNumber();

        $message = new TriggerFulfillmentMessage($session->getId(), $orderNumber);
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        /** @var PersonalizationSession $reloaded */
        $reloaded = $this->entityManager->getRepository(PersonalizationSession::class)->find($session->getId());
        self::assertSame(PersonalizationSessionStatus::SubmittedToGelato, $reloaded->getStatus());

        $fulfillment = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session->getId()]);
        self::assertInstanceOf(FulfillmentOrder::class, $fulfillment);
        self::assertSame('submitted', $fulfillment->getStatus());
        self::assertNotNull($fulfillment->getProviderOrderId());
        self::assertNull($fulfillment->getErrorMessage());

        $pdf = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy(['session' => $session->getId()]);
        self::assertInstanceOf(PdfArtifact::class, $pdf);
        self::assertFileExists($pdf->getStoragePath());

        self::assertCount(1, $this->fakeGelato->getCreatedOrders());
        self::assertSame($orderNumber, $this->fakeGelato->getCreatedOrders()[0]['orderReferenceId']);
    }

    public function testHandlerIsIdempotentWhenDispatchedTwice(): void
    {
        $session = $this->createApprovedSessionWithOrder();
        $message = new TriggerFulfillmentMessage($session->getId(), (string) $session->getSyliusOrderNumber());

        // First dispatch — succeeds
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        // Second dispatch — session is now SubmittedToGelato (terminal) — handler skips
        $this->fakeGelato->reset();
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        // Only one FulfillmentOrder, only one Gelato call in total across both dispatches
        $fulfillments = $this->entityManager->getRepository(FulfillmentOrder::class)->findBy(['session' => $session->getId()]);
        self::assertCount(1, $fulfillments);
        self::assertCount(0, $this->fakeGelato->getCreatedOrders());
    }

    public function testHandlerRetriesSuccessfullyAfterTransientGelatoFailure(): void
    {
        $session = $this->createApprovedSessionWithOrder();
        $message = new TriggerFulfillmentMessage($session->getId(), (string) $session->getSyliusOrderNumber());

        // First attempt: Gelato fails — handler throws, nothing is flushed to DB.
        // (submitOrRetry throws before flush; the unflushed FulfillmentOrder entity stays in UoW only.)
        $this->fakeGelato->setShouldFailNextCreate(true);
        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException) {
            // Expected — Messenger retries in production with exponential backoff.
        }

        // No FulfillmentOrder in DB yet (failure happened before flush).
        $this->entityManager->clear();
        $noOrder = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session->getId()]);
        self::assertNull($noOrder);
        self::assertCount(0, $this->fakeGelato->getCreatedOrders());

        // Second attempt: Gelato succeeds (simulating Messenger retry after backoff).
        $this->messageBus->dispatch($message);
        $this->entityManager->clear();

        /** @var PersonalizationSession $reloaded */
        $reloaded = $this->entityManager->getRepository(PersonalizationSession::class)->find($session->getId());
        self::assertSame(PersonalizationSessionStatus::SubmittedToGelato, $reloaded->getStatus());

        $retriedOrder = $this->entityManager->getRepository(FulfillmentOrder::class)->findOneBy(['session' => $session->getId()]);
        self::assertInstanceOf(FulfillmentOrder::class, $retriedOrder);
        self::assertSame('submitted', $retriedOrder->getStatus());
        self::assertCount(1, $this->fakeGelato->getCreatedOrders());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createApprovedSessionWithOrder(): PersonalizationSession
    {
        $session = new PersonalizationSession('b1', sprintf('hndlr-token-%s', bin2hex(random_bytes(4))));
        $session->saveContent('Nora', 'Pour toi', [], 3);

        $job = new PersonalizationGenerationJob($session, 'replicate', 1, 'black-forest-labs/flux-2-pro');
        $job->complete('succeeded', ['state' => ['totalPageCount' => 1, 'generatedPageCount' => 1]]);

        $artifact = new PersonalizationPreviewArtifact(
            $session,
            $job,
            1,
            'Couverture personnalisee pour Nora',
            true,
            '/uploads/books/aventure-enchantee/cover-default.svg',
            'image/svg+xml',
        );

        $this->entityManager->persist($session);
        $this->entityManager->persist($job);
        $this->entityManager->persist($artifact);
        $this->entityManager->flush();

        $this->previewVersionFactory->createApprovedVersion($session, $job);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();

        [$orderId, $orderNumber] = $this->insertOrder();
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Inserts a minimal completed Sylius order row and returns the [orderId, orderNumber] pair.
     * Caller is responsible for calling $session->markCheckoutCompleted($orderId, $orderNumber).
     *
     * @return array{int, string}
     */
    private function insertOrder(): array
    {
        $channelId = (int) $this->connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $customerId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_customer') + random_int(1000, 5000);
        $addressId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $orderId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderNumber = sprintf('HNDLR-%s', bin2hex(random_bytes(3)));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $email = sprintf('hndlr-%d@example.test', $customerId);

        $this->connection->executeStatement(
            "INSERT INTO sylius_customer (id, email, email_canonical, first_name, last_name, gender, created_at, updated_at, subscribed_to_newsletter) VALUES (:id, :email, :email, 'Nora', 'Test', 'u', :createdAt, :updatedAt, FALSE)",
            ['id' => $customerId, 'email' => $email, 'createdAt' => $now, 'updatedAt' => $now],
        );

        $this->connection->executeStatement(
            "INSERT INTO sylius_address (id, customer_id, first_name, last_name, phone_number, street, city, postcode, country_code, created_at, updated_at) VALUES (:id, :customerId, 'Nora', 'Test', '+32470000000', 'Rue du Test 1', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt)",
            ['id' => $addressId, 'customerId' => $customerId, 'createdAt' => $now, 'updatedAt' => $now],
        );

        $this->connection->executeStatement(
            "INSERT INTO sylius_order (id, shipping_address_id, channel_id, customer_id, number, state, items_total, adjustments_total, total, created_at, updated_at, currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest, abandoned_email, token_value, checkout_completed_at) VALUES (:id, :addressId, :channelId, :customerId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt, 'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE, FALSE, :tokenValue, :completedAt)",
            ['id' => $orderId, 'addressId' => $addressId, 'channelId' => $channelId, 'customerId' => $customerId, 'number' => $orderNumber, 'createdAt' => $now, 'updatedAt' => $now, 'tokenValue' => sprintf('hndlr-tok-%d', $orderId), 'completedAt' => $now],
        );

        return [$orderId, $orderNumber];
    }
}
