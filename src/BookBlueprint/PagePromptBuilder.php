<?php

declare(strict_types=1);

namespace App\BookBlueprint;

final class PagePromptBuilder
{
    /**
     * @param array<string, mixed> $masterBlueprint
     * @param array<string, mixed> $scene
     * @return array{prompt:string,negativePrompt:string,debug:array<string,mixed>}
     */
    public function build(array $masterBlueprint, array $scene, bool $photoProvided = false, bool $heroReferenceProvided = false): array
    {
        $visualBible = is_array($masterBlueprint['visualBible'] ?? null) ? $masterBlueprint['visualBible'] : [];
        $heroBible = is_array($masterBlueprint['heroBible'] ?? null) ? $masterBlueprint['heroBible'] : [];
        $imageGeneration = is_array($masterBlueprint['imageGeneration'] ?? null) ? $masterBlueprint['imageGeneration'] : [];
        $styleRules = $this->normalizeStringList($visualBible['style_rules'] ?? null);
        $identityRules = $this->normalizeStringList($heroBible['identityRules'] ?? null);
        $mustShow = $this->normalizeStringList($scene['must_show'] ?? null);
        $mustNotShow = $this->normalizeStringList($scene['must_not_show'] ?? null);
        $palette = $this->normalizeStringList($visualBible['palette'] ?? null);
        $metadata = is_array($masterBlueprint['metadata'] ?? null) ? $masterBlueprint['metadata'] : [];
        $storyPromise = trim((string) ($metadata['promise'] ?? ''));
        $sceneType = (string) ($scene['type'] ?? 'story');
        $sceneId = (string) ($scene['id'] ?? '');
        $isNoHeroPage = in_array($sceneType, ['dedication', 'summary'], true);

        if ($isNoHeroPage) {
            $heroSection = 'No hero character in this image. Show only the environment, atmosphere, and setting.';
            $referenceSection = 'No hero reference. Focus on consistent color palette and painting style with the rest of the book.';
        } elseif ($photoProvided) {
            $heroSection = 'Match the hero exactly to the provided child photo: same face, same features, same age. Zero drift allowed.';
            $referenceSection = 'The hero reference image is your PRIMARY guide for hero appearance. The cover image guides palette and style. Ignore the page composition guide for hero details — focus on hero consistency.';
        } elseif ($heroReferenceProvided) {
            $heroSection = sprintf('Match the hero EXACTLY from the reference image: same face shape, same features, same hairstyle, same outfit, same age. Zero drift.%s', [] !== $identityRules ? ' Rules: '.implode(', ', $identityRules) : '');
            $referenceSection = 'The hero reference image is your PRIMARY guide for hero appearance. The cover image guides palette and style. Ignore the page composition guide for hero details — focus on hero consistency.';
        } else {
            $heroSection = '' !== $storyPromise ? sprintf('Hero: %s', $storyPromise) : 'Premium child hero';
            $referenceSection = 'Focus on consistent style with the established visual direction.';
        }

        $sections = array_filter([
            sprintf('Interior page %d for a premium children\'s book. No text anywhere — text is external.', (int) ($scene['pageNumber'] ?? 0)),
            '' !== $storyPromise ? sprintf('Story: %s', $storyPromise) : null,
            [] !== $styleRules ? sprintf('Style: %s', implode(', ', $styleRules)) : null,
            [] !== $palette ? sprintf('Palette: %s', implode(', ', $palette)) : null,
            $heroSection,
            $referenceSection,
            '' !== $scene['camera'] ? sprintf('Shot: %s', $scene['camera']) : null,
            '' !== $scene['emotion'] ? sprintf('Mood: %s', $scene['emotion']) : null,
            '' !== $scene['lighting'] ? sprintf('Light: %s', $scene['lighting']) : null,
            '' !== $scene['composition'] ? sprintf('Composition: %s', $scene['composition']) : null,
            '' !== $scene['foreground'] ? sprintf('Front: %s', $scene['foreground']) : null,
            '' !== $scene['midground'] ? sprintf('Middle: %s', $scene['midground']) : null,
            '' !== $scene['background'] ? sprintf('Back: %s', $scene['background']) : null,
            [] !== $mustShow ? sprintf('Include: %s', implode(', ', $mustShow)) : null,
        ]);

        $extraNegative = [];
        if ($photoProvided || $heroReferenceProvided) {
            $extraNegative = ['inconsistent child likeness', 'inconsistent face', 'different hairstyle'];
        }
        if ($isNoHeroPage) {
            $extraNegative[] = 'child hero';
            $extraNegative[] = 'child face';
            $extraNegative[] = 'any human face';
        }

        return [
            'prompt' => implode("\n", $sections),
            'negativePrompt' => implode(', ', $this->buildNegativePrompt(
                trim((string) ($imageGeneration['negativePromptDefault'] ?? '')),
                trim((string) ($scene['negativePrompt'] ?? '')),
                $extraNegative,
            )),
            'debug' => [
                'sceneType' => $sceneType,
                'isNoHeroPage' => $isNoHeroPage,
                'heroReferenceProvided' => $heroReferenceProvided,
                'photoProvided' => $photoProvided,
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
