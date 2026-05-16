<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds all required multilingual catalog attributes for BOOK_ESPACE_ROBOT (b8).
 *
 * Covers: book_subtitle, book_description, book_long_description, book_emotional_promise,
 * book_features, book_reviews_json — in fr_FR, en_US, nl_NL.
 *
 * Secondary attributes (badge, cover_type, format, language, personalization_level, theme)
 * are handled by BackfillCatalogLocalesCommand::TRANSLATED_ATTRIBUTE_VALUES.
 *
 * All statements are idempotent (WHERE NOT EXISTS).
 */
final class Version20260512200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FR/EN/NL content attributes for BOOK_ESPACE_ROBOT to fix catalog locale diagnostics.';
    }

    private static function prd(): string
    {
        return "(SELECT id FROM sylius_product WHERE code='BOOK_ESPACE_ROBOT')";
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
        $this->insertAttrValue('book_subtitle', 'fr_FR', 'Une mission dans les étoiles');
        $this->insertAttrValue('book_subtitle', 'en_US', 'A mission among the stars');
        $this->insertAttrValue('book_subtitle', 'nl_NL', 'Een missie tussen de sterren');

        // ─── book_description ─────────────────────────────────────────────────
        $this->insertAttrValue('book_description', 'fr_FR', 'L\'Astronaute et Son Robot emmène les grands enfants dans une mission spatiale où coopération rime avec découverte.');
        $this->insertAttrValue('book_description', 'en_US', 'The Astronaut and Their Robot takes older children on a space mission where cooperation leads to discovery.');
        $this->insertAttrValue('book_description', 'nl_NL', 'De Astronaut en Zijn Robot brengt oudere kinderen mee op een ruimtemissie waar samenwerking leidt tot ontdekking.');

        // ─── book_long_description ────────────────────────────────────────────
        $this->insertAttrValue('book_long_description', 'fr_FR', 'Un enfant-astronaute et BLIX son robot partent réparer une balise perdue sur un astéroïde glacé. Entre hologrammes, cristaux et high-five dans l\'espace, ils apprennent que les grandes découvertes arrivent quand on combine ce qu\'on sait chacun. Pour les 8-10 ans curieux du cosmos.');
        $this->insertAttrValue('book_long_description', 'en_US', 'A child-astronaut and BLIX their robot set off to repair a lost beacon on an icy asteroid. Between holograms, crystals and high-fives in space, they learn that great discoveries happen when you combine what each of you knows. For curious 8–10 year olds.');
        $this->insertAttrValue('book_long_description', 'nl_NL', 'Een kind-astronaut en BLIX zijn robot vertrekken om een verloren baken te repareren op een ijzig asteroïde. Tussen hologrammen, kristallen en high-fives in de ruimte leren ze dat grote ontdekkingen plaatsvinden wanneer je combineert wat ieder weet. Voor nieuwsgierige 8-10 jarigen.');

        // ─── book_emotional_promise ───────────────────────────────────────────
        $this->insertAttrValue('book_emotional_promise', 'fr_FR', 'Un livre qui donne envie de résoudre les problèmes ensemble plutôt que seul.');
        $this->insertAttrValue('book_emotional_promise', 'en_US', 'A book that makes you want to solve problems together rather than alone.');
        $this->insertAttrValue('book_emotional_promise', 'nl_NL', 'Een boek dat je het gevoel geeft dat je problemen beter samen oplost dan alleen.');

        // ─── book_features (JSON array) ───────────────────────────────────────
        $this->insertAttrValue('book_features', 'fr_FR', '["10 pages personnalisées","Couverture rigide premium","Style sci-fi aquarelle premium","Âge 8-10 ans","Localisation FR\/EN\/NL"]');
        $this->insertAttrValue('book_features', 'en_US', '["10 personalized pages","Premium hardcover","Premium sci-fi watercolour style","Ages 8-10","FR\/EN\/NL localization"]');
        $this->insertAttrValue('book_features', 'nl_NL', '["10 gepersonaliseerde paginas","Premium harde kaft","Premium sci-fi aquarel stijl","Leeftijd 8-10 jaar","FR\/EN\/NL lokalisatie"]');

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
