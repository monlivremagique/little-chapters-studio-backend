<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Fulfillment\FulfillmentWebhookEvent;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationPreviewArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Personalization\PreviewVersionFactory;
use App\Production\PostPaymentProductionOrchestrator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PdfArtifactAccessControllerTest extends WebTestCase
{
    public function testPdfIsStoredPrivatelyAndServedThroughControlledEndpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var PreviewVersionFactory $previewVersionFactory */
        $previewVersionFactory = static::getContainer()->get(PreviewVersionFactory::class);
        /** @var PostPaymentProductionOrchestrator $orchestrator */
        $orchestrator = static::getContainer()->get(PostPaymentProductionOrchestrator::class);

        $session = new PersonalizationSession('b1', sprintf('pdf-owner-%s', bin2hex(random_bytes(4))));
        $session->saveContent('Nora', 'Pour toi', [], 3);
        $job = new PersonalizationGenerationJob($session, 'replicate', 1, 'black-forest-labs/flux-2-pro');
        $job->complete('succeeded', ['state' => ['totalPageCount' => 1, 'generatedPageCount' => 1]]);
        $artifact = new PersonalizationPreviewArtifact($session, $job, 1, 'Couverture', true, '/uploads/books/aventure-enchantee/cover-default.svg', 'image/svg+xml');
        $entityManager->persist($session);
        $entityManager->persist($job);
        $entityManager->persist($artifact);
        $entityManager->flush();

        $previewVersionFactory->createApprovedVersion($session, $job);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        $orderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderNumber = sprintf('PDF-%s', bin2hex(random_bytes(3)));
        $this->insertCompletedOrderWithShippingAddress($connection, $orderId, $orderNumber);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $entityManager->flush();

        $orchestrator->processPaidSessions([$session]);
        $entityManager->flush();
        $entityManager->clear();

        /** @var PdfArtifact $pdf */
        $pdf = $entityManager->getRepository(PdfArtifact::class)->findOneBy(['session' => $entityManager->getReference(PersonalizationSession::class, $session->getId())]);
        self::assertStringContainsString('/var/storage/personalizations/pdfs/', $pdf->getStoragePath());
        self::assertStringStartsWith('/api/personalization/pdfs/', $pdf->getPublicPath());

        $client->request('GET', $pdf->getPublicPath());
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('content-type'));
    }

    public function testGelatoWebhookProcessingIsIdempotent(): void
    {
        putenv('GELATO_WEBHOOK_SECRET=test-gelato-webhook-secret');
        $_ENV['GELATO_WEBHOOK_SECRET'] = 'test-gelato-webhook-secret';
        $_SERVER['GELATO_WEBHOOK_SECRET'] = 'test-gelato-webhook-secret';

        $client = static::createClient();
        $client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var PreviewVersionFactory $previewVersionFactory */
        $previewVersionFactory = static::getContainer()->get(PreviewVersionFactory::class);
        /** @var PostPaymentProductionOrchestrator $orchestrator */
        $orchestrator = static::getContainer()->get(PostPaymentProductionOrchestrator::class);

        $session = new PersonalizationSession('b1', sprintf('gelato-owner-%s', bin2hex(random_bytes(4))));
        $session->saveContent('Nora', 'Pour toi', [], 3);
        $job = new PersonalizationGenerationJob($session, 'replicate', 1, 'black-forest-labs/flux-2-pro');
        $job->complete('succeeded', ['state' => ['totalPageCount' => 1, 'generatedPageCount' => 1]]);
        $artifact = new PersonalizationPreviewArtifact($session, $job, 1, 'Couverture', true, '/uploads/books/aventure-enchantee/cover-default.svg', 'image/svg+xml');
        $entityManager->persist($session);
        $entityManager->persist($job);
        $entityManager->persist($artifact);
        $entityManager->flush();

        $previewVersionFactory->createApprovedVersion($session, $job);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        $orderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderNumber = sprintf('GLT-%s', bin2hex(random_bytes(3)));
        $this->insertCompletedOrderWithShippingAddress($connection, $orderId, $orderNumber);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $entityManager->flush();

        $orchestrator->processPaidSessions([$session]);
        $entityManager->flush();
        $entityManager->clear();

        $payload = [
            'eventId' => 'evt_gelato_same_001',
            'orderReferenceId' => $orderNumber,
            'itemReferenceId' => $session->getId(),
            'status' => 'shipped',
            'trackingUrl' => 'https://tracking.example.test/parcel',
            'trackingNumber' => 'TRACK-001',
        ];

        $client->request('POST', '/api/custom/fulfillment/gelato/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GELATO_WEBHOOK_SECRET' => 'test-gelato-webhook-secret',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/custom/fulfillment/gelato/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GELATO_WEBHOOK_SECRET' => 'test-gelato-webhook-secret',
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        self::assertSame(1, $entityManager->getRepository(FulfillmentWebhookEvent::class)->count(['eventKey' => 'gelato:evt_gelato_same_001']));
    }

    private function insertCompletedOrderWithShippingAddress(Connection $connection, int $orderId, string $orderNumber): void
    {
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $customerId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_customer') + random_int(1000, 5000);
        $addressId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $email = sprintf('customer-%d@example.test', $customerId);

        $connection->executeStatement("INSERT INTO sylius_customer (id, email, email_canonical, first_name, last_name, gender, created_at, updated_at, subscribed_to_newsletter) VALUES (:id, :email, :email, 'Nora', 'Dupont', 'u', :createdAt, :updatedAt, FALSE)", ['id' => $customerId, 'email' => $email, 'createdAt' => $now, 'updatedAt' => $now]);
        $connection->executeStatement("INSERT INTO sylius_address (id, customer_id, first_name, last_name, phone_number, street, city, postcode, country_code, created_at, updated_at) VALUES (:id, :customerId, 'Nora', 'Dupont', '+32470000000', 'Rue du Test 1', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt)", ['id' => $addressId, 'customerId' => $customerId, 'createdAt' => $now, 'updatedAt' => $now]);
        $connection->executeStatement("INSERT INTO sylius_order (id, shipping_address_id, channel_id, customer_id, number, state, items_total, adjustments_total, total, created_at, updated_at, currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest, abandoned_email, token_value, checkout_completed_at) VALUES (:id, :addressId, :channelId, :customerId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt, 'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE, FALSE, :tokenValue, :completedAt)", ['id' => $orderId, 'addressId' => $addressId, 'channelId' => $channelId, 'customerId' => $customerId, 'number' => $orderNumber, 'createdAt' => $now, 'updatedAt' => $now, 'tokenValue' => sprintf('token-%d', $orderId), 'completedAt' => $now]);
    }
}
