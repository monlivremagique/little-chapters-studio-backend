<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class BlueprintProjector
{
    /**
     * @param array<string, mixed> $masterBlueprint
     * @return array<string, mixed>
     */
    public function projectRuntimeBlueprint(array $masterBlueprint, string $locale): array
    {
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        $locales = is_array($masterBlueprint['locales'] ?? null) ? $masterBlueprint['locales'] : [];
        $localeNode = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
        $bookNode = is_array($localeNode['book'] ?? null) ? $localeNode['book'] : [];
        $localizedPages = is_array($localeNode['pages'] ?? null) ? $localeNode['pages'] : [];
        $sceneDefinitions = is_array($masterBlueprint['sceneDefinitions'] ?? null) ? $masterBlueprint['sceneDefinitions'] : [];
        $assets = is_array($masterBlueprint['assets'] ?? null) ? $masterBlueprint['assets'] : [];
        $assetDefaults = is_array($assets['defaults'] ?? null) ? $assets['defaults'] : [];
        $visualBible = is_array($masterBlueprint['visualBible'] ?? null) ? $masterBlueprint['visualBible'] : [];
        $heroBible = is_array($masterBlueprint['heroBible'] ?? null) ? $masterBlueprint['heroBible'] : [];
        $imageGeneration = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        $qa = is_array($masterBlueprint['qa'] ?? null) ? $masterBlueprint['qa'] : [];

        usort($sceneDefinitions, static function (mixed $left, mixed $right): int {
            $leftPageNumber = (int) ((is_array($left) ? $left['pageNumber'] : 0) ?? 0);
            $rightPageNumber = (int) ((is_array($right) ? $right['pageNumber'] : 0) ?? 0);

            if ($leftPageNumber !== $rightPageNumber) {
                return $leftPageNumber <=> $rightPageNumber;
            }

            $leftId = trim((string) ((is_array($left) ? $left['id'] : '') ?? ''));
            $rightId = trim((string) ((is_array($right) ? $right['id'] : '') ?? ''));

            return $leftId <=> $rightId;
        });

        $pages = [];

        foreach ($sceneDefinitions as $scene) {
            if (!is_array($scene)) {
                continue;
            }

            // Skip reference scenes (hero portraits, etc.) — they are not book pages
            if ('reference' === ($scene['type'] ?? '')) {
                continue;
            }

            $sceneId = trim((string) ($scene['id'] ?? ''));
            $pageLocaleNode = is_array($localizedPages[$sceneId] ?? null) ? $localizedPages[$sceneId] : [];
            $assetKey = trim((string) ($scene['assetKey'] ?? ''));

            $pages[] = [
                'id' => $sceneId,
                'type' => (string) ($scene['type'] ?? ''),
                'title_template' => $this->nullableString($pageLocaleNode['title_template'] ?? null),
                'text_template' => $this->nullableString($pageLocaleNode['text_template'] ?? null),
                'default_image_path' => isset($assetDefaults[$assetKey]) ? (string) $assetDefaults[$assetKey] : '',
                'prompt_template' => $this->nullableString($scene['promptTemplate'] ?? null),
                'negative_prompt' => $this->nullableString($scene['negativePrompt'] ?? null),
                'personalizable' => (bool) ($scene['personalizable'] ?? false),
                'aspect_ratio' => $this->nullableString($scene['aspectRatio'] ?? null),
                'page_number' => (int) ($scene['pageNumber'] ?? 0),
                'scene_key' => $sceneId,
            ];
        }

        return [
            'version' => 2,
            'title_template' => (string) ($bookNode['title_template'] ?? ''),
            'negative_prompt_default' => (string) ($imageGeneration['negativePromptDefault'] ?? ''),
            'style_rules' => is_array($visualBible['style_rules'] ?? null) ? array_values($visualBible['style_rules']) : [],
            'metadata' => [
                'schema' => 'book_blueprint_v2',
                'schemaVersion' => 2,
                'slug' => (string) ($metadata['slug'] ?? ''),
                'bookId' => (string) ($metadata['bookId'] ?? ''),
                'locale' => $locale,
            ],
            'visualBible' => $visualBible,
            'heroBible' => $heroBible,
            'imageGeneration' => $imageGeneration,
            'assets' => $assets,
            'qa' => $qa,
            'pages' => $pages,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $string = trim((string) $value);

        return '' !== $string ? $string : null;
    }
}
