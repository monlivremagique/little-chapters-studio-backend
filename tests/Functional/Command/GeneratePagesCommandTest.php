<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\PageGenerationService;
use App\BookBlueprint\PagePromptBuilder;
use App\Command\GeneratePagesCommand;
use App\Tests\Double\Replicate\FakeReplicatePredictionClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GeneratePagesCommandTest extends TestCase
{
    public function testCommandSucceedsWithMinimalOptions(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $outputDir = $this->createTemporaryDirectory();
        $coverPath = $this->createTemporaryPng();
        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--cover' => $coverPath,
            '--output-dir' => $outputDir,
            '--page' => 'page_1',
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Pages generated successfully.', $commandTester->getDisplay());
        self::assertFileExists($outputDir.'/page_1-generated.png');
        self::assertSame('fake-page-binary', file_get_contents($outputDir.'/page_1-generated.png'));

        $debug = json_decode((string) file_get_contents($outputDir.'/page_1-debug.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($debug['photoProvided']);
    }

    public function testCommandSucceedsWithPhotoAndCover(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();

        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => ['https://replicate.example.test/page-1.png']],
        ]);
        $fakeReplicate->registerDownload('https://replicate.example.test/page-1.png', 'fake-page-binary');

        $outputDir = $this->createTemporaryDirectory();
        $coverPath = $this->createTemporaryPng();
        $photoPath = $this->createTemporaryPng();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--cover' => $coverPath,
            '--photo' => $photoPath,
            '--output-dir' => $outputDir,
            '--page' => 'page_1',
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Pages generated successfully.', $commandTester->getDisplay());
        self::assertFileExists($outputDir.'/page_1-generated.png');
        self::assertSame('fake-page-binary', file_get_contents($outputDir.'/page_1-generated.png'));

        $debug = json_decode((string) file_get_contents($outputDir.'/page_1-debug.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($debug['photoProvided']);
        self::assertCount(3, $debug['replicateInputPayload']['input_images']);
    }

    public function testCommandGeneratesDedicatedHeroPortrait(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => ['https://replicate.example.test/hero-portrait.png']],
        ]);
        $fakeReplicate->registerDownload('https://replicate.example.test/hero-portrait.png', 'hero-portrait-binary');

        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--output-dir' => $outputDir,
            '--hero-prompt' => 'Premium character portrait of child hero with auburn curls, sage sweater, warm neutral background.',
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/hero-reference.png');
        self::assertSame('hero-portrait-binary', file_get_contents($outputDir.'/hero-reference.png'));

        $promptFile = (string) file_get_contents($outputDir.'/hero-reference-prompt.txt');
        self::assertStringContainsString('auburn curls', $promptFile);
    }

    public function testCommandRetriesTransientPageGenerationFailure(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'failed', 'error' => "('Error generating image', {'status': 'Error'})"],
        ]);
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => ['https://replicate.example.test/page-retry.png']],
        ]);
        $fakeReplicate->registerDownload('https://replicate.example.test/page-retry.png', 'retry-page-binary');

        $outputDir = $this->createTemporaryDirectory();
        $coverPath = $this->createTemporaryPng();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--cover' => $coverPath,
            '--output-dir' => $outputDir,
            '--page' => 'page_1',
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/page_1-generated.png');
        self::assertSame('retry-page-binary', file_get_contents($outputDir.'/page_1-generated.png'));
    }

    public function testFailsWithoutSourceFile(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => '/nonexistent/path.json',
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('The --source option is required', $commandTester->getDisplay());
    }

    public function testDryRunWritesDebugPayload(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $outputDir = $this->createTemporaryDirectory();
        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--output-dir' => $outputDir,
            '--page' => 'page_3',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Dry-run payload written.', $commandTester->getDisplay());
        self::assertFileExists($outputDir.'/page_3-prompt.txt');
        self::assertFileExists($outputDir.'/page_3-negative-prompt.txt');
        self::assertFileExists($outputDir.'/page_3-debug.json');

        $debug = json_decode((string) file_get_contents($outputDir.'/page_3-debug.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($debug['dryRun']);
        self::assertSame('page_3', $debug['scene']);
        self::assertNull($debug['prediction']);
    }

    private function createCommandTester(FakeReplicatePredictionClient $fakeReplicate): CommandTester
    {
        $command = new GeneratePagesCommand(
            new PageGenerationService(
                new \App\BookBlueprint\BlueprintValidator(),
                new PagePromptBuilder(),
            ),
            $fakeReplicate,
        );

        return new CommandTester($command);
    }

    private function createTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().'/lc-test-pages-'.bin2hex(random_bytes(6));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Temporary directory could not be created: %s', $dir));
        }

        return $dir;
    }

    private function createTemporaryPng(): string
    {
        $path = $this->createTemporaryDirectory().'/test.png';
        $image = imagecreatetruecolor(100, 100);
        if (false === $image) {
            throw new \RuntimeException('Temporary test image could not be created.');
        }
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function masterFixturePath(): string
    {
        $path = __DIR__.'/../../Fixtures/book-blueprints/master-valid.json';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Test fixture not found: %s', $path));
        }

        return $path;
    }
}
