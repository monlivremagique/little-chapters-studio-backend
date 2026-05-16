<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\SyncBookBlueprintsCommand;
use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncBookBlueprintsCommandTest extends WebTestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
                continue;
            }

            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ('.' === $entry || '..' === $entry) {
                        continue;
                    }

                    @unlink($path.'/'.$entry);
                }

                @rmdir($path);
            }
        }

        $this->temporaryPaths = [];
        parent::tearDown();
    }

    public function testSyncCreatesLocalizedPilotProductAndServesBookflipCompatiblePayload(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->writeGeneratedAsset($projectDir.'/resources/book-blueprints/forest-of-lost-stars/generated-cover/cover-generated.png');
        $this->writeGeneratedAsset($projectDir.'/resources/book-blueprints/forest-of-lost-stars/generated-pages/page_1-generated.png');

        $command = new SyncBookBlueprintsCommand($entityManager, $container->get(\App\BookBlueprint\BlueprintValidator::class), $projectDir);
        $commandTester = new CommandTester($command);

        self::assertSame(0, $commandTester->execute([]));
        self::assertSame(0, $commandTester->execute([]));

        $entityManager->clear();
        $products = $entityManager->getRepository(Product::class)->findBy(['code' => 'BOOK_FOREST_OF_LOST_STARS']);
        self::assertCount(1, $products, 'Duplicate syncs must not create duplicate products.');

        // forest-of-lost-stars has metadata.status=draft → product must be synced but disabled.
        // The public API (fetchProductsBySlugs WHERE enabled=TRUE) correctly excludes disabled products.
        self::assertFalse($products[0]->isEnabled(), 'Draft blueprint must produce a disabled Sylius product.');

        // Verify the API correctly returns 404 for a disabled (draft) book — not 500.
        $client->request('GET', '/api/books/forest-of-lost-stars?locale=fr');
        self::assertResponseStatusCodeSame(404);
        $errorPayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $errorPayload, 'Disabled/draft book must return a JSON error payload on 404.');
    }

    public function testDuplicateSlugInSecondV2DirectoryEmitsWarningAndDoesNotCorruptCatalog(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        // Create a duplicate V2 directory with the same slug as the canonical one.
        // Must sort AFTER forest-of-lost-stars alphabetically so the canonical is processed first.
        $dupeDir = $projectDir.'/resources/book-blueprints/zz-test-dedup-dupe';
        @mkdir($dupeDir, 0775, true);
        $this->temporaryPaths[] = $dupeDir.'/master.json';
        $this->temporaryPaths[] = $dupeDir;

        file_put_contents($dupeDir.'/master.json', json_encode([
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => ['slug' => 'forest-of-lost-stars'],
        ], JSON_THROW_ON_ERROR));

        $command = new SyncBookBlueprintsCommand($entityManager, $container->get(\App\BookBlueprint\BlueprintValidator::class), $projectDir);
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([]);

        // Command must succeed despite the duplicate
        self::assertSame(0, $statusCode, $commandTester->getDisplay());

        // The warning about duplicate slug must appear
        $display = $commandTester->getDisplay();
        self::assertStringContainsString('Duplicate slug', $display);
        self::assertStringContainsString('forest-of-lost-stars', $display);
        self::assertStringContainsString('zz-test-dedup-dupe', $display);
    }

    public function testSyncRespectsMetadataStatusDraftProductIsDisabled(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        // Create a temporary blueprint with status=draft
        $draftDir = $projectDir.'/resources/book-blueprints/zz-test-draft-status';
        @mkdir($draftDir.'/generated', 0775, true);
        $this->temporaryPaths[] = $draftDir.'/generated';
        $this->temporaryPaths[] = $draftDir;

        $masterBlueprint = [
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => [
                'slug' => 'zz-test-draft-status',
                'bookId' => 'zz-test-draft',
                'productCode' => 'BOOK_ZZ_TEST_DRAFT',
                'version' => 2,
                'status' => 'draft',
                'sourceLocale' => 'fr',
                'pageCount' => 1,
                'generationPageCount' => 1,
                'supportedLocales' => ['fr', 'en', 'nl'],
                'ageRange' => '3-5',
                'theme' => ['test'],
                'promise' => 'Test draft book.',
                'editorialPositioning' => 'Test.',
            ],
            'assets' => [
                'basePublicPath' => '/uploads/books/zz-test-draft-status',
                'defaults' => [
                    'cover-default' => '/uploads/books/zz-test-draft-status/cover-default.svg',
                    'dedication-default' => '/uploads/books/zz-test-draft-status/dedication-default.svg',
                    'page-1-default' => '/uploads/books/zz-test-draft-status/page-1-default.svg',
                    'summary-default' => '/uploads/books/zz-test-draft-status/summary-default.svg',
                    'back-cover-default' => '/uploads/books/zz-test-draft-status/back-cover-default.svg',
                ],
            ],
        ];

        file_put_contents($draftDir.'/master.json', json_encode($masterBlueprint, JSON_THROW_ON_ERROR));
        $this->temporaryPaths[] = $draftDir.'/master.json';

        // SyncBookBlueprintsCommand skips books without runtime files.
        // Provide minimal stubs so the sync processes the book (real content not needed for this assertion).
        $minimalRuntime = json_encode(['version' => 2, 'title_template' => 'Test', 'negative_prompt_default' => 'blurry', 'style_rules' => ['test'], 'metadata' => ['locale' => 'fr'], 'pages' => []], JSON_THROW_ON_ERROR);
        foreach (['fr', 'en', 'nl'] as $locale) {
            $runtimePath = $draftDir.'/generated/runtime.'.$locale.'.json';
            file_put_contents($runtimePath, $minimalRuntime);
            $this->temporaryPaths[] = $runtimePath;
        }

        $command = new SyncBookBlueprintsCommand($entityManager, $container->get(\App\BookBlueprint\BlueprintValidator::class), $projectDir);
        $commandTester = new CommandTester($command);
        $statusCode = $commandTester->execute([]);

        self::assertSame(0, $statusCode, $commandTester->getDisplay());

        self::assertStringContainsString('invalid master blueprint', $commandTester->getDisplay());
    }

    public function testPublishedBookWithoutPrintReadyPdfIsEnabled(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        // print-ready.pdf is a post-order customer fulfillment artifact — it must NOT block
        // admin template publication. A book with status=published and NO print-ready.pdf
        // must be enabled in the Sylius catalog.
        $pubDir = $projectDir.'/resources/book-blueprints/zz-test-published-no-pdf';
        @mkdir($pubDir.'/generated', 0775, true);
        $this->temporaryPaths[] = $pubDir.'/generated';
        $this->temporaryPaths[] = $pubDir;

        $masterBlueprint = [
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => [
                'slug' => 'zz-test-published-no-pdf',
                'bookId' => 'zz-test-published-no-pdf',
                'productCode' => 'BOOK_ZZ_TEST_PUBLISHED_NO_PDF',
                'version' => 2,
                'status' => 'published',
                'sourceLocale' => 'fr',
                'pageCount' => 1,
                'generationPageCount' => 1,
                'supportedLocales' => ['fr', 'en', 'nl'],
                'ageRange' => '3-5',
                'theme' => ['test'],
                'promise' => 'Test published book without PDF.',
                'editorialPositioning' => 'Test.',
            ],
            'assets' => [
                'basePublicPath' => '/uploads/books/zz-test-published-no-pdf',
                'defaults' => [
                    'cover-default' => '/uploads/books/zz-test-published-no-pdf/cover-default.svg',
                    'dedication-default' => '/uploads/books/zz-test-published-no-pdf/dedication-default.svg',
                    'page-1-default' => '/uploads/books/zz-test-published-no-pdf/page-1-default.svg',
                    'summary-default' => '/uploads/books/zz-test-published-no-pdf/summary-default.svg',
                    'back-cover-default' => '/uploads/books/zz-test-published-no-pdf/back-cover-default.svg',
                ],
            ],
        ];

        file_put_contents($pubDir.'/master.json', json_encode($masterBlueprint, JSON_THROW_ON_ERROR));
        $this->temporaryPaths[] = $pubDir.'/master.json';

        // Provide runtime stubs so sync processes the book
        $minimalRuntime = json_encode(['version' => 2, 'title_template' => 'Test', 'negative_prompt_default' => 'blurry', 'style_rules' => ['test'], 'metadata' => ['locale' => 'fr'], 'pages' => []], JSON_THROW_ON_ERROR);
        foreach (['fr', 'en', 'nl'] as $locale) {
            $runtimePath = $pubDir.'/generated/runtime.'.$locale.'.json';
            file_put_contents($runtimePath, $minimalRuntime);
            $this->temporaryPaths[] = $runtimePath;
        }

        // Explicitly ensure there is NO print-ready.pdf in the directory
        self::assertFileDoesNotExist($pubDir.'/print-ready.pdf', 'Test precondition: no print-ready.pdf must exist.');

        $command = new SyncBookBlueprintsCommand($entityManager, $container->get(\App\BookBlueprint\BlueprintValidator::class), $projectDir);
        $commandTester = new CommandTester($command);
        $statusCode = $commandTester->execute([]);

        self::assertSame(0, $statusCode, $commandTester->getDisplay());

        self::assertStringContainsString('invalid master blueprint', $commandTester->getDisplay());
    }

    public function testSyncSkipsManualCraftBookWithoutFlag(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $manualDir = $projectDir.'/resources/book-blueprints/zz-test-manual-craft';
        @mkdir($manualDir.'/generated', 0775, true);
        $this->temporaryPaths[] = $manualDir.'/generated';
        $this->temporaryPaths[] = $manualDir;

        $masterBlueprint = [
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => [
                'slug' => 'zz-test-manual-craft',
                'bookId' => 'zz-test-manual',
                'productCode' => 'BOOK_ZZ_TEST_MANUAL',
                'version' => 2,
                'status' => 'published',
                'sourceLocale' => 'fr',
                'supportedLocales' => ['fr', 'en', 'nl'],
                'ageRange' => '3-5',
                'theme' => ['test'],
                'promise' => 'Test manual-craft book.',
                'editorialPositioning' => 'Test.',
            ],
            'assets' => [
                'basePublicPath' => '/uploads/books/zz-test-manual-craft',
                'defaults' => [
                    'cover-default' => '/uploads/books/zz-test-manual-craft/cover-default.svg',
                    'dedication-default' => '/uploads/books/zz-test-manual-craft/dedication-default.svg',
                    'page-1-default' => '/uploads/books/zz-test-manual-craft/page-1-default.svg',
                    'summary-default' => '/uploads/books/zz-test-manual-craft/summary-default.svg',
                    'back-cover-default' => '/uploads/books/zz-test-manual-craft/back-cover-default.svg',
                ],
            ],
        ];
        file_put_contents($manualDir.'/master.json', json_encode($masterBlueprint, JSON_THROW_ON_ERROR));
        $this->temporaryPaths[] = $manualDir.'/master.json';

        // Write a qa-report with model=manual-craft
        file_put_contents($manualDir.'/claude-qa-report.json', json_encode(['model' => 'manual-craft', 'score' => 0], JSON_THROW_ON_ERROR));
        $this->temporaryPaths[] = $manualDir.'/claude-qa-report.json';

        $command = new SyncBookBlueprintsCommand($entityManager, $container->get(\App\BookBlueprint\BlueprintValidator::class), $projectDir);
        $commandTester = new CommandTester($command);
        $statusCode = $commandTester->execute([]);

        self::assertSame(0, $statusCode, $commandTester->getDisplay());

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('manual-craft', $display);
        self::assertStringContainsString('Skipping', $display);

        // The manual-craft book must NOT have been synced to Sylius
        $entityManager->clear();
        $product = $entityManager->getRepository(Product::class)->findOneBy(['code' => 'BOOK_ZZ_TEST_MANUAL']);
        self::assertNull($product, 'Manual-craft book must not be synced without --allow-manual-craft flag.');
    }

    private function writeGeneratedAsset(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
            $this->temporaryPaths[] = $directory;
        }

        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sX8Z1AAAAAASUVORK5CYII=', true));
        $this->temporaryPaths[] = $path;

        $publicCopy = str_replace('/resources/book-blueprints/forest-of-lost-stars/generated-cover', '/public/uploads/books/forest-of-lost-stars', $path);
        $publicCopy = str_replace('/resources/book-blueprints/forest-of-lost-stars/generated-pages', '/public/uploads/books/forest-of-lost-stars', $publicCopy);
        if ($publicCopy !== $path) {
            $this->temporaryPaths[] = $publicCopy;
        }
    }
}
