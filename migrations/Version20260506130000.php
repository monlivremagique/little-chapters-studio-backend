<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixes Sylius admin "product_attribute_name not found in DataBag" error.
 *
 * Root causes:
 * 1. Migration-created attributes only have fr_FR translations — en_US missing.
 * 2. Names stored as raw codes ("book_description") instead of human labels.
 *
 * Fix:
 * - UPDATE fr_FR names to human-readable labels.
 * - INSERT missing en_US translations for all 6 affected attributes.
 *
 * Idempotent: UPDATE is always safe; INSERT uses WHERE NOT EXISTS.
 */
final class Version20260506130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix Sylius admin attribute name DataBag error: add en_US translations and human-readable labels for migration-created attributes.';
    }

    private static function attrId(string $code): string
    {
        return "(SELECT id FROM sylius_product_attribute WHERE code='{$code}')";
    }

    public function up(Schema $schema): void
    {
        // Labels: [code => [fr_FR label, en_US label]]
        $labels = [
            'book_description'      => ['Description du livre',      'Book description'],
            'book_long_description' => ['Description longue',         'Long description'],
            'book_emotional_promise'=> ['Promesse émotionnelle',      'Emotional promise'],
            'book_features'         => ['Caractéristiques (JSON)',    'Features (JSON)'],
            'book_print_quality'    => ['Qualité d\'impression',      'Print quality'],
            'book_reviews_json'     => ['Avis clients (JSON)',        'Customer reviews (JSON)'],
        ];

        foreach ($labels as $code => [$fr, $en]) {
            $attrId = self::attrId($code);
            $frEscaped = str_replace("'", "''", $fr);
            $enEscaped = str_replace("'", "''", $en);

            // Fix fr_FR name (was the raw code string)
            $this->addSql(<<<SQL
                UPDATE sylius_product_attribute_translation
                SET name = '$frEscaped'
                WHERE translatable_id = $attrId AND locale = 'fr_FR'
                SQL);

            // Add en_US translation if missing
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_translation (id, translatable_id, name, locale)
                SELECT nextval('sylius_product_attribute_translation_id_seq'), $attrId, '$enEscaped', 'en_US'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_translation
                    WHERE translatable_id = $attrId AND locale = 'en_US'
                )
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $codes = [
            'book_description',
            'book_long_description',
            'book_emotional_promise',
            'book_features',
            'book_print_quality',
            'book_reviews_json',
        ];

        foreach ($codes as $code) {
            $attrId = self::attrId($code);
            // Revert fr_FR to raw code name
            $this->addSql("UPDATE sylius_product_attribute_translation SET name='$code' WHERE translatable_id=$attrId AND locale='fr_FR'");
            // Remove en_US
            $this->addSql("DELETE FROM sylius_product_attribute_translation WHERE translatable_id=$attrId AND locale='en_US'");
        }
    }
}
