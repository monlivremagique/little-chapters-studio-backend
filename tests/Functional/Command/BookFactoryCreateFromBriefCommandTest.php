<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\BlueprintValidator;
use App\BookBrief\BookBriefPromptBuilder;
use App\BookBrief\BookBriefQaPromptBuilder;
use App\Command\BookFactoryCreateFromBriefCommand;
use App\Command\CheckAssetCompletenessCommand;
use App\Command\GenerateMasterFromBriefCommand;
use App\Command\GeneratePrintReadyPdfCommand;
use App\Command\QaGateCommand;
use App\Command\ValidateBookBriefCommand;
use App\Tests\Double\Replicate\FakeReplicateTextGenerationClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests cover: dry-run passthrough, QA score gate, heroBible/visualBible gates, --sync-prod guard.
 *
 * All tests in this file fail before reaching Step 3 (create-from-blueprint) and therefore
 * do not require a booted Symfony kernel or Doctrine database.
 * KernelInterface is mocked — it is never actually called in these paths.
 */
final class BookFactoryCreateFromBriefCommandTest extends TestCase
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
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $node) {
                    /** @var \SplFileInfo $node */
                    $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
                }
                @rmdir($path);
            }
        }
        $this->temporaryPaths = [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dry-run
    // ─────────────────────────────────────────────────────────────────────────

    public function testDryRunWritesPromptsWithoutCallingReplicate(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $tester = $this->createCommandTester($fakeClient, $outputDir);

        $statusCode = $tester->execute([
            'brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertSame([], $fakeClient->getCreateInputs(), 'Replicate must not be called in dry-run mode.');
        self::assertFileExists($outputDir.'/claude-master-prompt.txt');
        self::assertFileExists($outputDir.'/claude-master-payload.json');
        self::assertFileExists($outputDir.'/claude-qa-prompt.txt');
        self::assertFileDoesNotExist($outputDir.'/master.json');
        self::assertStringContainsString('Dry-run complete', $tester->getDisplay());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QA score gate
    // ─────────────────────────────────────────────────────────────────────────

    public function testQaScoreBelowPremiumThresholdCausesFailure(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();

        $lowQaResponse = json_encode([
            'verdict' => 'GO',
            'scores' => [
                'editorial' => 6,
                'imageability' => 5,
                'heroConsistency' => 7,
                'localeCompleteness' => 6,
                'bedtimeSafety' => 5,
                'premiumBelgium' => 6,
            ],
            'blockingIssues' => ['Generic phrasing throughout.', 'Weak visual anchors.'],
            'correctedMaster' => (object) [],
        ]);

        // Seed 6 sequences (3 attempts × [master, QA]) for the retry loop
        for ($i = 0; $i < 3; ++$i) {
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [json_encode($this->masterFixture())]],
            ]);
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [$lowQaResponse]],
            ]);
        }

        $tester = $this->createCommandTester($fakeClient, $outputDir);
        $statusCode = $tester->execute([
            'brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(1, $statusCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('cannot generate a master', $display);
        self::assertStringContainsString('9.0', $display);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // heroBible gate
    // ─────────────────────────────────────────────────────────────────────────

    public function testHeroBibleMissingOrEmptyCausesFailure(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();

        $masterWithoutHeroBible = $this->masterFixture();
        $masterWithoutHeroBible['heroBible'] = [];

        $premiumQaResponse = json_encode([
            'verdict' => 'GO',
            'scores' => $this->premiumScores(),
            'blockingIssues' => [],
            'correctedMaster' => (object) [],
        ]);

        // Seed 6 sequences (3 attempts × [master, QA])
        for ($i = 0; $i < 3; ++$i) {
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [json_encode($masterWithoutHeroBible)]],
            ]);
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [$premiumQaResponse]],
            ]);
        }

        $tester = $this->createCommandTester($fakeClient, $outputDir);
        $statusCode = $tester->execute([
            'brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(1, $statusCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('heroBible', $display);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // visualBible gate
    // ─────────────────────────────────────────────────────────────────────────

    public function testVisualBibleMissingOrEmptyCausesFailure(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();

        $masterWithoutVisualBible = $this->masterFixture();
        $masterWithoutVisualBible['visualBible'] = [];

        $premiumQaResponse = json_encode([
            'verdict' => 'GO',
            'scores' => $this->premiumScores(),
            'blockingIssues' => [],
            'correctedMaster' => (object) [],
        ]);

        // Seed 6 sequences (3 attempts × [master, QA])
        for ($i = 0; $i < 3; ++$i) {
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [json_encode($masterWithoutVisualBible)]],
            ]);
            $fakeClient->seedNextPredictionSequence([
                ['status' => 'starting'],
                ['status' => 'succeeded', 'output' => [$premiumQaResponse]],
            ]);
        }

        $tester = $this->createCommandTester($fakeClient, $outputDir);
        $statusCode = $tester->execute([
            'brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(1, $statusCode, $tester->getDisplay());
        self::assertStringContainsString('visualBible', $tester->getDisplay());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Three enriched briefs — dry-run must pass for all three
    // ─────────────────────────────────────────────────────────────────────────

    public function testForestOfLostStarsEnrichedBriefDryRunPasses(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $tester = $this->createCommandTester($fakeClient, $outputDir);

        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/forest-of-lost-stars.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('forest-of-lost-stars.yaml not found.');
        }

        $statusCode = $tester->execute([
            'brief' => $briefPath,
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertSame([], $fakeClient->getCreateInputs());
        self::assertFileExists($outputDir.'/claude-master-prompt.txt');
        $prompt = (string) file_get_contents($outputDir.'/claude-master-prompt.txt');
        self::assertStringContainsString('arc_type:', $prompt, 'Enriched brief arc_type must appear in prompt');
        self::assertStringContainsString('scene_scripts', $prompt, 'Enriched brief scene scripts must appear in prompt');
    }

    public function testVilleEcoleBriefDryRunPasses(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $tester = $this->createCommandTester($fakeClient, $outputDir);

        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/ville-ecole-3-5.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('ville-ecole-3-5.yaml not found.');
        }

        $statusCode = $tester->execute([
            'brief' => $briefPath,
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertSame([], $fakeClient->getCreateInputs());
        $prompt = (string) file_get_contents($outputDir.'/claude-master-prompt.txt');
        self::assertStringContainsString('comfort-to-courage', $prompt);
    }

    public function testEspaceRobotBriefDryRunPasses(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $tester = $this->createCommandTester($fakeClient, $outputDir);

        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/espace-robot-8-10.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('espace-robot-8-10.yaml not found.');
        }

        $statusCode = $tester->execute([
            'brief' => $briefPath,
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertSame([], $fakeClient->getCreateInputs());
        $prompt = (string) file_get_contents($outputDir.'/claude-master-prompt.txt');
        self::assertStringContainsString('quest-with-revelation', $prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // --sync-prod guard
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // --sync-prod + --generate-images mutual exclusion
    // ─────────────────────────────────────────────────────────────────────────

    public function testSyncProdAndGenerateImagesCombinedFails(): void
    {
        $fakeClient = new FakeReplicateTextGenerationClient();
        $tester = $this->createCommandTester($fakeClient);

        $statusCode = $tester->execute([
            'brief' => $this->briefFixturePath(),
            '--sync-prod' => true,
            '--generate-images' => true,
        ]);

        self::assertSame(1, $statusCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('--sync-prod', $display);
        self::assertStringContainsString('--generate-images', $display);
        // Must fail before any Replicate call.
        self::assertSame([], $fakeClient->getCreateInputs());
    }

    public function testSyncProdWithoutRailwayTokenFailsWithExplicitInstructions(): void
    {
        $previousToken = getenv('RAILWAY_TOKEN');
        putenv('RAILWAY_TOKEN=');

        try {
            $fakeClient = new FakeReplicateTextGenerationClient();
            $tester = $this->createCommandTester($fakeClient);
            $statusCode = $tester->execute([
                'brief' => $this->briefFixturePath(),
                '--sync-prod' => true,
            ]);
        } finally {
            if (false !== $previousToken) {
                putenv('RAILWAY_TOKEN='.$previousToken);
            }
        }

        self::assertSame(1, $statusCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('RAILWAY_TOKEN', $display);
        self::assertStringContainsString('railway ssh', $display);
    }

    public function testSyncProdWithoutTokenDoesNotCallReplicate(): void
    {
        $previousToken = getenv('RAILWAY_TOKEN');
        putenv('RAILWAY_TOKEN=');

        $fakeClient = new FakeReplicateTextGenerationClient();

        try {
            $tester = $this->createCommandTester($fakeClient);
            $tester->execute([
                'brief' => $this->briefFixturePath(),
                '--sync-prod' => true,
            ]);
        } finally {
            if (false !== $previousToken) {
                putenv('RAILWAY_TOKEN='.$previousToken);
            }
        }

        // Pre-flight fires before any Replicate call.
        self::assertSame([], $fakeClient->getCreateInputs());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createCommandTester(FakeReplicateTextGenerationClient $fakeClient, string $projectDir = '/tmp'): CommandTester
    {
        $fakeKernel = $this->createMock(KernelInterface::class);
        $generateMasterCommand = new GenerateMasterFromBriefCommand(
            new BookBriefPromptBuilder(),
            new BlueprintValidator(),
            $fakeClient,
        );

        $command = new BookFactoryCreateFromBriefCommand(
            new ValidateBookBriefCommand(new BookBriefPromptBuilder()),
            $generateMasterCommand,
            new GeneratePrintReadyPdfCommand($projectDir),
            new QaGateCommand(),
            new CheckAssetCompletenessCommand(),
            $fakeKernel,
            $projectDir,
        );

        return new CommandTester($command);
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lc-factory-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->temporaryPaths[] = $directory;

        return $directory;
    }

    private function briefFixturePath(): string
    {
        return dirname(__DIR__, 3).'/resources/book-briefs/forest-of-lost-stars.yaml';
    }

    /** @return array<string, mixed> */
    private function masterFixture(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/book-blueprints/master-valid.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $fixture['metadata']['slug'] = 'forest-of-lost-stars';
        $fixture['metadata']['bookId'] = 'pilot-forest-of-lost-stars';
        $fixture['metadata']['productCode'] = 'BOOK_FOREST_OF_LOST_STARS';
        $fixture['metadata']['version'] = 2;
        $fixture['metadata']['status'] = 'draft';
        $fixture['metadata']['sourceLocale'] = 'fr';
        $fixture['metadata']['pageCount'] = 5;
        $fixture['metadata']['generationPageCount'] = 3;
        $fixture['metadata']['ageRange'] = '4-7';
        $fixture['metadata']['theme'] = ['magic', 'courage', 'wonder'];
        $fixture['metadata']['promise'] = 'The child helps fallen stars find their way back to the sky.';
        $fixture['metadata']['editorialPositioning'] = 'Premium bedtime storybook with Belgian-quality warmth.';
        $fixture['assets']['basePublicPath'] = '/uploads/books/forest-of-lost-stars';
        $fixture['assets']['defaults'] = [
            'cover-default' => '/uploads/books/forest-of-lost-stars/cover-default.svg',
            'dedication-default' => '/uploads/books/forest-of-lost-stars/dedication-default.svg',
            'page-1-default' => '/uploads/books/forest-of-lost-stars/page-1-default.svg',
            'summary-default' => '/uploads/books/forest-of-lost-stars/summary-default.svg',
            'back-cover-default' => '/uploads/books/forest-of-lost-stars/back-cover-default.svg',
        ];

        return $fixture;
    }

    /** @return array<string, int> */
    private function premiumScores(): array
    {
        return [
            'editorial' => 10,
            'imageability' => 10,
            'heroConsistency' => 10,
            'localeCompleteness' => 10,
            'bedtimeSafety' => 10,
            'premiumBelgium' => 10,
        ];
    }
}
