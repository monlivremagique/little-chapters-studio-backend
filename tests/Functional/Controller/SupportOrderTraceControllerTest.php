<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Fulfillment\FulfillmentOrder;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationGenerationJob;
use App\Entity\Personalization\PersonalizationGenerationJobStatus;
use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PreviewVersion;
use App\Support\OperationalEventRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SupportOrderTraceControllerTest extends WebTestCase
{
    public function testSupportTraceEndpointReturnsCorrelatedOrderArtifacts(): void
    {
        putenv('SUPPORT_OPERATIONS_TOKEN=test-support-token');
        $_ENV['SUPPORT_OPERATIONS_TOKEN'] = 'test-support-token';
        $_SERVER['SUPPORT_OPERATIONS_TOKEN'] = 'test-support-token';

        $client = static::createClient();
        $client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var OperationalEventRecorder $recorder */
        $recorder = static::getContainer()->get(OperationalEventRecorder::class);

        $session = new PersonalizationSession('b1', sprintf('trace-token-%s', bin2hex(random_bytes(4))));
        $session->saveContent('Nora', 'Pour toi', [], 3);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        $job = new PersonalizationGenerationJob($session, 'replicate', 1, 'black-forest-labs/flux-2-pro');
        $job->complete('succeeded', ['state' => ['totalPageCount' => 1, 'generatedPageCount' => 1]]);

        $entityManager->persist($session);
        $entityManager->persist($job);
        $entityManager->flush();

        [$orderId, $orderNumber, $paymentId, $orderTokenValue, $orderItemId, $variantCode] = $this->insertCompletedOrderWithPayment($connection);
        $session->attachToCart($orderTokenValue, (string) $orderItemId);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $link = new PersonalizationOrderItemLink($session, $orderItemId);
        $link->snapshotOrderItem([
            'order_item_id' => $orderItemId,
            'order_token_value' => $orderTokenValue,
            'variant_code' => $variantCode,
            'product_name' => "L'Aventure Enchantee",
            'unit_price' => 4990,
            'quantity' => 1,
            'currency_code' => 'EUR',
        ]);
        $entityManager->persist($link);

        $version = new PreviewVersion($session, $job, 1, 'Nora', 'Pour toi', [
            'sessionId' => $session->getId(),
            'pages' => [],
        ]);
        $entityManager->persist($version);
        $pdf = new PdfArtifact($session, $version, '/tmp/test.pdf', '/api/personalization/pdfs/token', 'token', str_repeat('a', 64), 1234);
        $entityManager->persist($pdf);
        $fulfillment = new FulfillmentOrder($session, $pdf, $orderNumber, ['request' => 'payload']);
        $fulfillment->markSubmitted('provider-order-123', ['provider' => 'gelato']);
        $entityManager->persist($fulfillment);
        $recorder->record('stripe.payment_completed', 'info', $session->getId(), $orderNumber, [
            'payment_id' => (string) $paymentId,
            'provider_order_id' => $fulfillment->getProviderOrderId(),
            'pdf_artifact_id' => '1',
        ]);
        $entityManager->persist(new StripeCheckoutSession('cs_trace_123', $orderId, $orderNumber, $orderTokenValue, $paymentId, 4990, 'EUR', null, $session->getGuestOwnerToken()));
        $entityManager->flush();

        $client->request('GET', sprintf('/api/custom/support/orders/%s/trace', $orderNumber), server: [
            'HTTP_X_SUPPORT_TOKEN' => 'test-support-token',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($orderNumber, $payload['orderNumber']);
        self::assertSame($session->getId(), $payload['sessionIds'][0]);
        self::assertSame((string) $paymentId, (string) $payload['events'][0]['context']['payment_id']);
        self::assertSame('provider-order-123', $payload['events'][0]['context']['provider_order_id']);
        self::assertSame('provider-order-123', $payload['fulfillments'][$session->getId()][0]['providerOrderId']);
    }

    /** @return array{int,string,int,string,int,string} */
    private function insertCompletedOrderWithPayment(Connection $connection): array
    {
        $orderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderItemId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order_item') + random_int(1000, 5000);
        $paymentId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_payment') + random_int(1000, 5000);
        $customerId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_customer') + random_int(1000, 5000);
        $addressId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $variantId = (int) $connection->fetchOne('SELECT id FROM sylius_product_variant ORDER BY id ASC LIMIT 1');
        $variantCode = (string) $connection->fetchOne('SELECT code FROM sylius_product_variant WHERE id = :variantId', ['variantId' => $variantId]);
        $paymentMethodId = (int) $connection->fetchOne('SELECT id FROM sylius_payment_method ORDER BY id ASC LIMIT 1');
        $orderNumber = sprintf('TRACE-%s', bin2hex(random_bytes(3)));
        $orderTokenValue = sprintf('trace-token-%d', $orderId);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $connection->executeStatement("INSERT INTO sylius_customer (id, email, email_canonical, first_name, last_name, gender, created_at, updated_at, subscribed_to_newsletter) VALUES (:id, :email, :email, 'Nora', 'Trace', 'u', :createdAt, :updatedAt, FALSE)", ['id' => $customerId, 'email' => sprintf('trace-%d@example.test', $customerId), 'createdAt' => $now, 'updatedAt' => $now]);
        $connection->executeStatement("INSERT INTO sylius_address (id, customer_id, first_name, last_name, phone_number, street, city, postcode, country_code, created_at, updated_at) VALUES (:id, :customerId, 'Nora', 'Trace', '+32470000000', 'Rue Trace 1', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt)", ['id' => $addressId, 'customerId' => $customerId, 'createdAt' => $now, 'updatedAt' => $now]);
        $connection->executeStatement("INSERT INTO sylius_order (id, shipping_address_id, channel_id, customer_id, number, state, items_total, adjustments_total, total, created_at, updated_at, currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest, abandoned_email, token_value, checkout_completed_at) VALUES (:id, :addressId, :channelId, :customerId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt, 'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE, FALSE, :tokenValue, :completedAt)", ['id' => $orderId, 'addressId' => $addressId, 'channelId' => $channelId, 'customerId' => $customerId, 'number' => $orderNumber, 'createdAt' => $now, 'updatedAt' => $now, 'tokenValue' => $orderTokenValue, 'completedAt' => $now]);
        $connection->executeStatement("INSERT INTO sylius_order_item (id, order_id, variant_id, quantity, unit_price, units_total, adjustments_total, total, is_immutable, product_name, variant_name, version) VALUES (:id, :orderId, :variantId, 1, 4990, 4990, 0, 4990, FALSE, 'L''Aventure Enchantee', 'Edition standard', 1)", ['id' => $orderItemId, 'orderId' => $orderId, 'variantId' => $variantId]);
        $connection->executeStatement("INSERT INTO sylius_payment (id, method_id, order_id, currency_code, amount, state, details, created_at, updated_at) VALUES (:id, :methodId, :orderId, 'EUR', 4990, 'completed', '{}', :createdAt, :updatedAt)", ['id' => $paymentId, 'methodId' => $paymentMethodId, 'orderId' => $orderId, 'createdAt' => $now, 'updatedAt' => $now]);

        return [$orderId, $orderNumber, $paymentId, $orderTokenValue, $orderItemId, $variantCode];
    }
}
