<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerOrderControllerTest extends WebTestCase
{
    public function testOwnerCanListAndReadOnlyOwnOrders(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        ['session' => $session, 'ownerToken' => $ownerToken] = $this->createOwnedLinkedOrder();
        $orderNumber = (string) $session->getSyliusOrderNumber();

        $client->request('GET', '/api/custom/orders', server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame($orderNumber, $payload[0]['orderNumber']);

        $client->request('GET', sprintf('/api/custom/orders/%s', $orderNumber), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $detailPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($orderNumber, $detailPayload['orderNumber']);
        self::assertSame($session->getId(), $detailPayload['sessions'][0]['id']);

        $client->request('GET', sprintf('/api/custom/orders/%s', $orderNumber), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => 'wrong-owner-token',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{session:PersonalizationSession, ownerToken:string} */
    private function createOwnedLinkedOrder(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $session = new PersonalizationSession('b1', sprintf('owner-%s', bin2hex(random_bytes(6))));
        $session->saveContent('Nora', 'Pour toi', [], 3);
        $session->markGenerationRequested();
        $session->markGenerating();
        $session->markPreviewReady();
        $session->approve();
        $entityManager->persist($session);
        $entityManager->flush();

        $orderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order') + random_int(1000, 5000);
        $orderItemId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_order_item') + random_int(1000, 5000);
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $variantId = (int) $connection->fetchOne('SELECT id FROM sylius_product_variant ORDER BY id ASC LIMIT 1');
        $paymentMethodId = (int) $connection->fetchOne('SELECT id FROM sylius_payment_method ORDER BY id ASC LIMIT 1');
        $addressId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM sylius_address') + random_int(1000, 5000);
        $orderNumber = sprintf('ORD-%s', bin2hex(random_bytes(4)));
        $tokenValue = sprintf('order-token-%d', $orderId);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $connection->executeStatement(
            "INSERT INTO sylius_address (id, first_name, last_name, street, city, postcode, country_code, created_at, updated_at) VALUES (:id, 'Nora', 'Dupont', 'Rue du Test 1', 'Bruxelles', '1000', 'BE', :createdAt, :updatedAt)",
            ['id' => $addressId, 'createdAt' => $now, 'updatedAt' => $now],
        );
        $connection->executeStatement(
            "INSERT INTO sylius_order (id, shipping_address_id, channel_id, number, state, items_total, adjustments_total, total, created_at, updated_at, currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest, abandoned_email, token_value, checkout_completed_at) VALUES (:id, :addressId, :channelId, :number, 'new', 3490, 590, 4080, :createdAt, :updatedAt, 'EUR', 'fr_FR', 'completed', 'paid', 'ready', TRUE, FALSE, :tokenValue, :completedAt)",
            ['id' => $orderId, 'addressId' => $addressId, 'channelId' => $channelId, 'number' => $orderNumber, 'createdAt' => $now, 'updatedAt' => $now, 'tokenValue' => $tokenValue, 'completedAt' => $now],
        );
        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order_item (
    id, order_id, variant_id, quantity, unit_price, units_total, adjustments_total, total,
    is_immutable, product_name, variant_name, version
) VALUES (
    :id, :orderId, :variantId, 1, 3490, 3490, 0, 3490,
    FALSE, :productName, 'Edition standard', 1
)
SQL,
            ['id' => $orderItemId, 'orderId' => $orderId, 'variantId' => $variantId, 'productName' => "L'Aventure Enchantee"],
        );
        $connection->executeStatement(
            "INSERT INTO sylius_payment (id, method_id, order_id, currency_code, amount, state, details, created_at, updated_at) VALUES (:id, :methodId, :orderId, 'EUR', 4080, 'completed', '{}', :createdAt, :updatedAt)",
            ['id' => $orderId + 100, 'methodId' => $paymentMethodId, 'orderId' => $orderId, 'createdAt' => $now, 'updatedAt' => $now],
        );

        $link = new PersonalizationOrderItemLink($session, $orderItemId);
        $link->snapshotOrderItem([
            'order_item_id' => $orderItemId,
            'order_id' => $orderId,
            'order_token_value' => $tokenValue,
            'variant_code' => 'variant',
            'product_name' => "L'Aventure Enchantee",
            'unit_price' => 3490,
            'quantity' => 1,
            'currency_code' => 'EUR',
        ]);
        $session->attachToCart($tokenValue, (string) $orderItemId);
        $session->markCheckoutCompleted($orderId, $orderNumber);
        $entityManager->persist($link);
        $entityManager->flush();

        return [
            'session' => $session,
            'ownerToken' => $session->getGuestOwnerToken(),
        ];
    }
}
