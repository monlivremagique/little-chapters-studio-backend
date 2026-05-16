<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\CheckAssetCompletenessCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckAssetCompletenessCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_dir($path)) {
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $node) {
                    $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
                }
                @rmdir($path);
            }
        }
        $this->temporaryPaths = [];
    }

    public function testFailsOnMissingBlueprintDir(): void
    {
        $tester = new CommandTester(new CheckAssetCompletenessCommand());
        $statusCode = $tester->execute(['blueprint-dir' => '/tmp/nonexistent']);
        self::assertSame(1, $statusCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testFailsOnMissingCoverPng(): void
    {
        $dir = $this->createTempDir();
        $this->writeMasterJson($dir, ['cover', 'story', 'backCover']);
        mkdir($dir.'/generated-cover', 0775, true);
        mkdir($dir.'/generated-pages', 0775, true);

        $tester = new CommandTester(new CheckAssetCompletenessCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(1, $statusCode);
        self::assertStringContainsString('Missing cover', $tester->getDisplay());
    }

    public function testPassesWithAllAssets(): void
    {
        $dir = $this->createTempDir();
        $this->writeMasterJson($dir, ['cover', 'story', 'story', 'story', 'story', 'story', 'story', 'backCover']);
        mkdir($dir.'/generated-cover', 0775, true);
        touch($dir.'/generated-cover/cover-generated.png');
        mkdir($dir.'/generated-pages', 0775, true);

        $expectedPages = ['page_1', 'page_2', 'page_3', 'page_4', 'page_5', 'page_6'];
        foreach ($expectedPages as $pageId) {
            touch($dir.'/generated-pages/'.$pageId.'-generated.png');
        }
        touch($dir.'/generated-pages/backCover-generated.png');
        touch($dir.'/generated-pages/hero-reference.png');

        $tester = new CommandTester(new CheckAssetCompletenessCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertStringContainsString('8 generated assets are present', $tester->getDisplay());
    }

    public function testPassesWithExpectedCountOverride(): void
    {
        $dir = $this->createTempDir();
        $this->writeMasterJson($dir, ['cover', 'story', 'backCover']);
        mkdir($dir.'/generated-cover', 0775, true);
        touch($dir.'/generated-cover/cover-generated.png');
        mkdir($dir.'/generated-pages', 0775, true);
        touch($dir.'/generated-pages/page_1-generated.png');
        touch($dir.'/generated-pages/page_2-generated.png');
        touch($dir.'/generated-pages/page_3-generated.png');
        touch($dir.'/generated-pages/backCover-generated.png');

        $tester = new CommandTester(new CheckAssetCompletenessCommand());
        $statusCode = $tester->execute([
            'blueprint-dir' => $dir,
            '--expected-count' => '5',
        ]);
        self::assertSame(0, $statusCode, $tester->getDisplay());
    }

    /** @param list<string> $types */
    private function writeMasterJson(string $dir, array $types): void
    {
        $sceneDefinitions = [];
        $index = 1;
        foreach ($types as $type) {
            $id = match ($type) {
                'cover' => 'cover',
                'backCover' => 'backCover',
                'summary' => 'summary',
                'dedication' => 'dedication',
                default => sprintf('page_%d', $index++),
            };
            $sceneDefinitions[] = [
                'id' => $id,
                'type' => $type,
                'pageNumber' => count($sceneDefinitions) + 1,
                'personalizable' => 'story' === $type,
                'assetKey' => $id.'-default',
            ];
        }

        file_put_contents($dir.'/master.json', json_encode([
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => ['slug' => 'test-book'],
            'sceneDefinitions' => $sceneDefinitions,
            'assets' => ['basePublicPath' => '/uploads/books/test-book', 'defaults' => []],
        ], JSON_PRETTY_PRINT));
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lc-assets-'.bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->temporaryPaths[] = $dir;
        return $dir;
    }
}
