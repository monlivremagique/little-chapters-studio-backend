<?php

declare(strict_types=1);

namespace App\Personalization;

final class PronounResolver
{
    private const PRONOUNS = [
        'M' => [
            'fr' => ['subject' => 'il', 'possessive' => 'son'],
            'en' => ['subject' => 'he', 'possessive' => 'his'],
            'nl' => ['subject' => 'hij', 'possessive' => 'zijn'],
        ],
        'F' => [
            'fr' => ['subject' => 'elle', 'possessive' => 'sa'],
            'en' => ['subject' => 'she', 'possessive' => 'her'],
            'nl' => ['subject' => 'zij', 'possessive' => 'haar'],
        ],
        'neutral' => [
            'fr' => ['subject' => 'l\'enfant', 'possessive' => 'de l\'enfant'],
            'en' => ['subject' => 'the child', 'possessive' => 'the child\'s'],
            'nl' => ['subject' => 'het kind', 'possessive' => 'van het kind'],
        ],
    ];

    /**
     * Resolve all placeholders in a template string.
     *
     * @param string $template   Text containing {child_name}, {child_pronoun_subject}, {child_possessive_det}
     * @param string $childName  The child's first name
     * @param string $gender     'M', 'F', or 'neutral'
     * @param string $bookLocale 'fr', 'en', or 'nl' — determines which pronoun set to use
     */
    public static function resolve(
        string $template,
        string $childName,
        string $gender = 'neutral',
        string $bookLocale = 'fr',
    ): string {
        $gender = in_array($gender, ['M', 'F'], true) ? $gender : 'neutral';
        $bookLocale = in_array($bookLocale, ['fr', 'en', 'nl'], true) ? $bookLocale : 'fr';
        $pronouns = self::PRONOUNS[$gender][$bookLocale] ?? self::PRONOUNS['neutral'][$bookLocale];

        $text = trim($template);
        $name = '' !== trim($childName) ? trim($childName) : self::PRONOUNS['neutral'][$bookLocale]['subject'];

        $text = str_replace(['{child_pronoun_subject}', '{child_possessive_det}'], [$pronouns['subject'], $pronouns['possessive']], $text);
        $text = str_replace('{child_name}', $name, $text);

        return trim($text);
    }

    /**
     * Resolve placeholders for a text that may contain any locale's placeholders.
     * Used when the locale is not known (e.g., general text).
     */
    public static function resolveSimple(string $template, string $childName, string $gender = 'neutral'): string
    {
        return self::resolve($template, $childName, $gender, 'fr');
    }
}
