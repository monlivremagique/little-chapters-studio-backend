<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Personalization\PersonalizationOrderItemLink;
use App\Entity\Personalization\PersonalizationSession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonalizationSessionOwnershipTest extends WebTestCase
{
    public function testGuestOwnerTokenIsRequiredToReadGuestSession(): void
    {
        $ownerClient = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($ownerClient);

        static::ensureKernelShutdown();
        $foreignClient = static::createClient();
        $foreignClient->request('GET', sprintf('/api/personalization/sessions/%s', $sessionId));
        self::assertResponseStatusCodeSame(404);

        static::ensureKernelShutdown();
        $foreignClient = static::createClient();
        $foreignClient->request('GET', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testWrongGuestOwnerTokenCannotMutateGuestSession(): void
    {
        $client = static::createClient();
        ['id' => $sessionId] = $this->createSession($client);

        $client->request('PATCH', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => 'wrong-owner-token',
        ], content: json_encode([
            'childName' => 'Lucas',
            'step' => 3,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
    }

    public function testMatchingGuestOwnerTokenAllowsMutation(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);

        $client->request('PATCH', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ], content: json_encode([
            'childName' => 'Emma',
            'dedication' => 'Pour toi',
            'step' => 3,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Emma', $payload['childName']);
        self::assertSame($ownerToken, $payload['ownerToken']);
    }

    public function testAuthenticatedCustomerCannotReadGuestSessionWithoutGuestOwnerToken(): void
    {
        $guestClient = static::createClient();
        ['id' => $sessionId] = $this->createSession($guestClient);
        static::ensureKernelShutdown();
        $customerToken = $this->registerCustomerAndReturnToken(static::createClient(), 'blocked');

        static::ensureKernelShutdown();
        $authenticatedClient = static::createClient();
        $authenticatedClient->request('GET', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $customerToken),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGuestSessionIsClaimedByAuthenticatedCustomerWhenOwnerTokenMatches(): void
    {
        $guestClient = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($guestClient);
        static::ensureKernelShutdown();
        $customerToken = $this->registerCustomerAndReturnToken(static::createClient(), 'claim');

        static::ensureKernelShutdown();
        $claimClient = static::createClient();
        $claimClient->request('GET', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $customerToken),
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();

        static::ensureKernelShutdown();
        $bearerOnlyClient = static::createClient();
        $bearerOnlyClient->request('GET', sprintf('/api/personalization/sessions/%s', $sessionId), server: [
            'HTTP_AUTHORIZATION' => sprintf('Bearer %s', $customerToken),
        ]);

        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);

        self::assertTrue($session->hasOwnerCustomer());
    }

    public function testLinkedOrderSessionsRequireMatchingOwnership(): void
    {
        $client = static::createClient();
        ['id' => $sessionId, 'ownerToken' => $ownerToken] = $this->createSession($client);
        $orderNumber = $this->createLinkedOrderForSession($sessionId);

        static::ensureKernelShutdown();
        $foreignClient = static::createClient();
        $foreignClient->request('GET', sprintf('/api/custom/orders/%s/sessions', $orderNumber));
        self::assertResponseStatusCodeSame(404);

        static::ensureKernelShutdown();
        $ownerClient = static::createClient();
        $ownerClient->request('GET', sprintf('/api/custom/orders/%s/sessions', $orderNumber), server: [
            'HTTP_X_PERSONALIZATION_OWNER_TOKEN' => $ownerToken,
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $ownerClient->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame($sessionId, $payload[0]['id']);
    }

    /**
     * @return array{id:string, ownerToken:string}
     */
    private function createSession(object $client): array
    {
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $payload['id'],
            'ownerToken' => (string) $payload['ownerToken'],
        ];
    }

    private function registerCustomerAndReturnToken(object $client, string $suffix): string
    {
        $email = sprintf('imp002-%s-%s@example.test', $suffix, bin2hex(random_bytes(4)));

        $client->request('POST', '/api/v2/shop/customers/register', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => $email,
            'password' => 'supersecret123',
            'firstName' => 'Test',
            'lastName' => 'Owner',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return (string) $payload['token'];
    }

    private function createLinkedOrderForSession(string $sessionId): string
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $nextOrderId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM sylius_order');
        $nextOrderItemId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM sylius_order_item');
        $channelId = (int) $connection->fetchOne('SELECT id FROM sylius_channel ORDER BY id ASC LIMIT 1');
        $variantId = (int) $connection->fetchOne('SELECT id FROM sylius_product_variant ORDER BY id ASC LIMIT 1');
        $orderNumber = sprintf('IMP002-%d', $nextOrderId);

        $connection->executeStatement(
            <<<'SQL'
INSERT INTO sylius_order (
    id, channel_id, number, state, items_total, adjustments_total, total, created_at, updated_at,
    currency_code, locale_code, checkout_state, payment_state, shipping_state, created_by_guest,
    abandoned_email, token_value
) VALUES (
    :id, :channelId, :number, 'new', 4990, 0, 4990, :createdAt, :updatedAt,
    'EUR', 'fr_FR', 'cart', 'cart', 'cart', TRUE, FALSE, :tokenValue
)
SQL,
            [
                'id' => $nextOrderId,
                'channelId' => $channelId,
                'number' => $orderNumber,
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'tokenValue' => sprintf('imp002-cart-token-%d', $nextOrderId),
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

        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($sessionId);
        $link = new PersonalizationOrderItemLink($session, $nextOrderItemId);
        $entityManager->persist($link);
        $entityManager->flush();

        return $orderNumber;
    }
}
