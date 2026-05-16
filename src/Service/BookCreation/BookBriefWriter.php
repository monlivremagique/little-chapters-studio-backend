<?php

declare(strict_types=1);

namespace App\Service\BookCreation;

use Symfony\Component\Yaml\Yaml;

final class BookBriefWriter
{
    /** @param array<string, mixed> $data */
    public function write(string $path, array $data): string
    {
        $brief = [
            'slug' => $data['slug'],
            'title' => $data['title'],
            'theme' => $data['theme'] ?? [],
            'age' => $data['age'],
            'story_subject' => $data['story_subject'],
            'main_emotion' => $data['main_emotion'],
            'learning_message' => $data['learning_message'],
            'languages' => $data['languages'] ?? ['fr', 'en', 'nl'],
            'visual_style' => $data['visual_style'],
            'story_page_count' => max(1, (int) ($data['story_page_count'] ?? 6)),
            'constraints' => $data['constraints'] ?? [],
            'arc_type' => $data['arc_type'] ?? '',
            'climax_page' => $data['climax_page'] ?? '',
            'setting' => $data['setting'] ?? '',
            'cultural_context' => $data['cultural_context'] ?? '',
            'parent_emotion_goal' => $data['parent_emotion_goal'] ?? '',
            'secondary_characters' => $data['secondary_characters'] ?? [],
        ];

        // Build scenes from individual page descriptions
        $scenes = [];
        $pageCount = max(1, (int) ($data['story_page_count'] ?? 6));
        for ($i = 1; $i <= $pageCount; ++$i) {
            $moment = trim((string) ($data[sprintf('scene_%d_moment', $i)] ?? ''));
            if ('' !== $moment) {
                $scenes[] = ['id' => sprintf('page_%d', $i), 'moment' => $moment];
            }
        }
        if ([] !== $scenes) {
            $brief['scenes'] = $scenes;
        }

        $yaml = Yaml::dump($brief, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($path, $yaml);

        return $path;
    }
}
