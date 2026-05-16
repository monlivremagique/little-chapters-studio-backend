<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class BlueprintValidator
{
    private const REQUIRED_IMAGE_PROVIDER = 'replicate';

    private const REQUIRED_IMAGE_MODEL = 'black-forest-labs/flux-2-pro';

    private const LEGACY_ALLOWED_MODEL_STRATEGY = 'flux_consistent_storybook_portrait_guided';

    /** @var list<string> */
    private const ALLOWED_PLACEHOLDERS = [
        '{child_name}',
        '{child_pronoun_subject}',
        '{child_possessive_det}',
    ];

    /** @var list<string> */
    private const REQUIRED_SHORT_LOCALES = ['fr', 'en', 'nl'];

    /** @var list<string> */
    private const REQUIRED_PAGE_TYPES = ['cover', 'dedication', 'summary', 'backCover'];

    /** @var list<string> */
    private const ALLOWED_PAGE_TYPES = ['cover', 'story', 'dedication', 'summary', 'backCover', 'reference'];

    /** @var list<string> */
    private const GENERATABLE_PAGE_TYPES = ['cover', 'story', 'backCover'];

    /** @param array<string, mixed> $blueprint */
    public function validateMasterBlueprint(array $blueprint): BlueprintValidationResult
    {
        $errors = [];
        $warnings = [];
        $locales = [];
        $assets = [];

        if (($blueprint['schema'] ?? null) !== 'book_blueprint_v2') {
            $errors[] = 'The "schema" field must equal "book_blueprint_v2".';
        }

        if ((int) ($blueprint['schemaVersion'] ?? 0) !== 2) {
            $errors[] = 'The "schemaVersion" field must equal 2.';
        }

        $metadata = is_array($blueprint['metadata'] ?? null) ? $blueprint['metadata'] : [];
        if ('' === trim((string) ($metadata['slug'] ?? ''))) {
            $errors[] = 'The "metadata.slug" field is required.';
        }

        $supportedLocales = is_array($metadata['supportedLocales'] ?? null) ? array_values(array_filter($metadata['supportedLocales'], 'is_string')) : [];
        foreach (self::REQUIRED_SHORT_LOCALES as $locale) {
            if (!in_array($locale, $supportedLocales, true)) {
                $errors[] = sprintf('The "metadata.supportedLocales" field must contain "%s".', $locale);
            }
        }

        $localesNode = is_array($blueprint['locales'] ?? null) ? $blueprint['locales'] : [];
        $locales = array_keys($localesNode);
        sort($locales);
        foreach (self::REQUIRED_SHORT_LOCALES as $locale) {
            $localeNode = is_array($localesNode[$locale] ?? null) ? $localesNode[$locale] : null;
            if (!is_array($localeNode)) {
                $errors[] = sprintf('The "locales.%s" object is required.', $locale);
                continue;
            }

            if (array_key_exists('scenes', $localeNode)) {
                $errors[] = sprintf('The "locales.%s.scenes" node is not allowed. Use "locales.%s.pages".', $locale, $locale);
            }

            if (!is_array($localeNode['pages'] ?? null)) {
                $errors[] = sprintf('The "locales.%s.pages" object is required.', $locale);
                continue;
            }

            foreach ($localeNode['pages'] as $pageKey => $pageNode) {
                if (!is_array($pageNode)) {
                    continue;
                }

                if (array_key_exists('title', $pageNode) && !array_key_exists('title_template', $pageNode)) {
                    $errors[] = sprintf('The "locales.%s.pages.%s.title" field is not allowed. Use "title_template".', $locale, (string) $pageKey);
                }

                if (array_key_exists('text', $pageNode) && !array_key_exists('text_template', $pageNode)) {
                    $errors[] = sprintf('The "locales.%s.pages.%s.text" field is not allowed. Use "text_template".', $locale, (string) $pageKey);
                }
            }
        }

        $sceneDefinitions = is_array($blueprint['sceneDefinitions'] ?? null) ? $blueprint['sceneDefinitions'] : [];
        if ([] === $sceneDefinitions) {
            $errors[] = 'The "sceneDefinitions" list must not be empty.';
        }

        $assetsNode = is_array($blueprint['assets'] ?? null) ? $blueprint['assets'] : [];
        if ('' === trim((string) ($assetsNode['basePublicPath'] ?? ''))) {
            $errors[] = 'The "assets.basePublicPath" field is required.';
        }
        $defaults = is_array($assetsNode['defaults'] ?? null) ? $assetsNode['defaults'] : [];

        $presentTypes = [];

        foreach ($sceneDefinitions as $index => $sceneDefinition) {
            if (!is_array($sceneDefinition)) {
                $errors[] = sprintf('sceneDefinitions[%d] must be an object.', $index);
                continue;
            }

            $id = (string) ($sceneDefinition['id'] ?? '');
            if ('' === $id) {
                $errors[] = 'sceneDefinitions requires an "id" field.';
            }

            $type = (string) ($sceneDefinition['type'] ?? '');
            if ('' === $type) {
                $errors[] = sprintf('sceneDefinitions[%s] requires a "type" field.', $id);
            }

            if ('' !== $type && !in_array($type, self::ALLOWED_PAGE_TYPES, true)) {
                $warnings[] = sprintf('sceneDefinitions[%s] has unknown type "%s". Known: %s.', $id, $type, implode(', ', self::ALLOWED_PAGE_TYPES));
            }

            $pageNumber = (int) ($sceneDefinition['pageNumber'] ?? 0);
            if ($pageNumber < 1) {
                $errors[] = sprintf('sceneDefinitions[%s] requires a positive "pageNumber".', $id);
            }

            if (!array_key_exists('promptTemplate', $sceneDefinition)) {
                $errors[] = sprintf('sceneDefinitions[%s] has no "promptTemplate".', $id);
            }

            if (!array_key_exists('negativePrompt', $sceneDefinition) && !in_array($type, ['dedication', 'summary'], true)) {
                $errors[] = sprintf('sceneDefinitions[%s] has no "negativePrompt".', $id);
            }

            if (!is_bool($sceneDefinition['personalizable'] ?? null)) {
                $errors[] = sprintf('sceneDefinitions[%s] requires a boolean "personalizable".', $id);
            }

            $assetKey = (string) ($sceneDefinition['assetKey'] ?? '');
            if ('' === $assetKey) {
                $errors[] = sprintf('sceneDefinitions[%s] requires an "assetKey".', $id);
            }

            $presentTypes[] = $type;
        }

        // Required page types validation
        foreach (self::REQUIRED_PAGE_TYPES as $requiredType) {
            if (!in_array($requiredType, $presentTypes, true)) {
                $warnings[] = sprintf('No sceneDefinition with type "%s" found.', $requiredType);
            }
        }

        // Assets
        $imageGeneration = is_array($blueprint['imageGeneration'] ?? null) ? $blueprint['imageGeneration'] : [];
        $errors = [...$errors, ...$this->validateImageGenerationModel($imageGeneration)];
        $errors = [...$errors, ...$this->validateQaStructure($blueprint)];
        $errors = [...$errors, ...$this->validateQaScorecard($blueprint)];

        return new BlueprintValidationResult($errors, $warnings, count($sceneDefinitions), $locales, $assets);
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return BlueprintValidationResult
     */
    public function validateRuntimeBlueprint(array $blueprint): BlueprintValidationResult
    {
        $errors = [];
        $warnings = [];
        $locales = [];
        $assets = [];

        $version = (int) ($blueprint['version'] ?? $blueprint['schemaVersion'] ?? 0);
        if ($version < 2) {
            $errors[] = 'Runtime blueprint version must be >= 2.';
        }

        $metadata = is_array($blueprint['metadata'] ?? null) ? $blueprint['metadata'] : [];
        $shortLocale = trim((string) ($blueprint['locale'] ?? $metadata['locale'] ?? ''));
        if ('' === $shortLocale) {
            $errors[] = 'The "locale" field is required (top-level or metadata.locale).';
        }

        $titleTemplate = trim((string) ($blueprint['title_template'] ?? ''));
        if ('' === $titleTemplate) {
            $errors[] = 'The "title_template" field is required.';
        }

        $styleRules = $blueprint['style_rules'] ?? [];
        if (!is_array($styleRules) || [] === $styleRules) {
            $warnings[] = 'The "style_rules" list is empty or missing.';
        }

        $negativePromptDefault = trim((string) ($blueprint['negative_prompt_default'] ?? ''));
        if ('' === $negativePromptDefault) {
            $warnings[] = 'The "negative_prompt_default" is missing.';
        }

        $pages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];
        if ([] === $pages) {
            $errors[] = 'The "pages" list is empty.';
        }

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = (string) ($page['id'] ?? sprintf('page_%d', $index));
            $locales[] = $page['locale'] ?? $shortLocale;

            if (!isset($page['id'])) {
                $errors[] = sprintf('pages[%d] requires an "id" field.', $index);
            }

            if (!isset($page['type'])) {
                $errors[] = sprintf('pages[%s] requires a "type" field.', $pageId);
            } elseif (!in_array($page['type'], self::ALLOWED_PAGE_TYPES, true)) {
                $warnings[] = sprintf('pages[%s] has unknown type "%s".', $pageId, $page['type']);
            }

            if (isset($page['type']) && in_array($page['type'], self::GENERATABLE_PAGE_TYPES, true)) {
                $promptTemplate = trim((string) ($page['prompt_template'] ?? ''));
                if ('' === $promptTemplate) {
                    $errors[] = sprintf('pages[%s] must have a "prompt_template" (type: %s).', $pageId, $page['type']);
                }
            }

            if (in_array($page['type'] ?? '', self::GENERATABLE_PAGE_TYPES, true)) {
                $assets[] = (string) ($page['default_image_path'] ?? '');
            }
        }

        // NL gender validation
        if ('nl' === $shortLocale) {
            $errors = [...$errors, ...$this->validateNlGenderNeutral($blueprint)];
        }

        // French copy-paste check
        if (in_array($shortLocale, ['nl', 'en'], true)) {
            $errors = [...$errors, ...$this->validateNoFrenchContentInLocale($blueprint, $shortLocale)];
        }

        // Placeholder validation
        $disallowed = $this->findDisallowedPlaceholders($blueprint);
        if ([] !== $disallowed) {
            $errors[] = sprintf('Found disallowed placeholders in %s runtime: %s. Allowed: %s.', strtoupper($shortLocale), implode(', ', $disallowed), implode(', ', self::ALLOWED_PLACEHOLDERS));
        }

        return new BlueprintValidationResult($errors, $warnings, count($pages), $locales, $assets);
    }

    /** @param array<string, mixed> $blueprint @return list<string> */
    private function validateNlGenderNeutral(array $blueprint): array
    {
        $errors = [];
        $pages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];

        $forbiddenPatterns = [
            ['pattern' => '/\bhij\b/iu', 'word' => 'hij', 'explanation' => 'Use {child_name} or {child_pronoun_subject} instead.'],
            ['pattern' => '/\bhem\b/iu', 'word' => 'hem', 'explanation' => 'Use {child_name} or restructure the sentence.'],
            ['pattern' => '/\bZijn\b(?!\s*(?:de|het|een|zijn|haar|mijn|jouw|onze|hun))/u', 'word' => 'Zijn (possessive)', 'explanation' => 'Use {child_possessive_det} instead of Zijn for the child hero.'],
        ];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = (string) ($page['id'] ?? '(unknown)');
            $fieldsToCheck = [
                'text_template'  => (string) ($page['text_template'] ?? ''),
                'title_template' => (string) ($page['title_template'] ?? ''),
            ];

            foreach ($fieldsToCheck as $fieldName => $text) {
                if ('' === $text) {
                    continue;
                }

                foreach ($forbiddenPatterns as ['pattern' => $pattern, 'word' => $word, 'explanation' => $explanation]) {
                    if (preg_match($pattern, $text) === 1) {
                        $errors[] = sprintf(
                            'NL gender violation in pages[%s].%s: found forbidden gendered pronoun "%s". Fix: %s. Offending text: "%s"',
                            $pageId,
                            $fieldName,
                            $word,
                            $explanation,
                            $text,
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Heuristic: detect French-language words in NL or EN runtime text fields.
     * French-only words (avec, comme, pour, dans, mais, leur) must not appear in NL/EN translations.
     * Their presence almost certainly indicates copy-paste from the FR locale.
     *
     * @param array<string, mixed> $blueprint @return list<string>
     */
    private function validateNoFrenchContentInLocale(array $blueprint, string $locale): array
    {
        $errors = [];
        $pages = is_array($blueprint['pages'] ?? null) ? $blueprint['pages'] : [];

        // French-only words that cannot appear in valid Dutch or English
        $frenchOnlyPatterns = [
            '/\bavec\b/iu'  => 'avec',
            '/\bcomme\b/iu' => 'comme',
            '/\bpour\b/iu'  => 'pour',
            '/\bdans\b/iu'  => 'dans',
            '/\bmais\b/iu'  => 'mais',
            '/\bleur\b/iu'  => 'leur',
        ];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageId = (string) ($page['id'] ?? '(unknown)');
            $fieldsToCheck = [
                'text_template'  => (string) ($page['text_template'] ?? ''),
                'title_template' => (string) ($page['title_template'] ?? ''),
            ];

            foreach ($fieldsToCheck as $fieldName => $text) {
                if ('' === $text) {
                    continue;
                }

                foreach ($frenchOnlyPatterns as $pattern => $word) {
                    if (preg_match($pattern, $text) === 1) {
                        $errors[] = sprintf(
                            'Possible French copy-paste in %s runtime pages[%s].%s: found French word "%s". Verify this is valid %s text, not copy-pasted from the FR locale.',
                            strtoupper($locale),
                            $pageId,
                            $fieldName,
                            $word,
                            strtoupper($locale),
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function findDisallowedPlaceholders(array $payload): array
    {
        $placeholders = [];
        $this->collectPlaceholders($payload, $placeholders);
        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return array_values(array_filter(
            $placeholders,
            static fn (string $placeholder): bool => !in_array($placeholder, self::ALLOWED_PLACEHOLDERS, true),
        ));
    }

    /** @param mixed $value @param list<string> $placeholders */
    private function collectPlaceholders(mixed $value, array &$placeholders): void
    {
        if (is_string($value)) {
            if (preg_match_all('/\{[^}]+\}/', $value, $matches) === 1 || (isset($matches[0]) && [] !== $matches[0])) {
                foreach ($matches[0] as $placeholder) {
                    $placeholders[] = $placeholder;
                }
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectPlaceholders($item, $placeholders);
        }
    }

    /** @param array<string, mixed> $imageGeneration @return list<string> */
    private function validateImageGenerationModel(array $imageGeneration): array
    {
        $errors = [];
        $modelStrategy = $imageGeneration['modelStrategy'] ?? null;

        if (is_array($modelStrategy)) {
            $model = trim((string) ($modelStrategy['model'] ?? ''));
            if ('' === $model) {
                $errors[] = sprintf('The "imageGeneration.modelStrategy.model" field is required and must equal "%s".', self::REQUIRED_IMAGE_MODEL);
            } elseif ($model !== self::REQUIRED_IMAGE_MODEL) {
                $errors[] = sprintf('The "imageGeneration.modelStrategy.model" field must equal "%s".', self::REQUIRED_IMAGE_MODEL);
            }

            return $errors;
        }

        $legacyStrategy = trim((string) $modelStrategy);
        if ('' === $legacyStrategy) {
            $errors[] = sprintf('The "imageGeneration.modelStrategy.model" field is required and must equal "%s".', self::REQUIRED_IMAGE_MODEL);
        } elseif ($legacyStrategy !== self::LEGACY_ALLOWED_MODEL_STRATEGY) {
            $errors[] = sprintf('The "imageGeneration.modelStrategy.model" field must equal "%s".', self::REQUIRED_IMAGE_MODEL);
        }

        return $errors;
    }

    /** @param array<string, mixed> $blueprint @return list<string> */
    private function validateQaStructure(array $blueprint): array
    {
        $qa = is_array($blueprint['qa'] ?? null) ? $blueprint['qa'] : [];
        if ([] === $qa) {
            return [];
        }

        $errors = [];
        foreach ([
            'qa.requiredPageTypes' => $qa['requiredPageTypes'] ?? null,
            'qa.requiredLocales' => $qa['requiredLocales'] ?? null,
            'qa.rules' => $qa['rules'] ?? null,
        ] as $path => $value) {
            if (!is_array($value)) {
                $errors[] = sprintf('The "%s" field must be an array of strings.', $path);
                continue;
            }

            $normalized = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && '' !== trim($item)));
            if ([] === $normalized) {
                $errors[] = sprintf('The "%s" field must not be empty.', $path);
            }
        }

        $placeholderPolicy = $qa['placeholderPolicy'] ?? null;
        if (!is_array($placeholderPolicy)) {
            $errors[] = 'The "qa.placeholderPolicy" object is required when qa is present.';
        } elseif (!is_array($placeholderPolicy['allowed'] ?? null)) {
            $errors[] = 'The "qa.placeholderPolicy.allowed" field must be an array of strings.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $blueprint @return list<string> */
    private function validateQaScorecard(array $blueprint): array
    {
        $qa = is_array($blueprint['qa'] ?? null) ? $blueprint['qa'] : [];
        $scorecard = $qa['scorecard'] ?? null;
        if (!is_array($scorecard)) {
            return [];
        }

        $errors = [];
        foreach (['editorialScore', 'imageabilityScore', 'heroConsistencyScore', 'localeCompletenessScore'] as $scoreKey) {
            $scoreNode = $scorecard[$scoreKey] ?? null;
            if (!is_array($scoreNode)) {
                $errors[] = sprintf('The "qa.scorecard.%s" object is required when qa.scorecard is present.', $scoreKey);
                continue;
            }

            $rationale = trim((string) ($scoreNode['rationale'] ?? ''));
            if ('' === $rationale) {
                $errors[] = sprintf('The "qa.scorecard.%s.rationale" field is required.', $scoreKey);
            }

            $value = $scoreNode['value'] ?? null;
            if ((!is_int($value) && !is_float($value) && !ctype_digit((string) $value)) || (float) $value < 0 || (float) $value > 100) {
                $errors[] = sprintf('The "qa.scorecard.%s.value" field must be a number between 0 and 100.', $scoreKey);
            }
        }

        return $errors;
    }
}
