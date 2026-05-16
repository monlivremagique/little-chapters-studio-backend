<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\BlueprintValidator;
use App\BookBlueprint\CoverGenerationService;
use App\BookBlueprint\CoverPromptBuilder;
use App\Command\GenerateCoverCommand;
use App\Tests\Double\Replicate\FakeReplicatePredictionClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateCoverCommandTest extends TestCase
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
    }

    public function testDryRunBuildsPromptWithoutWritingFiles(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester(new FakeReplicatePredictionClient());

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Cover prompt built successfully in dry-run mode.', $commandTester->getDisplay());
        self::assertFileExists($outputDir.'/cover-prompt.txt');
        self::assertFileExists($outputDir.'/cover-negative-prompt.txt');
        self::assertFileExists($outputDir.'/cover-debug.json');
        self::assertFileDoesNotExist($outputDir.'/cover-generated.png');
        $prompt = (string) file_get_contents($outputDir.'/cover-prompt.txt');
        self::assertStringContainsString('STYLE:', $prompt);
        self::assertStringContainsString('HERO:', $prompt);
        self::assertStringContainsString('CAMERA:', $prompt);
        self::assertStringContainsString('COMPOSITION:', $prompt);
        self::assertStringContainsString('FOREGROUND:', $prompt);
        self::assertStringContainsString('MIDGROUND:', $prompt);
        self::assertStringContainsString('BACKGROUND:', $prompt);
        self::assertStringContainsString('LIGHTING:', $prompt);
        self::assertStringContainsString('EMOTION:', $prompt);
        self::assertStringContainsString('MUST_SHOW:', $prompt);
        self::assertStringContainsString('MUST_NOT_SHOW:', $prompt);
        self::assertStringNotContainsString('personalized', strtolower($prompt));
        self::assertStringNotContainsString('likeness', strtolower($prompt));
        self::assertStringNotContainsString('same face', strtolower($prompt));
        self::assertStringNotContainsString('reference photo', strtolower($prompt));
        self::assertStringNotContainsString('scene directive', strtolower($prompt));
        self::assertSame(1, preg_match_all('/^COMPOSITION:/m', $prompt));
        self::assertStringNotContainsString('same age and ,', strtolower($prompt));
        self::assertStringContainsString('no text or readable characters anywhere', strtolower($prompt));
        self::assertStringContainsString('leave clean empty visual space for title overlay', strtolower($prompt));

        $negativePrompt = (string) file_get_contents($outputDir.'/cover-negative-prompt.txt');
        self::assertStringContainsString('letters', strtolower($negativePrompt));
        self::assertStringContainsString('words', strtolower($negativePrompt));
        self::assertStringContainsString('title', strtolower($negativePrompt));
        self::assertStringContainsString('readable characters', strtolower($negativePrompt));
        self::assertStringNotContainsString('likeness', strtolower($negativePrompt));
        self::assertStringNotContainsString('inconsistent face', strtolower($negativePrompt));

        $debug = json_decode((string) file_get_contents($outputDir.'/cover-debug.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($debug['dryRun']);
        self::assertFalse($debug['photoProvided']);
        self::assertSame($outputDir, $debug['outputDir']);
        self::assertArrayHasKey('replicateInputPayload', $debug);
    }

    public function testCommandGeneratesCoverFilesWithOptionalPhoto(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => ['https://replicate.example.test/cover.png']],
        ]);
        $fakeReplicate->registerDownload('https://replicate.example.test/cover.png', 'fake-png-binary');

        $outputDir = $this->createTemporaryDirectory();
        $photoPath = $this->createTemporaryPng();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--photo' => $photoPath,
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Cover generated successfully.', $commandTester->getDisplay());
        self::assertFileExists($outputDir.'/cover-generated.png');
        self::assertFileExists($outputDir.'/cover-prompt.txt');
        self::assertFileExists($outputDir.'/cover-negative-prompt.txt');
        self::assertFileExists($outputDir.'/cover-debug.json');
        self::assertSame('fake-png-binary', file_get_contents($outputDir.'/cover-generated.png'));
        $prompt = (string) file_get_contents($outputDir.'/cover-prompt.txt');
        self::assertStringNotContainsString('forest of lost stars"', strtolower($prompt));
        self::assertStringContainsString('personalized child hero based on the provided reference photo', strtolower($prompt));
        self::assertStringContainsString('same face', strtolower($prompt));
        self::assertStringContainsString('scary atmosphere', (string) file_get_contents($outputDir.'/cover-negative-prompt.txt'));

        $debug = json_decode((string) file_get_contents($outputDir.'/cover-debug.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('black-forest-labs/flux-2-pro', $debug['model']);
        self::assertTrue($debug['photoProvided']);
        self::assertStringStartsWith('data:image/png;base64,', $debug['replicateInputPayload']['input_images'][0]);
    }

    public function testCommandRetriesTransientCoverGenerationFailure(): void
    {
        $fakeReplicate = new FakeReplicatePredictionClient();
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'failed', 'error' => "('Error generating image', {'status': 'Error'})"],
        ]);
        $fakeReplicate->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => ['https://replicate.example.test/cover-retry.png']],
        ]);
        $fakeReplicate->registerDownload('https://replicate.example.test/cover-retry.png', 'retry-cover-binary');

        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(0, $statusCode);
        self::assertCount(2, $fakeReplicate->getCreateInputs());
        self::assertSame('retry-cover-binary', file_get_contents($outputDir.'/cover-generated.png'));
    }

    public function testCommandSkipsRealGenerationWhenCoverAlreadyExistsWithoutForce(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        file_put_contents($outputDir.'/cover-generated.png', 'existing-cover');
        $fakeReplicate = new FakeReplicatePredictionClient();
        $commandTester = $this->createCommandTester($fakeReplicate);

        $statusCode = $commandTester->execute([
            '--source' => $this->masterFixturePath(),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Cover already exists.', $commandTester->getDisplay());
        self::assertSame('existing-cover', file_get_contents($outputDir.'/cover-generated.png'));
        self::assertSame([], $fakeReplicate->getCreateInputs());
    }

    private function createCommandTester(FakeReplicatePredictionClient $fakeReplicatePredictionClient): CommandTester
    {
        $command = new GenerateCoverCommand(
            new CoverGenerationService(new BlueprintValidator(), new CoverPromptBuilder()),
            $fakeReplicatePredictionClient,
        );

        return new CommandTester($command);
    }

    private function masterFixturePath(): string
    {
        return dirname(__DIR__, 3).'/resources/book-blueprints/forest-of-lost-stars/master.json';
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lc-cover-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->temporaryPaths[] = $directory;

        return $directory;
    }

    private function createTemporaryPng(): string
    {
        $path = sys_get_temp_dir().'/lc-cover-photo-'.bin2hex(random_bytes(6)).'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sX8Z1AAAAAASUVORK5CYII=', true));
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
