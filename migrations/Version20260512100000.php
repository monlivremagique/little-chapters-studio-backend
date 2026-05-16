<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds all required multilingual catalog attributes for BOOK_VILLE_ECOLE (b7).
 *
 * Covers: book_subtitle, book_description, book_long_description, book_emotional_promise,
 * book_features, book_reviews_json — in fr_FR, en_US, nl_NL.
 *
 * Secondary attributes (badge, cover_type, format, language, personalization_level, theme)
 * are handled by BackfillCatalogLocalesCommand::TRANSLATED_ATTRIBUTE_VALUES.
 *
 * All statements are idempotent (WHERE NOT EXISTS).
 */
final class Version20260512100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FR/EN/NL content attributes for BOOK_VILLE_ECOLE to fix catalog locale diagnostics.';
    }

    private static function prd(): string
    {
        return "(SELECT id FROM sylius_product WHERE code='BOOK_VILLE_ECOLE')";
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
        $this->insertAttrValue('book_subtitle', 'fr_FR', 'Mon premier grand jour en ville');
        $this->insertAttrValue('book_subtitle', 'en_US', 'My first big day in the city');
        $this->insertAttrValue('book_subtitle', 'nl_NL', 'Mijn eerste grote dag in de stad');

        // ─── book_description ─────────────────────────────────────────────────
        $this->insertAttrValue('book_description', 'fr_FR', 'Mon Grand Jour en Ville accompagne le tout-petit dans sa première aventure urbaine, pas à pas vers le courage.');
        $this->insertAttrValue('book_description', 'en_US', 'My Big Day in the City guides little ones through their first urban adventure, step by step towards courage.');
        $this->insertAttrValue('book_description', 'nl_NL', 'Mijn Grote Dag in de Stad begeleidt de kleintjes bij hun eerste stedelijke avontuur, stap voor stap naar moed.');

        // ─── book_long_description ────────────────────────────────────────────
        $this->insertAttrValue('book_long_description', 'fr_FR', 'Un matin ensoleillé en ville belge : trams, marché, boulangerie et parc. À chaque étape, l\'enfant découvre qu\'il est plus courageux qu\'il ne le pensait. Pour les 3-5 ans qui font leurs premiers grands pas.');
        $this->insertAttrValue('book_long_description', 'en_US', 'A sunny morning in a Belgian city: trams, market, bakery and park. At each step, the child discovers they are braver than they thought. For 3–5 year olds taking their first big steps.');
        $this->insertAttrValue('book_long_description', 'nl_NL', 'Een zonnige ochtend in een Belgische stad: trams, markt, bakkerij en park. Bij elke stap ontdekt het kind dat het moediger is dan het dacht. Voor 3-5 jarigen die hun eerste grote stappen zetten.');

        // ─── book_emotional_promise ───────────────────────────────────────────
        $this->insertAttrValue('book_emotional_promise', 'fr_FR', 'Le livre qui transforme chaque sortie en victoire personnelle.');
        $this->insertAttrValue('book_emotional_promise', 'en_US', 'The book that turns every outing into a personal victory.');
        $this->insertAttrValue('book_emotional_promise', 'nl_NL', 'Het boek dat elk uitstapje omzet in een persoonlijke overwinning.');

        // ─── book_features (JSON array) ───────────────────────────────────────
        $this->insertAttrValue('book_features', 'fr_FR', '["10 pages personnalisées","Couverture rigide premium","Style gouache urban premium","Âge 3-5 ans","Localisation FR\/EN\/NL"]');
        $this->insertAttrValue('book_features', 'en_US', '["10 personalized pages","Premium hardcover","Urban gouache premium style","Ages 3-5","FR\/EN\/NL localization"]');
        $this->insertAttrValue('book_features', 'nl_NL', '["10 gepersonaliseerde paginas","Premium harde kaft","Urban gouache premium stijl","Leeftijd 3-5 jaar","FR\/EN\/NL lokalisatie"]');

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
