<?php

declare(strict_types=1);

namespace App\Tests\Unit\BookBrief;

use App\BookBrief\BookBriefPromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that BookBriefPromptBuilder correctly injects enrichment fields
 * into the prompt when present in the brief, and remains backward-compatible
 * when they are absent.
 */
final class BookBriefPromptBuilderTest extends TestCase
{
    private BookBriefPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BookBriefPromptBuilder();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Backward compatibility — minimal brief (no enrichment fields)
    // ─────────────────────────────────────────────────────────────────────────

    public function testMinimalBriefBuildsWithoutErrors(): void
    {
        $result = $this->builder->build($this->minimalBrief());

        self::assertNotEmpty($result['prompt']);
        self::assertSame('forest-of-lost-stars', $result['slug']);
        self::assertSame(0, $result['debug']['arcType'] ?? 0, 'arcType must be null for minimal brief');
        self::assertSame(0, $result['debug']['scenesCount']);
    }

    public function testMinimalBriefPromptContainsRequiredStructuralConstraints(): void
    {
        $result = $this->builder->build($this->minimalBrief());
        $prompt = $result['prompt'];

        self::assertStringContainsString('book_blueprint_v2', $prompt);
        self::assertStringContainsString('heroBible', $prompt);
        self::assertStringContainsString('visualBible', $prompt);
        self::assertStringContainsString('black-forest-labs/flux-2-pro', $prompt);
        self::assertStringContainsString('Never use locales.<locale>.scenes', $prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enriched fields — present in prompt when supplied
    // ─────────────────────────────────────────────────────────────────────────

    public function testArcTypeAndClimaxPageInjectedWhenPresent(): void
    {
        $brief = $this->minimalBrief() + [
            'arc_type' => 'discovery-and-return',
            'climax_page' => 'page_4',
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('arc_type: discovery-and-return', $prompt);
        self::assertStringContainsString('climax_page: page_4', $prompt);
        self::assertStringContainsString('emotional peak', $prompt);
        self::assertSame('discovery-and-return', $result['debug']['arcType']);
        self::assertSame('page_4', $result['debug']['climaxPage']);
    }

    public function testSettingAndCulturalContextInjectedWhenPresent(): void
    {
        $brief = $this->minimalBrief() + [
            'setting' => 'a moonlit enchanted forest',
            'cultural_context' => 'Belgique premium — chaleur wallonne',
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('setting: a moonlit enchanted forest', $prompt);
        self::assertStringContainsString('cultural_context: Belgique premium', $prompt);
    }

    public function testParentEmotionGoalInjectedWhenPresent(): void
    {
        $brief = $this->minimalBrief() + [
            'parent_emotion_goal' => 'Le parent veut que l\'enfant s\'endorme serein',
        ];

        $result = $this->builder->build($brief);

        self::assertStringContainsString('parent_emotion_goal:', $result['prompt']);
    }

    public function testSecondaryCharactersInjectedWhenPresent(): void
    {
        $brief = $this->minimalBrief() + [
            'secondary_characters' => ['un renard roux', 'une chouette sage'],
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('secondary_characters: un renard roux, une chouette sage', $prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scene scripts — both formats supported
    // ─────────────────────────────────────────────────────────────────────────

    public function testStructuredScenesArrayInjectedIntoPrompt(): void
    {
        $brief = $this->minimalBrief() + [
            'scenes' => [
                ['id' => 'page_1', 'moment' => 'L\'enfant decouvre une etoile tombee.'],
                ['id' => 'page_2', 'moment' => 'Le renard guide l\'enfant.'],
            ],
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('scene_scripts', $prompt);
        self::assertStringContainsString('page_1: L\'enfant decouvre une etoile tombee.', $prompt);
        self::assertStringContainsString('page_2: Le renard guide l\'enfant.', $prompt);
        self::assertSame(2, $result['debug']['scenesCount']);
    }

    public function testFlatSceneNMomentKeysInjectedIntoPrompt(): void
    {
        $brief = $this->minimalBrief() + [
            'scene_1_moment' => 'L\'enfant arrive dans la foret.',
            'scene_2_moment' => 'Il trouve la premiere etoile.',
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('scene_scripts', $prompt);
        self::assertStringContainsString('page_1: L\'enfant arrive dans la foret.', $prompt);
        self::assertStringContainsString('page_2: Il trouve la premiere etoile.', $prompt);
        self::assertSame(2, $result['debug']['scenesCount']);
    }

    public function testStructuredScenesFormatTakesPrecedenceOverFlatKeys(): void
    {
        $brief = $this->minimalBrief() + [
            'scenes' => [
                ['id' => 'page_1', 'moment' => 'Scene from structured array.'],
            ],
            'scene_1_moment' => 'Scene from flat key — must NOT appear.',
        ];

        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('Scene from structured array.', $prompt);
        self::assertStringNotContainsString('Scene from flat key', $prompt);
        self::assertSame(1, $result['debug']['scenesCount']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enrichment fields absent — no null/empty lines pollute prompt
    // ─────────────────────────────────────────────────────────────────────────

    public function testAbsentEnrichmentFieldsLeaveNoStrayNullLinesInPrompt(): void
    {
        $result = $this->builder->build($this->minimalBrief());
        $prompt = $result['prompt'];

        // implode with array_filter — no bare null or empty lines from ternary expressions
        self::assertStringNotContainsString("\n\n\n", $prompt, 'Triple newlines suggest null->empty coercion leaking into prompt');
        self::assertStringNotContainsString('arc_type:', $prompt);
        self::assertStringNotContainsString('climax_page:', $prompt);
        self::assertStringNotContainsString('scene_scripts', $prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Forest-of-lost-stars brief fixture (enriched YAML) round-trip
    // ─────────────────────────────────────────────────────────────────────────

    public function testForestOfLostStarsEnrichedBriefProducesArcAndScenesInPrompt(): void
    {
        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/forest-of-lost-stars.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('forest-of-lost-stars.yaml fixture not found.');
        }

        $brief = \Symfony\Component\Yaml\Yaml::parseFile($briefPath);
        $result = $this->builder->build($brief);
        $prompt = $result['prompt'];

        self::assertStringContainsString('arc_type:', $prompt);
        self::assertStringContainsString('climax_page:', $prompt);
        self::assertStringContainsString('scene_scripts', $prompt);
        self::assertStringContainsString('page_4', $prompt);
        self::assertSame(6, $result['debug']['scenesCount']);
    }

    public function testVilleEcoleBriefLoadsWithoutErrors(): void
    {
        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/ville-ecole-3-5.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('ville-ecole-3-5.yaml fixture not found.');
        }

        $brief = \Symfony\Component\Yaml\Yaml::parseFile($briefPath);
        $result = $this->builder->build($brief);

        self::assertSame('ville-ecole', $result['slug']);
        self::assertStringContainsString('comfort-to-courage', $result['prompt']);
        self::assertSame(6, $result['debug']['scenesCount']);
    }

    public function testEspaceRobotBriefLoadsWithoutErrors(): void
    {
        $briefPath = dirname(__DIR__, 3).'/resources/book-briefs/espace-robot-8-10.yaml';
        if (!is_file($briefPath)) {
            self::markTestSkipped('espace-robot-8-10.yaml fixture not found.');
        }

        $brief = \Symfony\Component\Yaml\Yaml::parseFile($briefPath);
        $result = $this->builder->build($brief);

        self::assertSame('espace-robot', $result['slug']);
        self::assertStringContainsString('quest-with-revelation', $result['prompt']);
        self::assertSame(6, $result['debug']['scenesCount']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function minimalBrief(): array
    {
        return [
            'slug' => 'forest-of-lost-stars',
            'title' => 'La foret des etoiles perdues',
            'story_subject' => 'Un enfant aide des etoiles tombees.',
            'main_emotion' => 'emerveillement doux',
            'learning_message' => 'Les petits gestes illuminent le monde.',
            'age' => '4-7',
            'visual_style' => 'premium watercolor storybook',
            'languages' => ['fr', 'en', 'nl'],
            'theme' => ['magic', 'courage'],
        ];
    }
}
