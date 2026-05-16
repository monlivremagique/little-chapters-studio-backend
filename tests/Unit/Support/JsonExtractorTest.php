<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\JsonExtractor;
use PHPUnit\Framework\TestCase;

final class JsonExtractorTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Clean valid JSON
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractCleanJson(): void
    {
        $input = '{"verdict":"GO","scores":{"editorial":9.5}}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('GO', $result['verdict']);
        self::assertSame(9.5, $result['scores']['editorial']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Markdown code fences
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithMarkdownFences(): void
    {
        $input = "```json\n{\"verdict\":\"GO\"}\n```";
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('GO', $result['verdict']);
    }

    public function testExtractJsonWithMarkdownFencesNoLang(): void
    {
        $input = "```\n{\"verdict\":\"NO_GO\"}\n```";
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('NO_GO', $result['verdict']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Prose before/after JSON
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithLeadingProse(): void
    {
        $input = "Here is the corrected master I prepared:\n{\"verdict\":\"GO\"}\nPlease use this.";
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('GO', $result['verdict']);
    }

    public function testExtractJsonWithTrailingProse(): void
    {
        $input = "{\"scores\":{\"editorial\":9.2}}\n\nI hope this helps!";
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame(9.2, $result['scores']['editorial']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Partial / malformed JSON
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithTrailingComma(): void
    {
        $input = '{"verdict":"GO","scores":{"editorial":9.2,"imageability":9.1,}}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('GO', $result['verdict']);
    }

    public function testExtractJsonWithUnquotedKeys(): void
    {
        $input = '{verdict:"GO",scores:{editorial:9.2}}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('GO', $result['verdict']);
    }

    public function testExtractJsonWithBooleanTrueValue(): void
    {
        $input = '{"success":True,"count":5}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertTrue($result['success']);
    }

    public function testExtractJsonWithNullValue(): void
    {
        $input = '{"data":Null,"name":"test"}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertNull($result['data']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Multi-block JSON — multiple brace pairs, pick outermost
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithEmbeddedObject(): void
    {
        $input = '{"outer":{"inner":"value"},"name":"test"}';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('test', $result['name']);
        self::assertSame('value', $result['outer']['inner']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Empty / invalid input
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractEmptyStringReturnsNull(): void
    {
        self::assertNull(JsonExtractor::extract(''));
    }

    public function testExtractInvalidTextReturnsNull(): void
    {
        self::assertNull(JsonExtractor::extract('This is not JSON at all'));
    }

    public function testExtractNullBytesReturnsNull(): void
    {
        self::assertNull(JsonExtractor::extract("\0\0\0"));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Real-world scenarios from Claude outputs
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractClaudeQaResponseWithBlockingIssues(): void
    {
        $input = <<<'JSON'
        Here is my QA assessment:

        {
            "verdict": "NO_GO",
            "scores": {
                "editorial": 8.5,
                "imageability": 9.2,
                "heroConsistency": 8.0,
                "localeCompleteness": 9.1,
                "bedtimeSafety": 9.5,
                "premiumBelgium": 8.8
            },
            "blockingIssues": [
                "Editorial score below 9.0",
                "Hero consistency below 9.0"
            ],
            "correctedMaster": {
                "metadata": {
                    "slug": "test-book",
                    "status": "published"
                }
            }
        }

        I applied all corrections above.
        JSON;

        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('NO_GO', $result['verdict']);
        self::assertCount(2, $result['blockingIssues']);
        self::assertSame('test-book', $result['correctedMaster']['metadata']['slug']);
    }

    public function testExtractClaudeMasterResponse(): void
    {
        $input = <<<'JSON'
        ```json
        {
            "schema": "book_blueprint_v2",
            "schemaVersion": 2,
            "metadata": {
                "slug": "espace-robot",
                "status": "draft",
                "supportedLocales": ["fr", "en", "nl"],
                "pageCount": 10
            },
            "locales": {
                "fr": { "book": { "title_template": "L'aventure" }, "pages": {} },
                "en": { "book": { "title_template": "The adventure" }, "pages": {} },
                "nl": { "book": { "title_template": "Het avontuur" }, "pages": {} }
            },
            "sceneDefinitions": [],
            "assets": { "basePublicPath": "/uploads/books/espace-robot", "defaults": {} },
            "imageGeneration": { "provider": "replicate", "modelStrategy": { "model": "black-forest-labs/flux-2-pro" } },
            "visualBible": { "style_rules": [], "palette": "warm", "lighting": "golden" },
            "heroBible": { "identityRules": [], "characterDesign": "child", "forbiddenDrift": [] },
            "qa": { "requiredPageTypes": [], "requiredLocales": [], "placeholderPolicy": { "allowed": ["{child_name}"], "forbidden": [] }, "rules": [], "scorecard": {} }
        }
        ```
        JSON;

        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('book_blueprint_v2', $result['schema']);
        self::assertSame('espace-robot', $result['metadata']['slug']);
        self::assertCount(3, $result['metadata']['supportedLocales']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Trailing garbage after JSON
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithTrailingGarbage(): void
    {
        $input = '{"valid":true}...some text after...';
        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertTrue($result['valid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Nested correctedMaster — common Claude nesting error
    // ─────────────────────────────────────────────────────────────────────────

    public function testExtractJsonWithNestedCorrectedMaster(): void
    {
        $input = <<<'JSON'
        {
            "verdict": "NO_GO",
            "scores": {},
            "blockingIssues": [],
            "correctedMaster": {
                "verdict": "GO",
                "scores": {},
                "blockingIssues": [],
                "correctedMaster": {
                    "metadata": { "slug": "nested" }
                }
            }
        }
        JSON;

        $result = JsonExtractor::extract($input);

        self::assertNotNull($result);
        self::assertSame('NO_GO', $result['verdict']);
        // Verify we extracted the outer envelope, not a partial inner one
        self::assertArrayHasKey('correctedMaster', $result);
    }
}
