<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds multilingual marketing content for product detail pages:
 * - 5 new translatable text attributes: book_description, book_long_description,
 *   book_emotional_promise, book_features (JSON array), book_print_quality
 * - FR/EN/NL values for all 5 books
 * - EN corrections + NL values for book_badge
 *
 * All statements are idempotent (WHERE NOT EXISTS / UPDATE only when needed).
 */
final class Version20260506110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multilingual product content: add book_description, book_long_description, book_emotional_promise, book_features, book_print_quality attributes with FR/EN/NL values for all 5 books. Correct badge EN/add NL.';
    }

    // ── Subquery helpers — no hardcoded integer IDs ──────────────────────────

    private static function prd(string $code): string
    {
        return "(SELECT id FROM sylius_product WHERE code='{$code}')";
    }

    private static function attr(string $code): string
    {
        return "(SELECT id FROM sylius_product_attribute WHERE code='{$code}')";
    }

    public function up(Schema $schema): void
    {
        // ─── 1. Create 5 new translatable text attributes ─────────────────────
        foreach ([
            ['book_description',      11],
            ['book_long_description', 12],
            ['book_emotional_promise',13],
            ['book_features',         14],
            ['book_print_quality',    15],
        ] as [$code, $position]) {
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute (id, code, type, storage_type, configuration, created_at, updated_at, position, translatable)
                SELECT nextval('sylius_product_attribute_id_seq'), '$code', 'text', 'text', '[]', NOW(), NOW(), $position, TRUE
                WHERE NOT EXISTS (SELECT 1 FROM sylius_product_attribute WHERE code = '$code')
                SQL);
            // Admin label (fr_FR and en_US for Sylius admin display)
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_translation (id, translatable_id, name, locale)
                SELECT nextval('sylius_product_attribute_translation_id_seq'),
                       (SELECT id FROM sylius_product_attribute WHERE code = '$code'),
                       '$code', 'fr_FR'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_translation
                    WHERE translatable_id = (SELECT id FROM sylius_product_attribute WHERE code = '$code')
                      AND locale = 'fr_FR'
                )
                SQL);
        }

        // ─── 2. Helper: insert a text attribute value idempotently ────────────
        // Pattern used below: INSERT ... WHERE NOT EXISTS (same product+attr+locale)
        // Updates are applied separately where we want to overwrite.

        // ─── 3. book_description ─────────────────────────────────────────────
        $descriptions = [
            // [product_code, locale, value]
            ['BOOK_AVENTURE_ENCHANTEE',       'fr_FR', "L'Aventure Enchantee emmene votre enfant dans un monde magique ou il devient le heros d'une quete extraordinaire."],
            ['BOOK_AVENTURE_ENCHANTEE',       'en_US', "The Enchanted Adventure takes your child into a magical world where they become the hero of an extraordinary quest."],
            ['BOOK_AVENTURE_ENCHANTEE',       'nl_NL', "Het Betoverde Avontuur neemt uw kind mee naar een magische wereld waar hij of zij de held wordt van een buitengewone zoektocht."],
            ['BOOK_VOYAGE_DES_ETOILES',       'fr_FR', "Le Voyage des Etoiles ouvre une odyssee douce et lumineuse pour les enfants qui aiment rever grand."],
            ['BOOK_VOYAGE_DES_ETOILES',       'en_US', "The Stars Journey opens a soft and luminous odyssey for children who love to dream big."],
            ['BOOK_VOYAGE_DES_ETOILES',       'nl_NL', "De Sterrenstocht opent een zachte en lichtgevende odyssee voor kinderen die groot durven dromen."],
            ['BOOK_FORET_DES_MERVEILLES',     'fr_FR', "La Foret des Merveilles propose une promenade tendre entre animaux attachants et magie douce."],
            ['BOOK_FORET_DES_MERVEILLES',     'en_US', "The Enchanted Forest offers a tender stroll among lovable animals and gentle magic."],
            ['BOOK_FORET_DES_MERVEILLES',     'nl_NL', "Het Wondere Woud biedt een tedere wandeling tussen schattige dieren en zachte magie."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'fr_FR', "Super-Heros du Quotidien aide l'enfant a voir sa force, ses qualites et ses petits exploits du quotidien."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'en_US', "Everyday Superhero helps children see their strength, qualities and everyday achievements."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'nl_NL', "De Superheld van Alledag helpt kinderen hun kracht, kwaliteiten en dagelijkse prestaties te zien."],
            ['BOOK_DOUCE_NUIT_ETOILEE',       'fr_FR', "Douce Nuit Etoilee accompagne le coucher avec une histoire paisible ou l'enfant retrouve son propre univers."],
            ['BOOK_DOUCE_NUIT_ETOILEE',       'en_US', "Starry Goodnight accompanies bedtime with a peaceful story where children find their own universe."],
            ['BOOK_DOUCE_NUIT_ETOILEE',       'nl_NL', "Een Zachte Nacht vol Sterren begeleidt het slapengaan met een rustig verhaal waar het kind zijn eigen wereld terugvindt."],
        ];

        foreach ($descriptions as [$prdCode, $locale, $val]) {
            $pid = self::prd($prdCode);
            $attrId = self::attr('book_description');
            $escaped = str_replace("'", "''", $val);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid, $attrId, '$locale', '$escaped'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id=$pid AND attribute_id=$attrId AND locale_code='$locale'
                )
                SQL);
        }

        // ─── 4. book_long_description ─────────────────────────────────────────
        $longDescriptions = [
            ['BOOK_AVENTURE_ENCHANTEE', 'fr_FR', "Plongez votre enfant dans une aventure epique personnalisee. Chaque page fait de lui le coeur du recit et transforme la lecture du soir en souvenir marquant."],
            ['BOOK_AVENTURE_ENCHANTEE', 'en_US', "Plunge your child into a personalised epic adventure. Every page makes them the heart of the story and transforms bedtime reading into a lasting memory."],
            ['BOOK_AVENTURE_ENCHANTEE', 'nl_NL', "Dompel uw kind onder in een gepersonaliseerd episch avontuur. Elke pagina maakt hem of haar tot het hart van het verhaal en verandert het avondlezen in een onvergetelijke herinnering."],
            ['BOOK_VOYAGE_DES_ETOILES', 'fr_FR', "Ce livre personnalise entraine l'enfant dans une aventure spatiale rassurante, faite de decouvertes, d'etoiles et de courage tranquille."],
            ['BOOK_VOYAGE_DES_ETOILES', 'en_US', "This personalised book takes the child on a reassuring space adventure full of discoveries, stars and quiet courage."],
            ['BOOK_VOYAGE_DES_ETOILES', 'nl_NL', "Dit gepersonaliseerde boek neemt het kind mee op een geruststellend ruimteavontuur, vol ontdekkingen, sterren en stille moed."],
            ['BOOK_FORET_DES_MERVEILLES', 'fr_FR', "Pense pour les plus jeunes, ce livre personnalise melange nature, tendresse et repetition rassurante dans un format ideal pour le rituel de lecture."],
            ['BOOK_FORET_DES_MERVEILLES', 'en_US', "Designed for the youngest readers, this personalised book blends nature, tenderness and soothing repetition in an ideal format for reading rituals."],
            ['BOOK_FORET_DES_MERVEILLES', 'nl_NL', "Ontworpen voor de allerkleinsten, dit gepersonaliseerde boek combineert natuur, tederheid en rustgevende herhaling in een ideaal formaat voor het leesritueel."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'fr_FR', "Le recit place l'enfant au centre d'une histoire de confiance en soi, de courage et d'autonomie, sans quitter un ton doux et bienveillant."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'en_US', "The story places the child at the centre of a narrative about self-confidence, courage and independence, always in a gentle and caring tone."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'nl_NL', "Het verhaal plaatst het kind centraal in een vertelling over zelfvertrouwen, moed en zelfstandigheid, steeds in een zachte en liefdevolle toon."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'fr_FR', "Ce livre personnalise est concu pour les routines du soir : rythme calme, promesse affective forte et reperes visuels simples pour apaiser avant le sommeil."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'en_US', "This personalised book is designed for evening routines: a calm pace, a strong emotional promise and simple visual cues to soothe before sleep."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'nl_NL', "Dit gepersonaliseerde boek is ontworpen voor de avondroutine: een rustig tempo, een sterke emotionele belofte en eenvoudige visuele ankerpunten om te kalmeren voor het slapen."],
        ];

        foreach ($longDescriptions as [$prdCode, $locale, $val]) {
            $pid = self::prd($prdCode);
            $attrId = self::attr('book_long_description');
            $escaped = str_replace("'", "''", $val);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid,
                       $attrId,
                       '$locale', '$escaped'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id=$pid
                      AND attribute_id=$attrId
                      AND locale_code='$locale'
                )
                SQL);
        }

        // ─── 5. book_emotional_promise ────────────────────────────────────────
        $promises = [
            ['BOOK_AVENTURE_ENCHANTEE', 'fr_FR', "Un cadeau qui fait briller les yeux de l'enfant des la couverture."],
            ['BOOK_AVENTURE_ENCHANTEE', 'en_US', "A gift that makes a child's eyes light up from the very first page."],
            ['BOOK_AVENTURE_ENCHANTEE', 'nl_NL', "Een cadeau dat de ogen van uw kind laat stralen vanaf de allereerste bladzijde."],
            ['BOOK_VOYAGE_DES_ETOILES', 'fr_FR', "Une histoire qui donne envie d'imaginer l'impossible, soir apres soir."],
            ['BOOK_VOYAGE_DES_ETOILES', 'en_US', "A story that inspires dreaming of the impossible, night after night."],
            ['BOOK_VOYAGE_DES_ETOILES', 'nl_NL', "Een verhaal dat avond na avond de verbeelding prikkelt en het onmogelijke doet dromen."],
            ['BOOK_FORET_DES_MERVEILLES', 'fr_FR', "Une bulle de douceur qui rassure et apaise a chaque lecture."],
            ['BOOK_FORET_DES_MERVEILLES', 'en_US', "A bubble of gentleness that comforts and soothes with every read."],
            ['BOOK_FORET_DES_MERVEILLES', 'nl_NL', "Een bubbel van zachtheid die bij elke lezing troost en kalmte brengt."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'fr_FR', "Un livre qui nourrit la fierte, la confiance et l'envie d'oser."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'en_US', "A book that nurtures pride, confidence and the courage to try."],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'nl_NL', "Een boek dat trots, zelfvertrouwen en de durf om iets te proberen voedt."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'fr_FR', "Le livre du soir qui transforme le coucher en moment privilegie."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'en_US', "The bedtime book that turns going to sleep into a treasured moment."],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'nl_NL', "Het slaapboek dat het slapengaan verandert in een bijzonder moment."],
        ];

        foreach ($promises as [$prdCode, $locale, $val]) {
            $pid = self::prd($prdCode);
            $attrId = self::attr('book_emotional_promise');
            $escaped = str_replace("'", "''", $val);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid,
                       $attrId,
                       '$locale', '$escaped'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id=$pid
                      AND attribute_id=$attrId
                      AND locale_code='$locale'
                )
                SQL);
        }

        // ─── 6. book_features (JSON array stored as text) ────────────────────
        $features = [
            ['BOOK_AVENTURE_ENCHANTEE', 'fr_FR', '["28 pages illustrees","Couverture rigide premium","Personnalisation du visage","Dedicace personnalisee","Impression haute qualite"]'],
            ['BOOK_AVENTURE_ENCHANTEE', 'en_US', '["28 illustrated pages","Premium hardcover","Face personalisation","Personalised dedication","High quality printing"]'],
            ['BOOK_AVENTURE_ENCHANTEE', 'nl_NL', '["28 geillustreerde paginas","Premium harde kaft","Gezichtspersonalisatie","Gepersonaliseerde opdracht","Hoogwaardige druk"]'],
            ['BOOK_VOYAGE_DES_ETOILES', 'fr_FR', '["28 pages illustrees","Couverture rigide premium","Univers celeste poetique","Dedicace personnalisee","Fabrication soignee"]'],
            ['BOOK_VOYAGE_DES_ETOILES', 'en_US', '["28 illustrated pages","Premium hardcover","Poetic celestial world","Personalised dedication","Careful craftsmanship"]'],
            ['BOOK_VOYAGE_DES_ETOILES', 'nl_NL', '["28 geillustreerde paginas","Premium harde kaft","Poetische sterrenwereld","Gepersonaliseerde opdracht","Zorgvuldige afwerking"]'],
            ['BOOK_FORET_DES_MERVEILLES', 'fr_FR', '["24 pages illustrees","Couverture rigide premium","Animaux et nature","Personnalisation enfant","Impression haute qualite"]'],
            ['BOOK_FORET_DES_MERVEILLES', 'en_US', '["24 illustrated pages","Premium hardcover","Animals and nature","Child personalisation","High quality printing"]'],
            ['BOOK_FORET_DES_MERVEILLES', 'nl_NL', '["24 geillustreerde paginas","Premium harde kaft","Dieren en natuur","Kindgepersonaliseerd","Hoogwaardige druk"]'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'fr_FR', '["28 pages illustrees","Couverture rigide premium","Theme confiance en soi","Personnalisation enfant","Impression haute qualite"]'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'en_US', '["28 illustrated pages","Premium hardcover","Self-confidence theme","Child personalisation","High quality printing"]'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'nl_NL', '["28 geillustreerde paginas","Premium harde kaft","Thema zelfvertrouwen","Kindgepersonaliseerd","Hoogwaardige druk"]'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'fr_FR', '["20 pages illustrees","Couverture souple premium","Rituel du coucher","Personnalisation enfant","Impression haute qualite"]'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'en_US', '["20 illustrated pages","Premium softcover","Bedtime routine","Child personalisation","High quality printing"]'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'nl_NL', '["20 geillustreerde paginas","Premium softe kaft","Bedtijdritueel","Kindgepersonaliseerd","Hoogwaardige druk"]'],
        ];

        foreach ($features as [$prdCode, $locale, $val]) {
            $pid = self::prd($prdCode);
            $attrId = self::attr('book_features');
            $escaped = str_replace("'", "''", $val);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid,
                       $attrId,
                       '$locale', '$escaped'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id=$pid
                      AND attribute_id=$attrId
                      AND locale_code='$locale'
                )
                SQL);
        }

        // ─── 7. book_print_quality ────────────────────────────────────────────
        $printQuality = [
            ['BOOK_AVENTURE_ENCHANTEE', 'fr_FR', 'Impression offset premium'],
            ['BOOK_AVENTURE_ENCHANTEE', 'en_US', 'Premium offset printing'],
            ['BOOK_AVENTURE_ENCHANTEE', 'nl_NL', 'Premium offsetdruk'],
            ['BOOK_VOYAGE_DES_ETOILES', 'fr_FR', 'Impression offset premium'],
            ['BOOK_VOYAGE_DES_ETOILES', 'en_US', 'Premium offset printing'],
            ['BOOK_VOYAGE_DES_ETOILES', 'nl_NL', 'Premium offsetdruk'],
            ['BOOK_FORET_DES_MERVEILLES', 'fr_FR', 'Impression offset premium'],
            ['BOOK_FORET_DES_MERVEILLES', 'en_US', 'Premium offset printing'],
            ['BOOK_FORET_DES_MERVEILLES', 'nl_NL', 'Premium offsetdruk'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'fr_FR', 'Impression offset premium'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'en_US', 'Premium offset printing'],
            ['BOOK_SUPER_HEROS_DU_QUOTIDIEN', 'nl_NL', 'Premium offsetdruk'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'fr_FR', 'Impression offset premium'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'en_US', 'Premium offset printing'],
            ['BOOK_DOUCE_NUIT_ETOILEE', 'nl_NL', 'Premium offsetdruk'],
        ];

        foreach ($printQuality as [$prdCode, $locale, $val]) {
            $pid = self::prd($prdCode);
            $attrId = self::attr('book_print_quality');
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid,
                       $attrId,
                       '$locale', '$val'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id=$pid
                      AND attribute_id=$attrId
                      AND locale_code='$locale'
                )
                SQL);
        }

        // ─── 8. book_badge — correct EN + add NL ─────────────────────────────
        // book_badge attr_id=52. Current EN = copy of FR. Correct EN + add NL.
        // Book 22 (VOYAGE_DES_ETOILES): FR "Nouveau" → EN "New" / NL "Nieuw"
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='New' WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_VOYAGE_DES_ETOILES') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='en_US'");
        $this->addSql(<<<'SQL'
            INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
            SELECT nextval('sylius_product_attribute_value_id_seq'), (SELECT id FROM sylius_product WHERE code='BOOK_VOYAGE_DES_ETOILES'), (SELECT id FROM sylius_product_attribute WHERE code='book_badge'), 'nl_NL', 'Nieuw'
            WHERE NOT EXISTS (
                SELECT 1 FROM sylius_product_attribute_value WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_VOYAGE_DES_ETOILES') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='nl_NL'
            )
            SQL);

        // Book 24 (SUPER_HEROS): FR "Coup de coeur" → EN "Our pick" / NL "Aanrader"
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Our pick' WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_SUPER_HEROS_DU_QUOTIDIEN') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='en_US'");
        $this->addSql(<<<'SQL'
            INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
            SELECT nextval('sylius_product_attribute_value_id_seq'), (SELECT id FROM sylius_product WHERE code='BOOK_SUPER_HEROS_DU_QUOTIDIEN'), (SELECT id FROM sylius_product_attribute WHERE code='book_badge'), 'nl_NL', 'Aanrader'
            WHERE NOT EXISTS (
                SELECT 1 FROM sylius_product_attribute_value WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_SUPER_HEROS_DU_QUOTIDIEN') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='nl_NL'
            )
            SQL);

        // Book 21 (AVENTURE): "Best-seller" identical in FR/EN — add NL same value
        $this->addSql(<<<'SQL'
            INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
            SELECT nextval('sylius_product_attribute_value_id_seq'), (SELECT id FROM sylius_product WHERE code='BOOK_AVENTURE_ENCHANTEE'), (SELECT id FROM sylius_product_attribute WHERE code='book_badge'), 'nl_NL', 'Bestseller'
            WHERE NOT EXISTS (
                SELECT 1 FROM sylius_product_attribute_value WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_AVENTURE_ENCHANTEE') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='nl_NL'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove all values for the 5 new attributes
        foreach (['book_description', 'book_long_description', 'book_emotional_promise', 'book_features', 'book_print_quality'] as $code) {
            $this->addSql("DELETE FROM sylius_product_attribute_value WHERE attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='$code')");
            $this->addSql("DELETE FROM sylius_product_attribute_translation WHERE translatable_id=(SELECT id FROM sylius_product_attribute WHERE code='$code')");
            $this->addSql("DELETE FROM sylius_product_attribute WHERE code='$code'");
        }

        // Revert badge EN corrections, remove NL badge values
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Nouveau' WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_VOYAGE_DES_ETOILES') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='en_US'");
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Coup de coeur' WHERE product_id=(SELECT id FROM sylius_product WHERE code='BOOK_SUPER_HEROS_DU_QUOTIDIEN') AND attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='en_US'");
        $this->addSql("DELETE FROM sylius_product_attribute_value WHERE attribute_id=(SELECT id FROM sylius_product_attribute WHERE code='book_badge') AND locale_code='nl_NL'");
    }
}
