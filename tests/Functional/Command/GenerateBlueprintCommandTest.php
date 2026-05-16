<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\BlueprintProjector;
use App\BookBlueprint\BlueprintValidator;
use App\Command\GenerateBlueprintCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateBlueprintCommandTest extends TestCase
{
    private const FIXTURES_DIR = '/tests/Fixtures/book-blueprints';

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (scandir($directory) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                @unlink($directory.'/'.$entry);
            }

            @rmdir($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function testGeneratesFrNlEnFromMasterValidWithLocalizedTextsAndStablePrompts(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute([
            '--source' => $this->fixturePath('master-valid.json'),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/runtime.fr.json');
        self::assertFileExists($outputDir.'/runtime.en.json');
        self::assertFileExists($outputDir.'/runtime.nl.json');

        $fr = $this->readJson($outputDir.'/runtime.fr.json');
        $en = $this->readJson($outputDir.'/runtime.en.json');
        $nl = $this->readJson($outputDir.'/runtime.nl.json');

        self::assertSame('{child_name} et l\'aventure enchantee', $fr['title_template']);
        self::assertSame('{child_name} and the Enchanted Adventure', $en['title_template']);
        self::assertSame('{child_name} en het Betoverde Avontuur', $nl['title_template']);

        self::assertSame('{child_name} ouvre la porte du grand bois et entend les arbres lui souhaiter la bienvenue.', $this->findPage($fr, 'page_1')['text_template']);
        self::assertSame('{child_name} opens the door to the great forest and hears the trees welcoming them.', $this->findPage($en, 'page_1')['text_template']);
        self::assertSame('{child_name} opent de deur naar het grote woud en hoort de bomen {child_name} verwelkomen met een zacht gefluister.', $this->findPage($nl, 'page_1')['text_template']);

        self::assertSame($this->findPage($fr, 'cover')['prompt_template'], $this->findPage($en, 'cover')['prompt_template']);
        self::assertSame($this->findPage($fr, 'cover')['prompt_template'], $this->findPage($nl, 'cover')['prompt_template']);
    }

    public function testGeneratesPagesOrderedByPageNumber(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute([
            '--source' => $this->fixturePath('master-valid-subset-order.json'),
            '--output-dir' => $outputDir,
            '--locales' => 'fr',
        ]);

        self::assertSame(0, $statusCode);
        $fr = $this->readJson($outputDir.'/runtime.fr.json');

        self::assertSame(['cover', 'page_1', 'summary', 'dedication', 'backCover'], array_column($fr['pages'], 'id'));
        self::assertSame([1, 2, 3, 4, 5], array_column($fr['pages'], 'page_number'));
    }

    public function testLocalesOptionGeneratesOnlyRequestedFiles(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute([
            '--source' => $this->fixturePath('master-valid.json'),
            '--output-dir' => $outputDir,
            '--locales' => 'fr,nl',
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/runtime.fr.json');
        self::assertFileExists($outputDir.'/runtime.nl.json');
        self::assertFileDoesNotExist($outputDir.'/runtime.en.json');
    }

    public function testDryRunWritesNothing(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute([
            '--source' => $this->fixturePath('master-valid.json'),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertSame(['.', '..'], scandir($outputDir));
    }

    public function testFailsWithoutWritingFilesWhenProjectedRuntimeIsInvalid(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute([
            '--source' => $this->fixturePath('master-valid-but-runtime-invalid.json'),
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('Projected runtime validation failed.', $commandTester->getDisplay());
        self::assertSame(['.', '..'], scandir($outputDir));
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester(new GenerateBlueprintCommand(new BlueprintValidator(), new BlueprintProjector()));
    }

    private function fixturePath(string $fileName): string
    {
        return dirname(__DIR__, 3).self::FIXTURES_DIR.'/'.$fileName;
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/lc-blueprint-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $runtime */
    private function findPage(array $runtime, string $pageId): array
    {
        foreach ($runtime['pages'] as $page) {
            if (is_array($page) && ($page['id'] ?? null) === $pageId) {
                return $page;
            }
        }

        self::fail(sprintf('Page "%s" not found.', $pageId));
    }
}
