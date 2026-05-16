<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Personalization\PersonalizationSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PersonalizationSessionLocaleTest extends WebTestCase
{
    public function testCreateSessionStoresExplicitNlLocale(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
            'bookLocale' => 'nl',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('nl', $payload['bookLocale']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PersonalizationSession $session */
        $session = $entityManager->getRepository(PersonalizationSession::class)->find($payload['id']);
        self::assertSame('nl', $session->getBookLocale());
        self::assertSame('nl', $session->getResolvedBookLocale());
    }

    public function testCreateSessionRejectsInvalidLocale(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
            'bookLocale' => 'de',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateSessionRejectsMissingLocale(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/personalization/sessions', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'bookId' => 'b1',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }
}
