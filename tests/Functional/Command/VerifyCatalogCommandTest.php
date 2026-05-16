<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\VerifyCatalogCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class VerifyCatalogCommandTest extends TestCase
{
    public function testFailsOnMissingSlugInCatalog(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $catalogResponse = $this->createMock(ResponseInterface::class);
        $catalogResponse->method('getStatusCode')->willReturn(200);
        $catalogResponse->method('getContent')->willReturn(json_encode([
            ['slug' => 'other-book', 'title' => 'Other'],
        ]));

        $httpClient->method('request')->willReturn($catalogResponse);

        $tester = new CommandTester(new VerifyCatalogCommand($httpClient));
        $statusCode = $tester->execute([
            'slug' => 'my-book',
            '--base-url' => 'http://localhost:8001',
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('does NOT appear', $tester->getDisplay());
    }

    public function testFailsOnMissingBookBlueprint(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $callCount = 0;

        $httpClient->method('request')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            $resp = $this->createMock(ResponseInterface::class);
            $resp->method('getStatusCode')->willReturn(200);

            if (1 === $callCount) {
                // GET /api/books — catalog list
                $resp->method('getContent')->willReturn(json_encode([
                    ['slug' => 'my-book', 'title' => 'My Book'],
                ]));
            } else {
                // GET /api/books/my-book?locale=fr — missing bookBlueprint
                $resp->method('getContent')->willReturn(json_encode([
                    'slug' => 'my-book',
                    'title' => 'My Book',
                    'bookBlueprint' => null,
                ]));
            }

            return $resp;
        });

        $tester = new CommandTester(new VerifyCatalogCommand($httpClient));
        $statusCode = $tester->execute([
            'slug' => 'my-book',
            '--base-url' => 'http://localhost:8001',
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('missing bookBlueprint', $tester->getDisplay());
    }

    public function testPassesWithCompleteBook(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $callCount = 0;

        $httpClient->method('request')->willReturnCallback(function () use (&$callCount) {
            ++$callCount;
            $resp = $this->createMock(ResponseInterface::class);
            $resp->method('getStatusCode')->willReturn(200);

            $frPages = [
                ['id' => 'cover', 'default_image_path' => '/uploads/books/my-book/cover-generated.png'],
                ['id' => 'dedication', 'default_image_path' => '/uploads/books/my-book/dedication-default.svg'],
                ['id' => 'page_1', 'default_image_path' => '/uploads/books/my-book/page_1-generated.png'],
                ['id' => 'page_2', 'default_image_path' => '/uploads/books/my-book/page_2-generated.png'],
                ['id' => 'summary', 'default_image_path' => '/uploads/books/my-book/summary-default.svg'],
                ['id' => 'backCover', 'default_image_path' => '/uploads/books/my-book/backCover-generated.png'],
            ];

            if (1 === $callCount) {
                $resp->method('getContent')->willReturn(json_encode([
                    ['slug' => 'my-book', 'title' => 'My Book'],
                ]));
            } elseif (in_array($callCount, [2, 3, 4], true)) {
                $locale = match ($callCount) { 2 => 'fr', 3 => 'en', 4 => 'nl' };
                $resp->method('getContent')->willReturn(json_encode([
                    'slug' => 'my-book',
                    'title' => 'My Book',
                    'bookBlueprint' => [
                        'metadata' => ['locale' => $locale, 'slug' => 'my-book'],
                        'pages' => $frPages,
                    ],
                ]));
            } else {
                // Asset HTTP 200 check — any count >= 5
                $resp->method('getContent')->willReturn('ok');
            }

            return $resp;
        });

        $tester = new CommandTester(new VerifyCatalogCommand($httpClient));
        $statusCode = $tester->execute([
            'slug' => 'my-book',
            '--base-url' => 'http://localhost:8001',
        ]);

        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertStringContainsString('fully visible', $tester->getDisplay());
    }
}
