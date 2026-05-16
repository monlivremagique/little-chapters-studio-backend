<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\BlueprintValidator;
use App\Command\ValidateBlueprintCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ValidateBlueprintCommandTest extends TestCase
{
    private const FIXTURES_DIR = '/tests/Fixtures/book-blueprints';

    /**
     * @dataProvider provideBlueprintValidationCases
     */
    public function testValidateBlueprintCommand(
        string $fixtureFile,
        bool $runtime,
        int $expectedStatusCode,
        string $expectedOutputFragment,
    ): void {
        $commandTester = new CommandTester(new ValidateBlueprintCommand(new BlueprintValidator()));
        $arguments = ['--file' => dirname(__DIR__, 3) . self::FIXTURES_DIR . '/' . $fixtureFile];

        if ($runtime) {
            $arguments['--runtime'] = true;
        }

        $statusCode = $commandTester->execute($arguments);
        $display = $commandTester->getDisplay();

        self::assertSame($expectedStatusCode, $statusCode);
        self::assertStringContainsString($expectedOutputFragment, $display);
        self::assertStringContainsString('Status:', $display);
        self::assertStringContainsString('Pages:', $display);
        self::assertStringContainsString('Locales detected:', $display);
        self::assertStringContainsString('Assets referenced:', $display);
    }

    /**
     * @return iterable<string, array{0:string, 1:bool, 2:int, 3:string}>
     */
    public static function provideBlueprintValidationCases(): iterable
    {
        yield 'master v2 valid' => ['master-valid.json', false, 0, 'Master Blueprint V2 is valid.'];
        yield 'master v2 invalid without nl locale' => ['master-invalid-missing-nl.json', false, 1, 'The "metadata.supportedLocales" field must contain "nl".'];
        yield 'master v2 invalid missing asset key' => ['master-invalid-missing-asset-key.json', false, 1, 'missing from assets.defaults'];
        yield 'master v2 invalid stability provider' => ['master-invalid-stability-provider.json', false, 1, 'The "imageGeneration.provider" field must equal "replicate".'];
        yield 'master v2 invalid locales scenes' => ['master-invalid-locales-scenes.json', false, 1, 'The "locales.fr.scenes" node is not allowed.'];
        yield 'master v2 invalid scorecard rationale' => ['master-invalid-scorecard-rationale.json', false, 1, 'The "qa.scorecard.editorialScore.rationale" field is required.'];
        yield 'master v2 invalid scene id page1' => ['master-invalid-scene-id-page1.json', false, 1, 'sceneDefinitions[2].id "page1" is not allowed. Use snake case like "page_1".'];
        yield 'runtime valid' => ['runtime-valid.json', true, 0, 'Runtime Blueprint is valid.'];
        yield 'runtime invalid without pages' => ['runtime-invalid-no-pages.json', true, 1, 'The "pages" list must not be empty.'];
        yield 'runtime invalid forbidden placeholder' => ['runtime-invalid-bad-placeholder.json', true, 1, 'Only {child_name} is allowed.'];
        yield 'runtime invalid nl gendered pronouns' => ['runtime-invalid-nl-gendered.json', true, 1, 'hij'];
    }
}
