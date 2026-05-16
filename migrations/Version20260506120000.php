<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds book_reviews_json translatable attribute with FR/EN/NL review arrays for all 5 books.
 * Reviews are the last visible FR-only content on the product detail page.
 * All statements are idempotent (WHERE NOT EXISTS).
 */
final class Version20260506120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multilingual product reviews: add book_reviews_json attribute with FR/EN/NL review arrays for all 5 books.';
    }

    /** @return array<int, array<string, string>> */
    private function reviewData(): array
    {
        // Raw PHP strings — SQL-escaping applied dynamically in up().
        // Review assignment per book:
        //   21 (aventure)     → r1, r4, r5
        //   22 (voyage)       → r2, r5, r6
        //   23 (foret)        → r3, r6, r4
        //   24 (super-heros)  → r4, r5, r1
        //   25 (douce-nuit)   → r6, r3, r2
        return [
            'BOOK_AVENTURE_ENCHANTEE' => [
                'fr_FR' => '[{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"Mon fils etait tres emu de se voir dans l\'histoire. Le livre est magnifique.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Un cadeau unique et memorable. L\'enfant se reconnait tout de suite.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"La qualite premium est au rendez-vous et mon fils adore etre le heros.","childAge":7,"verified":true}]',
                'en_US' => '[{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"My son was very moved to see himself in the story. The book is simply beautiful.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"A unique and memorable gift. The child recognises themselves straight away.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"The premium quality is there, and my son loves being the hero.","childAge":7,"verified":true}]',
                'nl_NL' => '[{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"Mijn zoon was erg ontroerd toen hij zichzelf in het verhaal zag. Het boek is prachtig.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Een uniek en onvergetelijk cadeau. Het kind herkent zichzelf meteen.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"De premiumkwaliteit is duidelijk aanwezig en mijn zoon is dol op de hoofdrol.","childAge":7,"verified":true}]',
            ],
            'BOOK_VOYAGE_DES_ETOILES' => [
                'fr_FR' => '[{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Offert pour Noel, c\'est le cadeau qui a eu le plus de succes.","childAge":6,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"La qualite premium est au rendez-vous et mon fils adore etre le heros.","childAge":7,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Magnifique cadeau. Les couleurs sont douces et le rendu est tres beau.","childAge":4,"verified":true}]',
                'en_US' => '[{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Given as a Christmas gift, it was by far the most successful present.","childAge":6,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"The premium quality is there, and my son loves being the hero.","childAge":7,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"A magnificent gift. The colours are soft and the result is beautiful.","childAge":4,"verified":true}]',
                'nl_NL' => '[{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Als kerstcadeau gegeven, het was veruit het meest succesvolle cadeau.","childAge":6,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"De premiumkwaliteit is duidelijk aanwezig en mijn zoon is dol op de hoofdrol.","childAge":7,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Een prachtig cadeau. De kleuren zijn zacht en het resultaat is heel mooi.","childAge":4,"verified":true}]',
            ],
            'BOOK_FORET_DES_MERVEILLES' => [
                'fr_FR' => '[{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Tres belle qualite d\'impression. Ma fille veut le lire tous les soirs.","childAge":3,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Magnifique cadeau. Les couleurs sont douces et le rendu est tres beau.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Un cadeau unique et memorable. L\'enfant se reconnait tout de suite.","childAge":5,"verified":true}]',
                'en_US' => '[{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Excellent print quality. My daughter wants to read it every single evening.","childAge":3,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"A magnificent gift. The colours are soft and the result is beautiful.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"A unique and memorable gift. The child recognises themselves straight away.","childAge":5,"verified":true}]',
                'nl_NL' => '[{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Uitstekende afdrukkwaliteit. Mijn dochter wil het elke avond lezen.","childAge":3,"verified":true},{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Een prachtig cadeau. De kleuren zijn zacht en het resultaat is heel mooi.","childAge":4,"verified":true},{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Een uniek en onvergetelijk cadeau. Het kind herkent zichzelf meteen.","childAge":5,"verified":true}]',
            ],
            'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => [
                'fr_FR' => '[{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Un cadeau unique et memorable. L\'enfant se reconnait tout de suite.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"La qualite premium est au rendez-vous et mon fils adore etre le heros.","childAge":7,"verified":true},{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"Mon fils etait tres emu de se voir dans l\'histoire. Le livre est magnifique.","childAge":4,"verified":true}]',
                'en_US' => '[{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"A unique and memorable gift. The child recognises themselves straight away.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"The premium quality is there, and my son loves being the hero.","childAge":7,"verified":true},{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"My son was very moved to see himself in the story. The book is simply beautiful.","childAge":4,"verified":true}]',
                'nl_NL' => '[{"id":"r4","author":"Camille B.","rating":5,"date":"2024-10-22","text":"Een uniek en onvergetelijk cadeau. Het kind herkent zichzelf meteen.","childAge":5,"verified":true},{"id":"r5","author":"Nicolas M.","rating":5,"date":"2024-10-05","text":"De premiumkwaliteit is duidelijk aanwezig en mijn zoon is dol op de hoofdrol.","childAge":7,"verified":true},{"id":"r1","author":"Marie L.","rating":5,"date":"2024-12-15","text":"Mijn zoon was erg ontroerd toen hij zichzelf in het verhaal zag. Het boek is prachtig.","childAge":4,"verified":true}]',
            ],
            'BOOK_DOUCE_NUIT_ETOILEE' => [
                'fr_FR' => '[{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Magnifique cadeau. Les couleurs sont douces et le rendu est tres beau.","childAge":4,"verified":true},{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Tres belle qualite d\'impression. Ma fille veut le lire tous les soirs.","childAge":3,"verified":true},{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Offert pour Noel, c\'est le cadeau qui a eu le plus de succes.","childAge":6,"verified":true}]',
                'en_US' => '[{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"A magnificent gift. The colours are soft and the result is beautiful.","childAge":4,"verified":true},{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Excellent print quality. My daughter wants to read it every single evening.","childAge":3,"verified":true},{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Given as a Christmas gift, it was by far the most successful present.","childAge":6,"verified":true}]',
                'nl_NL' => '[{"id":"r6","author":"Julie P.","rating":4,"date":"2024-09-18","text":"Een prachtig cadeau. De kleuren zijn zacht en het resultaat is heel mooi.","childAge":4,"verified":true},{"id":"r3","author":"Thomas R.","rating":4,"date":"2024-11-10","text":"Uitstekende afdrukkwaliteit. Mijn dochter wil het elke avond lezen.","childAge":3,"verified":true},{"id":"r2","author":"Sophie D.","rating":5,"date":"2024-11-28","text":"Als kerstcadeau gegeven, het was veruit het meest succesvolle cadeau.","childAge":6,"verified":true}]',
            ],
        ];
    }

    public function up(Schema $schema): void
    {
        // ─── 1. Create book_reviews_json attribute ────────────────────────────
        $this->addSql(<<<'SQL'
            INSERT INTO sylius_product_attribute (id, code, type, storage_type, configuration, created_at, updated_at, position, translatable)
            SELECT nextval('sylius_product_attribute_id_seq'), 'book_reviews_json', 'text', 'text', '[]', NOW(), NOW(), 16, TRUE
            WHERE NOT EXISTS (SELECT 1 FROM sylius_product_attribute WHERE code = 'book_reviews_json')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO sylius_product_attribute_translation (id, translatable_id, name, locale)
            SELECT nextval('sylius_product_attribute_translation_id_seq'),
                   (SELECT id FROM sylius_product_attribute WHERE code = 'book_reviews_json'),
                   'book_reviews_json', 'fr_FR'
            WHERE NOT EXISTS (
                SELECT 1 FROM sylius_product_attribute_translation
                WHERE translatable_id = (SELECT id FROM sylius_product_attribute WHERE code = 'book_reviews_json')
                  AND locale = 'fr_FR'
            )
            SQL);

        // ─── 2. Insert FR/EN/NL review arrays for all 5 books ────────────────
        foreach ($this->reviewData() as $productId => $locales) {
            $pidSub = "(SELECT id FROM sylius_product WHERE code='{$productId}')";
            foreach ($locales as $locale => $json) {
                // SQL-escape single quotes in the JSON string before embedding.
                $escaped = str_replace("'", "''", $json);
                $this->addSql(<<<SQL
                    INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                    SELECT nextval('sylius_product_attribute_value_id_seq'), {$pidSub},
                           (SELECT id FROM sylius_product_attribute WHERE code='book_reviews_json'),
                           '{$locale}', '{$escaped}'
                    WHERE NOT EXISTS (
                        SELECT 1 FROM sylius_product_attribute_value
                        WHERE product_id={$pidSub}
                          AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_reviews_json')
                          AND locale_code='{$locale}'
                    )
                    SQL);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sylius_product_attribute_value WHERE attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_reviews_json')");
        $this->addSql("DELETE FROM sylius_product_attribute_translation WHERE translatable_id=(SELECT id FROM sylius_product_attribute WHERE code='book_reviews_json')");
        $this->addSql("DELETE FROM sylius_product_attribute WHERE code='book_reviews_json'");
    }
}
