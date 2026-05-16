<?php

declare(strict_types=1);

namespace App\Tests\Functional\Production;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PersonalizationSessionStatus;
use App\Entity\Personalization\PreviewVersion;
use App\Entity\Support\OperationalEvent;
use App\Personalization\PreviewVersionFactory;
use App\Gelato\GelatoFulfillmentService;
use App\Production\PostPaymentProductionOrchestrator;
use App\Tests\Double\Gelato\FakeGelatoClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostPaymentProductionPipelineTest extends KernelTestCase
{
    public function testApprovedPreviewVersionPdfAndFulfillmentSubmissionArePersisted(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var PreviewVersionFactory $previewVersionFactory */
        $previewVersionFactory = $container->get(PreviewVersionFactory::class);
        /** @var PostPaymentProductionOrchestrator $orchestrator */
        $orchestrator = $container->get(PostPaymentProductionOrchestrator::class);
        /** @var GelatoFulfillmentService $gelatoFulfillmentService */
        $gelatoFulfillmentService = $container->get(GelatoFulfillmentService::class);
        /** @var FakeGelatoClient $fakeGelato */
        $fakeGelato = $container->get(FakeGelatoClient::class);

        $session = new PersonalizationSession('b1', sprintf('imp-lot-token-%s', bin2hex(random_bytes(4))));
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

        $entityManager->persist($session);
        $entityManager->persist($job);
        $entityManager->persist($artifact);
        $entityManager->flush();

        $version = $previewVersionFactory->createApprovedVersion($session, $job);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        $orderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderNumber = sprintf('IMP-PIPE-%s', bin2hex(random_bytes(3)));
        $this->insertCompletedOrderWithShippingAddress($connection, $orderId, $orderNumber);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $entityManager->flush();

        $orchestrator->processPaidSessions([$session]);
        $entityManager->flush();
        $entityManager->clear();

        /** @var PreviewVersion $storedVersion */
        $storedVersion = $entityManager->getRepository(PreviewVersion::class)->find($version->getId());
        self::assertNotNull($storedVersion->getContentHash());
        self::assertNotSame('', $storedVersion->getContentHash());

        /** @var PdfArtifact|null $pdfArtifact */
        $pdfArtifact = $entityManager->getRepository(PdfArtifact::class)->findOneBy([
            'previewVersion' => $storedVersion,
        ]);
        self::assertInstanceOf(PdfArtifact::class, $pdfArtifact);
        self::assertFileExists($pdfArtifact->getStoragePath());
        self::assertGreaterThan(1000, $pdfArtifact->getFileSize());
        self::assertSame(hash_file('sha256', $pdfArtifact->getStoragePath()), $pdfArtifact->getFileHash());

        /** @var FulfillmentOrder|null $fulfillment */
        $fulfillment = $entityManager->getRepository(FulfillmentOrder::class)->findOneBy([
            'session' => $storedVersion->getSession(),
        ]);
        self::assertInstanceOf(FulfillmentOrder::class, $fulfillment);
        self::assertSame('submitted', $fulfillment->getStatus());
        self::assertNotNull($fulfillment->getProviderOrderId());
        self::assertNull($fulfillment->getErrorMessage());
        self::assertCount(1, $fakeGelato->getCreatedOrders());
        $gelatoPayload = $fakeGelato->getCreatedOrders()[0];
        self::assertSame($orderNumber, $gelatoPayload['orderReferenceId']);
        self::assertSame('photobooks-hardcover_pf_200x200-mm-8x8-inch_pt_170-gsm-65lb-coated-silk_cl_4-4_ccl_4-4_bt_glued-left_ct_matt-lamination_prt_1-0_cpt_130-gsm-65-lb-cover-coated-silk_ver', $gelatoPayload['items'][0]['productUid']);
        self::assertSame('default', $gelatoPayload['items'][0]['files'][0]['type']);
        self::assertSame('BE', $gelatoPayload['shippingAddress']['country']);

        /** @var PersonalizationSession $storedSession */
        $storedSession = $entityManager->getRepository(PersonalizationSession::class)->find($storedVersion->getSession()->getId());
        self::assertSame(PersonalizationSessionStatus::SubmittedToGelato, $storedSession->getStatus());

        $gelatoFulfillmentService->applyWebhook([
            'event' => 'order_item_status_updated',
            'itemReferenceId' => $storedSession->getId(),
            'orderReferenceId' => $orderNumber,
            'status' => 'shipped',
            'trackingUrl' => 'https://tracking.example.test/parcel',
            'trackingNumber' => 'TRACK-001',
        ]);
        $entityManager->flush();
        $entityManager->clear();

        /** @var FulfillmentOrder $shippedFulfillment */
        $shippedFulfillment = $entityManager->getRepository(FulfillmentOrder::class)->find($fulfillment->getId());
        self::assertSame('shipped', $shippedFulfillment->getStatus());
        self::assertSame('https://tracking.example.test/parcel', $shippedFulfillment->getTrackingUrl());
        /** @var PersonalizationSession $shippedSession */
        $shippedSession = $entityManager->getRepository(PersonalizationSession::class)->find($storedSession->getId());
        self::assertSame(PersonalizationSessionStatus::Shipped, $shippedSession->getStatus());

        $events = $entityManager->getRepository(OperationalEvent::class)->findBy([
            'sessionId' => $shippedSession->getId(),
        ]);
        self::assertNotEmpty($events);
    }

    private function insertCompletedOrderWithShippingAddress(Connection $connection, int $orderId, string $orderNumber): void
    {
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $customerId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_customer') + random_int(1000, 5000);
        $addressId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $email = sprintf('gelato-%d@example.test', $customerId);

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_customer (
    id, email, email_canonical, first_name, last_name, gender, created_at, updated_at, subscribed_to_newsletter
) VALUES (
    :id, :email, :email, 'Nora', 'Dupont', 'u', :createdAt, :updatedAt, FALSE
)
SQL,
            [
                'id' => $customerId,
                'email' => $email,
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_address (
    id, customer_id, first_name, last_name, phone_number, street, city, postcode, country_code, created_at, updated_at
) VALUES (
    :id, :customerId, 'Nora', 'Dupont', '+32470000000', 'Rue du Test 1', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt
)
SQL,
            [
                'id' => $addressId,
                'customerId' => $customerId,
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order (
    id, shipping_address_id, channel_id, customer_id, number, state, items_total, adjustments_total, total, created_at, updated_at,
    currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest,
    abandoned_email, token_value, checkout_completed_at
) VALUES (
    :id, :addressId, :channelId, :customerId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt,
    'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE,
    FALSE, :tokenValue, :completedAt
)
SQL,
            [
                'id' => $orderId,
                'addressId' => $addressId,
                'channelId' => $channelId,
                'customerId' => $customerId,
                'number' => $orderNumber,
                'createdAt' => $now,
                'updatedAt' => $now,
                'tokenValue' => sprintf('imp-pipe-token-%d', $orderId),
                'completedAt' => $now,
            ],
        );
    }
}
