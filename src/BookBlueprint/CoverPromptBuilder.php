<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class CoverPromptBuilder
{
    /**
     * @param array<string, mixed> $masterBlueprint
     * @param array<string, mixed> $coverScene
     * @param bool $photoProvided
     * @param bool $heroReferenceProvided hero-reference.png is available as input_image
     * @return array{prompt:string,negativePrompt:string,debug:array<string,mixed>}
     */
    public function build(array $masterBlueprint, array $coverScene, bool $photoProvided = false, bool $heroReferenceProvided = false): array
    {
        $visualBible = is_array($masterBlueprint['visualBible'] ?? null) ? $masterBlueprint['visualBible'] : [];
        $heroBible = is_array($masterBlueprint['heroBible'] ?? null) ? $masterBlueprint['heroBible'] : [];
        $imageGeneration = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        $styleRules = $this->normalizeStringList($visualBible['style_rules'] ?? null);
        $identityRules = $this->normalizeStringList($heroBible['identityRules'] ?? null);
        $mustShow = $this->normalizeStringList($coverScene['must_show'] ?? null);
        $mustNotShow = $this->normalizeStringList($coverScene['must_not_show'] ?? null);
        $palette = $this->normalizeStringList($visualBible['palette'] ?? null);
        $compositionRules = $this->normalizeStringList($visualBible['compositionRules'] ?? null);
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        $storyPromise = trim((string) ($metadata['promise'] ?? ''));

        if ($photoProvided) {
            $heroDescriptor = 'match the hero exactly to the provided child photo: same face, same hairstyle, same eye color, same skin tone, same perceived age';
        } elseif ($heroReferenceProvided) {
            $heroDescriptor = 'match the hero from the reference image exactly: same face shape, same features, same hairstyle, same outfit, same perceived age — zero drift allowed';
        } else {
            $heroDescriptor = implode(', ', $identityRules);
        }

        $sections = array_filter([
            'Premium children\'s book cover. No text anywhere — text will be overlaid in design.',
            '' !== $storyPromise ? sprintf('Story: %s', $storyPromise) : null,
            [] !== $styleRules ? sprintf('Style: %s', implode(', ', $styleRules)) : null,
            [] !== $palette ? sprintf('Colors: %s', implode(', ', $palette)) : null,
            sprintf('Hero: %s', $heroDescriptor),
            '' !== $coverScene['camera'] ? sprintf('Shot: %s', $coverScene['camera']) : null,
            '' !== $coverScene['emotion'] ? sprintf('Mood: %s', $coverScene['emotion']) : null,
            '' !== $coverScene['lighting'] ? sprintf('Light: %s', $coverScene['lighting']) : null,
            '' !== $coverScene['composition'] ? sprintf('Composition: %s', $coverScene['composition']) : null,
            [] !== $compositionRules ? sprintf('Framing: %s', implode(', ', $compositionRules)) : null,
            'Upper third must be kept clear (no hero face, no elements) — title will be placed here during book design.',
            [] !== $mustShow ? sprintf('Include: %s', implode(', ', $mustShow)) : null,
        ]);

        $extraNegative = [];
        if ($photoProvided || $heroReferenceProvided) {
            $extraNegative = ['inconsistent child likeness', 'inconsistent face', 'different hairstyle'];
        }

        return [
            'prompt' => implode("\n", $sections),
            'negativePrompt' => implode(', ', $this->buildNegativePrompt(
                trim((string) ($imageGeneration['negativePromptDefault'] ?? '')),
                trim((string) ($coverScene['negativePrompt'] ?? '')),
                $extraNegative,
            )),
            'debug' => [
                'photoProvided' => $photoProvided,
                'heroReferenceProvided' => $heroReferenceProvided,
                'heroDescriptor' => $heroDescriptor,
            ],
        ];
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $values)));
    }

    /** @param list<string> $extraNegative @return list<string> */
    private function buildNegativePrompt(string $defaultNegativePrompt, string $sceneNegativePrompt, array $extraNegative): array
    {
        $items = array_merge(
            NegativePrompt::BASE,
            ['text', 'letters', 'words', 'title', 'readable characters', 'watermark', 'logo'],
        );
        foreach ([$defaultNegativePrompt, $sceneNegativePrompt] as $block) {
            foreach (explode(',', $block) as $item) {
                $t = strtolower(trim($item));
                if ('' !== $t) $items[] = $t;
            }
        }
        $items = array_merge($items, $extraNegative);
        return array_values(array_unique($items));
    }
}
