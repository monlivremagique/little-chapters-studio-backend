<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds all required multilingual catalog attributes for BOOK_FOREST_OF_LOST_STARS (b6).
 * The book exists in FrontCatalogMetadata and Sylius but lacked locale-specific attribute values,
 * causing app:diagnose-catalog-locales to report 5/6 for every attribute code.
 *
 * Covers: book_subtitle, book_description, book_long_description, book_emotional_promise,
 * book_features, book_reviews_json — in fr_FR, en_US, nl_NL.
 *
 * Secondary attributes (badge, cover_type, format, language, personalization_level, theme)
 * are handled by BackfillCatalogLocalesCommand::TRANSLATED_ATTRIBUTE_VALUES.
 *
 * All statements are idempotent (WHERE NOT EXISTS).
 */
final class Version20260512000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FR/EN/NL content attributes for BOOK_FOREST_OF_LOST_STARS to fix catalog locale diagnostics (5/6 → 6/6).';
    }

    private static function prd(): string
    {
        return "(SELECT id FROM sylius_product WHERE code='BOOK_FOREST_OF_LOST_STARS')";
    }

    private static function attr(string $code): string
    {
        return "(SELECT id FROM sylius_product_attribute WHERE code='{$code}')";
    }

    private function insertAttrValue(string $attributeCode, string $locale, string $value): void
    {
        $prd = self::prd();
        $attr = self::attr($attributeCode);
        $safe = str_replace("'", "''", $value);

        $this->addSql(<<<SQL
            INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
            SELECT nextval('sylius_product_attribute_value_id_seq'), {$prd}, {$attr}, '{$locale}', '{$safe}'
            WHERE {$prd} IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM sylius_product_attribute_value
                WHERE product_id = {$prd}
                  AND attribute_id = {$attr}
                  AND locale_code = '{$locale}'
              )
            SQL);
    }

    public function up(Schema $schema): void
    {
        // ─── book_subtitle ────────────────────────────────────────────────────
        $this->insertAttrValue('book_subtitle', 'fr_FR', 'Une quête douce sous les étoiles');
        $this->insertAttrValue('book_subtitle', 'en_US', 'A gentle quest under the stars');
        $this->insertAttrValue('book_subtitle', 'nl_NL', 'Een zachte zoektocht onder de sterren');

        // ─── book_description ─────────────────────────────────────────────────
        $this->insertAttrValue('book_description', 'fr_FR', 'La Forêt des Étoiles Perdues raconte une quête douce où un enfant aide les étoiles tombées à retrouver leur ciel.');
        $this->insertAttrValue('book_description', 'en_US', 'The Forest of Lost Stars tells a gentle quest where a child helps fallen stars find their way back to the sky.');
        $this->insertAttrValue('book_description', 'nl_NL', 'Het Woud van de Verloren Sterren vertelt een zachte queeste waarbij een kind gevallen sterren helpt hun weg terug aan de hemel te vinden.');

        // ─── book_long_description ────────────────────────────────────────────
        $this->insertAttrValue('book_long_description', 'fr_FR', 'Ce livre premium mêle magie calme, courage tendre et illustrations aquarelles pour une histoire du soir unique et personnalisée.');
        $this->insertAttrValue('book_long_description', 'en_US', 'This premium book blends calm magic, gentle courage and watercolour illustrations for a unique, personalized bedtime story.');
        $this->insertAttrValue('book_long_description', 'nl_NL', 'Dit premium boek combineert rustige magie, zacht moed en aquarelillustraties voor een uniek, gepersonaliseerd bedtijdverhaal.');

        // ─── book_emotional_promise ───────────────────────────────────────────
        $this->insertAttrValue('book_emotional_promise', 'fr_FR', 'Une couverture wow, des pages cohérentes et une histoire assez tendre pour donner envie de relire.');
        $this->insertAttrValue('book_emotional_promise', 'en_US', 'A stunning cover, coherent pages and a tender story that makes you want to read it again and again.');
        $this->insertAttrValue('book_emotional_promise', 'nl_NL', "Een prachtige cover, samenhangende pagina's en een teder verhaal dat je keer op keer wilt lezen.");

        // ─── book_features (JSON array) ───────────────────────────────────────
        $this->insertAttrValue('book_features', 'fr_FR', '["10 pages personnalisées","Couverture rigide premium","Blueprint FR\/NL\/EN","Compatible BookFlip","Fabriqué en Europe"]');
        $this->insertAttrValue('book_features', 'en_US', '["10 personalized pages","Premium hardcover","Blueprint FR\/NL\/EN","BookFlip compatible","Made in Europe"]');
        $this->insertAttrValue('book_features', 'nl_NL', '["10 gepersonaliseerde paginas","Premium harde kaft","Blueprint FR/NL/EN","BookFlip compatibel","Gemaakt in Europa"]');

        // ─── book_reviews_json (empty — book is new) ──────────────────────────
        $this->insertAttrValue('book_reviews_json', 'fr_FR', '[]');
        $this->insertAttrValue('book_reviews_json', 'en_US', '[]');
        $this->insertAttrValue('book_reviews_json', 'nl_NL', '[]');
    }

    public function down(Schema $schema): void
    {
        $prd = self::prd();

        foreach (['book_subtitle', 'book_description', 'book_long_description', 'book_emotional_promise', 'book_features', 'book_reviews_json'] as $code) {
            $attr = self::attr($code);
            $this->addSql(<<<SQL
                DELETE FROM sylius_product_attribute_value
                WHERE product_id = {$prd}
                  AND attribute_id = {$attr}
                  AND locale_code IN ('fr_FR', 'en_US', 'nl_NL')
                SQL);
        }
    }
}
