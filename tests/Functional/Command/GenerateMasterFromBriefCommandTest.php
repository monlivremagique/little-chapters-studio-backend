<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\BookBlueprint\BlueprintValidator;
use App\BookBrief\BookBriefPromptBuilder;
use App\BookBrief\BookBriefQaPromptBuilder;
use App\Command\GenerateMasterFromBriefCommand;
use App\Tests\Double\Replicate\FakeReplicateTextGenerationClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateMasterFromBriefCommandTest extends TestCase
{
    public function testDryRunWritesClaudePromptAndPayloadWithoutCallingReplicate(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/claude-master-prompt.txt');
        self::assertFileExists($outputDir.'/claude-master-payload.json');
        self::assertFileExists($outputDir.'/claude-master-debug.json');
        self::assertFileDoesNotExist($outputDir.'/master.json');
        self::assertSame([], $fakeClient->getCreateInputs());

        $prompt = (string) file_get_contents($outputDir.'/claude-master-prompt.txt');
        self::assertStringContainsString('Return ONLY a valid JSON object.', $prompt);
        self::assertStringContainsString('qa.scorecard', $prompt);
        self::assertStringContainsString('imageGeneration.provider exactly "replicate"', $prompt);
        self::assertStringContainsString('modelStrategy.model exactly "black-forest-labs/flux-2-pro"', $prompt);
        self::assertStringContainsString('never use locales.<locale>.scenes', $prompt);
        self::assertStringContainsString('No Stability, stable-diffusion, SDXL', $prompt);
    }

    public function testQaDryRunWritesQaArtifactsWithoutCallingReplicate(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
            '--qa-correct' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/claude-master-prompt.txt');
        self::assertFileExists($outputDir.'/claude-qa-prompt.txt');
        self::assertFileExists($outputDir.'/claude-qa-payload.json');
        self::assertFileExists($outputDir.'/claude-qa-debug.json');
        self::assertFileExists($outputDir.'/claude-qa-report.json');
        self::assertFileDoesNotExist($outputDir.'/master.json');

        $qaReport = json_decode((string) file_get_contents($outputDir.'/claude-qa-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DRY_RUN', $qaReport['verdict']);
        self::assertTrue($qaReport['dryRun']);

        $qaPrompt = (string) file_get_contents($outputDir.'/claude-qa-prompt.txt');
        self::assertStringContainsString('translationNaturalness', $qaPrompt);
        self::assertStringContainsString('child_pronoun_subject', $qaPrompt);
        self::assertStringContainsString('child_possessive_det', $qaPrompt);
    }

    public function testFullGenerationPipelineProducesValidMaster(): void
    {
        $fakeClient = new FakeReplicateTextGenerationClient();

        // Pass 1 — master generation: valid blueprint
        $fakeClient->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => [json_encode($this->masterFixture())]],
        ]);
        // Pass 2 — QA corrective pass: GO verdict with scores above 9.0
        $fakeClient->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => [json_encode([
                'verdict' => 'GO',
                'scores' => [
                    'editorial' => 9.2,
                    'imageability' => 9.1,
                    'heroConsistency' => 9.3,
                    'localeCompleteness' => 9.0,
                    'bedtimeSafety' => 9.5,
                    'premiumBelgium' => 9.2,
                    'translationNaturalness' => 9.0,
                ],
                'blockingIssues' => [],
                'correctedMaster' => (object) [],
            ])]],
        ]);

        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--qa-correct' => true,
        ]);

        self::assertSame(0, $statusCode);
        self::assertFileExists($outputDir.'/master.json');

        $master = json_decode((string) file_get_contents($outputDir.'/master.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('book_blueprint_v2', $master['schema']);
        self::assertSame(2, $master['schemaVersion']);
        self::assertSame('forest-of-lost-stars', $master['metadata']['slug']);

        $validator = new BlueprintValidator();
        $validation = $validator->validateMasterBlueprint($master);
        self::assertTrue($validation->isValid(), implode("\n", $validation->errors));
    }

    public function testFullPipelineRejectsInvalidMaster(): void
    {
        $fakeClient = new FakeReplicateTextGenerationClient();

        // Pass 1 — master generation: invalid blueprint (missing required fields)
        $fakeClient->seedNextPredictionSequence([
            ['status' => 'starting'],
            ['status' => 'succeeded', 'output' => [json_encode([
                'schema' => 'book_blueprint_v2',
                'schemaVersion' => 2,
                'metadata' => ['slug' => 'test-slug'],
                // missing most required fields intentionally
            ])]],
        ]);

        $outputDir = $this->createTemporaryDirectory();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--qa-correct' => true,
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('Generated master blueprint is invalid', $commandTester->getDisplay());
    }

    public function testFailsOnInvalidBriefYaml(): void
    {
        $fakeClient = new FakeReplicateTextGenerationClient();
        $commandTester = $this->createCommandTester($fakeClient);

        $invalidBrief = $this->createTemporaryDirectory().'/invalid.yaml';
        file_put_contents($invalidBrief, 'not: valid: yaml: [[[');

        $statusCode = $commandTester->execute([
            '--brief' => $invalidBrief,
            '--dry-run' => true,
        ]);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('Invalid YAML', $commandTester->getDisplay());
    }

    public function testDryRunWithQaCorrectIncludesTranslationNaturalnessInQaPrompt(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
            '--qa-correct' => true,
        ]);

        self::assertSame(0, $statusCode);
        $qaPrompt = (string) file_get_contents($outputDir.'/claude-qa-prompt.txt');

        // Check translationNaturalness score dimension is included
        self::assertStringContainsString('translationNaturalness', $qaPrompt, 'translationNaturalness should be in QA prompt');

        // Check pronoun placeholders are mentioned
        self::assertStringContainsString('child_pronoun_subject', $qaPrompt, 'child_pronoun_subject placeholder should be in QA prompt');
        self::assertStringContainsString('child_possessive_det', $qaPrompt, 'child_possessive_det placeholder should be in QA prompt');
    }

    public function testDryRunIncludesPronounPlaceholdersInMasterPrompt(): void
    {
        $outputDir = $this->createTemporaryDirectory();
        $fakeClient = new FakeReplicateTextGenerationClient();
        $commandTester = $this->createCommandTester($fakeClient);

        $statusCode = $commandTester->execute([
            '--brief' => $this->briefFixturePath(),
            '--output-dir' => $outputDir,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $statusCode);
        $prompt = (string) file_get_contents($outputDir.'/claude-master-prompt.txt');

        // Check pronoun placeholders are in the master prompt
        self::assertStringContainsString('child_pronoun_subject', $prompt);
        self::assertStringContainsString('child_possessive_det', $prompt);

        // Check hero_reference scene is included
        self::assertStringContainsString('hero_reference', $prompt);

        // Check NL quality instructions
        self::assertStringContainsString('Flanders', $prompt);
        self::assertStringContainsString('idiomatic Dutch', $prompt);
    }

    /** @return array{createInputs: list<mixed>, lastModel: string|null} */
    private function createCommandTester(FakeReplicateTextGenerationClient $fakeClient): CommandTester
    {
        $command = new GenerateMasterFromBriefCommand(
            new BookBriefPromptBuilder(),
            new BookBriefQaPromptBuilder(),
            new BlueprintValidator(),
            $fakeClient,
        );

        return new CommandTester($command);
    }

    private function createTemporaryDirectory(): string
    {
        $dir = sys_get_temp_dir().'/lc-brief-master-'.bin2hex(random_bytes(6));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Temporary directory could not be created: %s', $dir));
        }

        return $dir;
    }

    private function briefFixturePath(): string
    {
        $path = __DIR__.'/../../Fixtures/forest-of-lost-stars.yaml';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Test fixture not found: %s', $path));
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function masterFixture(): array
    {
        return [
            'schema' => 'book_blueprint_v2',
            'schemaVersion' => 2,
            'metadata' => [
                'bookId' => 'forest-of-lost-stars',
                'slug' => 'forest-of-lost-stars',
                'productCode' => 'BOOK_FOREST-OF-LOST-STARS',
                'version' => 2,
                'status' => 'draft',
                'sourceLocale' => 'fr',
                'supportedLocales' => ['fr', 'en', 'nl'],
                'pageCount' => 10,
                'generationPageCount' => 8,
                'ageRange' => '4-7',
                'theme' => ['magic', 'courage', 'wonder'],
                'promise' => 'A magical journey through a starry forest.',
                'editorialPositioning' => 'Premium bedtime story.',
            ],
            'locales' => [
                'fr' => [
                    'book' => ['title_template' => 'La forêt des étoiles perdues'],
                    'pages' => [
                        'cover' => ['title_template' => '', 'text_template' => ''],
                        'dedication' => ['title_template' => '', 'text_template' => ''],
                        'page_1' => ['title_template' => '', 'text_template' => ''],
                        'page_2' => ['title_template' => '', 'text_template' => ''],
                        'page_3' => ['title_template' => '', 'text_template' => ''],
                        'page_4' => ['title_template' => '', 'text_template' => ''],
                        'page_5' => ['title_template' => '', 'text_template' => ''],
                        'page_6' => ['title_template' => '', 'text_template' => ''],
                        'summary' => ['title_template' => '', 'text_template' => ''],
                        'backCover' => ['title_template' => '', 'text_template' => ''],
                    ],
                ],
                'en' => [
                    'book' => ['title_template' => 'Forest of Lost Stars'],
                    'pages' => [
                        'cover' => ['title_template' => '', 'text_template' => ''],
                        'dedication' => ['title_template' => '', 'text_template' => ''],
                        'page_1' => ['title_template' => '', 'text_template' => ''],
                        'page_2' => ['title_template' => '', 'text_template' => ''],
                        'page_3' => ['title_template' => '', 'text_template' => ''],
                        'page_4' => ['title_template' => '', 'text_template' => ''],
                        'page_5' => ['title_template' => '', 'text_template' => ''],
                        'page_6' => ['title_template' => '', 'text_template' => ''],
                        'summary' => ['title_template' => '', 'text_template' => ''],
                        'backCover' => ['title_template' => '', 'text_template' => ''],
                    ],
                ],
                'nl' => [
                    'book' => ['title_template' => 'Bos van verloren sterren'],
                    'pages' => [
                        'cover' => ['title_template' => '', 'text_template' => ''],
                        'dedication' => ['title_template' => '', 'text_template' => ''],
                        'page_1' => ['title_template' => '', 'text_template' => ''],
                        'page_2' => ['title_template' => '', 'text_template' => ''],
                        'page_3' => ['title_template' => '', 'text_template' => ''],
                        'page_4' => ['title_template' => '', 'text_template' => ''],
                        'page_5' => ['title_template' => '', 'text_template' => ''],
                        'page_6' => ['title_template' => '', 'text_template' => ''],
                        'summary' => ['title_template' => '', 'text_template' => ''],
                        'backCover' => ['title_template' => '', 'text_template' => ''],
                    ],
                ],
            ],
            'visualBible' => [
                'style_rules' => ['Premium watercolor storybook style'],
                'palette' => 'Night blue, star gold, forest green',
                'lighting' => 'Moonlit with warm star glow',
                'compositionRules' => ['Centered hero, warm emotional focus'],
            ],
            'heroBible' => [
                'identityRules' => ['Child hero: brown curls, rosy cheeks, wonder-filled eyes'],
                'characterDesign' => 'A warm 5-year-old with tousled brown curls, bright eyes, wearing a cozy blue nightgown.',
                'forbiddenDrift' => ['No age change', 'No hair color change', 'No clothing change'],
            ],
            'sceneDefinitions' => [
                [
                    'id' => 'hero_reference',
                    'type' => 'reference',
                    'pageNumber' => 1,
                    'personalizable' => true,
                    'assetKey' => 'hero-reference-default',
                    'camera' => 'front-facing portrait, bust or medium shot',
                    'composition' => 'centered character portrait on warm neutral background',
                    'foreground' => 'hero described in heroBible, warm expression',
                    'midground' => 'soft warm neutral background',
                    'background' => 'warm soft bokeh',
                    'lighting' => 'soft, warm, even studio portrait lighting',
                    'emotion' => 'warm, approachable, gentle',
                    'must_show' => ['hero face visible', 'hero outfit visible', 'warm expression'],
                    'must_not_show' => ['text', 'scene context', 'other characters'],
                    'promptTemplate' => 'Portrait of child hero with warm expression, soft neutral background',
                    'negativePrompt' => 'text, letters, scene context, other characters',
                ],
                [
                    'id' => 'cover',
                    'type' => 'cover',
                    'pageNumber' => 2,
                    'personalizable' => true,
                    'assetKey' => 'cover-default',
                    'camera' => 'medium shot',
                    'composition' => 'hero centered in magical forest',
                    'foreground' => 'hero looking up at stars',
                    'midground' => 'glowing fallen stars in forest',
                    'background' => 'moonlit forest',
                    'lighting' => 'moonlight with golden star glow',
                    'emotion' => 'wonder and discovery',
                    'must_show' => ['hero face', 'stars'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child in moonlit forest with glowing fallen stars',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'dedication',
                    'type' => 'dedication',
                    'pageNumber' => 3,
                    'personalizable' => false,
                    'assetKey' => 'dedication-default',
                    'camera' => 'close-up',
                    'composition' => 'centered still life',
                    'foreground' => 'open book with star',
                    'midground' => 'forest floor with moss',
                    'background' => 'soft dark forest bokeh',
                    'lighting' => 'warm candlelight',
                    'emotion' => 'peaceful intimacy',
                    'must_show' => ['open book', 'soft light'],
                    'must_not_show' => ['text', 'faces'],
                    'promptTemplate' => 'Open book on forest floor with single glowing star',
                    'negativePrompt' => 'text, letters, faces',
                ],
                [
                    'id' => 'page_1',
                    'type' => 'story',
                    'pageNumber' => 4,
                    'personalizable' => true,
                    'assetKey' => 'page-1-default',
                    'camera' => 'wide shot',
                    'composition' => 'hero small in vast forest',
                    'foreground' => 'hero crouching by fallen star',
                    'midground' => 'ancient oak tree roots',
                    'background' => 'deep forest with moonlight',
                    'lighting' => 'moonbeam spotlight on star',
                    'emotion' => 'curious discovery',
                    'must_show' => ['hero face', 'faintly glowing star', 'oak roots'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child crouching by glowing fallen star in ancient oak roots',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'page_2',
                    'type' => 'story',
                    'pageNumber' => 5,
                    'personalizable' => true,
                    'assetKey' => 'page-2-default',
                    'camera' => 'following shot',
                    'composition' => 'fox leading hero through ferns',
                    'foreground' => 'hero following fox',
                    'midground' => 'ferns with scattered stars',
                    'background' => 'deep forest path',
                    'lighting' => 'stars illuminating the path',
                    'emotion' => 'trust and adventure',
                    'must_show' => ['hero', 'red fox', 'scattered stars'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Red fox leading child through fern-filled forest with scattered glowing stars',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'page_3',
                    'type' => 'story',
                    'pageNumber' => 6,
                    'personalizable' => true,
                    'assetKey' => 'page-3-default',
                    'camera' => 'intimate medium shot',
                    'composition' => 'hero listening to owl',
                    'foreground' => 'hero sitting on mossy log',
                    'midground' => 'wise old owl on branch',
                    'background' => 'hollow tree interior',
                    'lighting' => 'soft golden light from owl\'s eyes',
                    'emotion' => 'learning and concentration',
                    'must_show' => ['hero listening', 'wise owl', 'mossy log'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child sitting on mossy log listening to wise owl on branch',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'page_4',
                    'type' => 'story',
                    'pageNumber' => 7,
                    'personalizable' => true,
                    'assetKey' => 'page-4-default',
                    'camera' => 'wide magical shot',
                    'composition' => 'hero singing, stars rising',
                    'foreground' => 'hero singing with closed eyes',
                    'midground' => 'stars lifting in spiral',
                    'background' => 'night sky clearing',
                    'lighting' => 'stars creating golden spiral light',
                    'emotion' => 'magical triumph and warmth',
                    'must_show' => ['hero singing', 'stars rising in spiral', 'golden light'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child singing as fallen stars rise in a golden spiral toward the night sky',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'page_5',
                    'type' => 'story',
                    'pageNumber' => 8,
                    'personalizable' => true,
                    'assetKey' => 'page-5-default',
                    'camera' => 'wide peaceful shot',
                    'composition' => 'hero lying in clearing with animal friends',
                    'foreground' => 'hero lying on soft moss',
                    'midground' => 'fox and owl nearby',
                    'background' => 'star-filled sky',
                    'lighting' => 'gentle starlight on faces',
                    'emotion' => 'peace and belonging',
                    'must_show' => ['hero', 'fox', 'owl', 'star-filled sky'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child lying on moss with fox and owl under star-filled sky',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'page_6',
                    'type' => 'story',
                    'pageNumber' => 9,
                    'personalizable' => true,
                    'assetKey' => 'page-6-default',
                    'camera' => 'intimate bedroom shot',
                    'composition' => 'hero in bed looking out window',
                    'foreground' => 'hero in bed, peaceful',
                    'midground' => 'bedroom with moonlight',
                    'background' => 'window with stars winking',
                    'lighting' => 'soft bedroom nightlight',
                    'emotion' => 'peaceful sleepiness, full heart',
                    'must_show' => ['hero in bed', 'window with stars', 'peaceful expression'],
                    'must_not_show' => ['text'],
                    'promptTemplate' => 'Child in cozy bed looking out window at winking stars',
                    'negativePrompt' => 'text, letters, signs',
                ],
                [
                    'id' => 'summary',
                    'type' => 'summary',
                    'pageNumber' => 10,
                    'personalizable' => true,
                    'assetKey' => 'summary-default',
                    'camera' => 'symbolic close-up',
                    'composition' => 'small star in child\'s hand',
                    'foreground' => 'cupped hands holding tiny star',
                    'midground' => 'warm golden glow',
                    'background' => 'soft starry bokeh',
                    'lighting' => 'star illuminating hands',
                    'emotion' => 'gratitude and wonder',
                    'must_show' => ['small hands', 'tiny star', 'warm glow'],
                    'must_not_show' => ['text', 'faces'],
                    'promptTemplate' => 'Small child hands cupping a tiny glowing star, warm golden light',
                    'negativePrompt' => 'text, letters, faces',
                ],
                [
                    'id' => 'backCover',
                    'type' => 'backCover',
                    'pageNumber' => 11,
                    'personalizable' => false,
                    'assetKey' => 'back-cover-default',
                    'camera' => 'wide establishing shot',
                    'composition' => 'forest at peace, stars in place',
                    'foreground' => 'empty forest clearing',
                    'midground' => 'stars twinkling above trees',
                    'background' => 'calm night sky',
                    'lighting' => 'soft starlight over forest',
                    'emotion' => 'peaceful closure',
                    'must_show' => ['peaceful forest', 'stars in sky'],
                    'must_not_show' => ['text', 'people'],
                    'promptTemplate' => 'Peaceful forest clearing with stars twinkling in calm night sky',
                    'negativePrompt' => 'text, letters, people',
                ],
            ],
            'assets' => [
                'basePublicPath' => '/uploads/books/forest-of-lost-stars/',
                'defaults' => [
                    'cover-default' => '/uploads/books/forest-of-lost-stars/cover-default.png',
                    'dedication-default' => '/uploads/books/forest-of-lost-stars/dedication-default.png',
                    'hero-reference-default' => '/uploads/books/forest-of-lost-stars/hero-reference-default.png',
                    'page-1-default' => '/uploads/books/forest-of-lost-stars/page-1-default.png',
                    'page-2-default' => '/uploads/books/forest-of-lost-stars/page-2-default.png',
                    'page-3-default' => '/uploads/books/forest-of-lost-stars/page-3-default.png',
                    'page-4-default' => '/uploads/books/forest-of-lost-stars/page-4-default.png',
                    'page-5-default' => '/uploads/books/forest-of-lost-stars/page-5-default.png',
                    'page-6-default' => '/uploads/books/forest-of-lost-stars/page-6-default.png',
                    'summary-default' => '/uploads/books/forest-of-lost-stars/summary-default.png',
                    'back-cover-default' => '/uploads/books/forest-of-lost-stars/back-cover-default.png',
                ],
            ],
            'imageGeneration' => [
                'provider' => 'replicate',
                'modelStrategy' => ['model' => 'black-forest-labs/flux-2-pro'],
                'negativePromptDefault' => 'text, letters, signs, labels, logos',
                'resolution' => '1 MP',
                'outputFormat' => 'png',
                'inputImages' => ['pageReference' => true, 'childPhoto' => true],
            ],
            'qa' => [
                'requiredPageTypes' => ['cover', 'dedication', 'story', 'summary', 'backCover'],
                'requiredLocales' => ['fr', 'en', 'nl'],
                'placeholderPolicy' => ['allowed' => ['{child_name}', '{child_pronoun_subject}', '{child_possessive_det}'], 'forbidden' => []],
                'rules' => ['Gender neutral language', 'No text in images', 'Hero consistency'],
                'scorecard' => [
                    'editorialScore' => ['value' => 92, 'rationale' => 'Strong emotional arc.'],
                    'imageabilityScore' => ['value' => 91, 'rationale' => 'Visually rich scenes.'],
                    'heroConsistencyScore' => ['value' => 93, 'rationale' => 'Clear hero bible.'],
                    'localeCompletenessScore' => ['value' => 90, 'rationale' => 'All three locales complete.'],
                ],
            ],
        ];
    }
}
