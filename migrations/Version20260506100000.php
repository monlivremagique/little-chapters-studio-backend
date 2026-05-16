<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds multilingual catalog support: nl_NL locale, links en_US + nl_NL to the channel,
 * adds real EN translations (correcting FR copy-paste), adds NL translations for
 * taxons, products, and book_subtitle attributes.
 *
 * All statements are idempotent (WHERE NOT EXISTS / ON CONFLICT).
 * All IDs resolved by code subqueries — no hardcoded integer IDs.
 */
final class Version20260506100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multilingual catalog: add nl_NL locale, link en_US+nl_NL to channel, add EN/NL translations for taxons, products and book_subtitle attributes.';
    }

    // ── Subquery helpers — resolve IDs by stable code, never hardcode integers ──

    private static function ch(): string
    {
        return "(SELECT id FROM sylius_channel WHERE code='LITTLE_CHAPTERS_BE_FR')";
    }

    private static function tax(string $code): string
    {
        return "(SELECT id FROM sylius_taxon WHERE code='{$code}')";
    }

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
        // ─── 1. Add nl_NL locale ─────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            INSERT INTO sylius_locale (id, code, created_at, updated_at)
            SELECT nextval('sylius_locale_id_seq'), 'nl_NL', NOW(), NOW()
            WHERE NOT EXISTS (SELECT 1 FROM sylius_locale WHERE code = 'nl_NL')
            SQL);

        // ─── 2. Link en_US to channel ────────────────────────────────────────
        $ch = self::ch();
        $this->addSql(<<<SQL
            INSERT INTO sylius_channel_locales (channel_id, locale_id)
            SELECT $ch, id FROM sylius_locale WHERE code = 'en_US'
            ON CONFLICT DO NOTHING
            SQL);

        // ─── 3. Link nl_NL to channel ────────────────────────────────────────
        $this->addSql(<<<SQL
            INSERT INTO sylius_channel_locales (channel_id, locale_id)
            SELECT $ch, id FROM sylius_locale WHERE code = 'nl_NL'
            ON CONFLICT DO NOTHING
            SQL);

        // ─── 4. Correct taxon translations EN (copy-paste FR → real EN) ──────
        foreach ([
            'AVENTURES_MAGIQUES'  => ['Magical Adventures',       'Personalized adventure books for children aged 3 to 8.'],
            'HISTOIRES_DU_SOIR'   => ['Bedtime Stories',           'Personalized bedtime books for a calm and imaginative nighttime routine.'],
            'AMIS_ANIMAUX'        => ['Animal Friends',            'Personalized stories with adorable animals, nature, and tender exploration.'],
            'FETES_CELEBRATIONS'  => ['Celebrations & Birthdays',  'Personalized books for birthdays, births, and moments worth celebrating.'],
            'HEROS_DU_QUOTIDIEN'  => ['Everyday Heroes',           'Personalized stories that build confidence, autonomy, and pride in everyday achievements.'],
        ] as $taxCode => [$name, $desc]) {
            $tid = self::tax($taxCode);
            $this->addSql(<<<SQL
                UPDATE sylius_taxon_translation
                SET name = '{$name}', description = '{$desc}'
                WHERE translatable_id = $tid AND locale = 'en_US'
                SQL);
        }

        // ─── 5. Add NL taxon translations ─────────────────────────────────────
        // Slugs kept identical to FR — they are the stable URL identifiers.
        foreach ([
            'AVENTURES_MAGIQUES'  => ['Magische Avonturen',                     'aventures-magiques',       'Gepersonaliseerde avonturenboeken voor kinderen van 3 tot 8 jaar.'],
            'HISTOIRES_DU_SOIR'   => ['Verhaaltjes voor het Slapengaan',         'histoires-du-soir',        'Gepersonaliseerde slaapverhaaltjes voor een rustgevend bedtijdritueel.'],
            'AMIS_ANIMAUX'        => ['Dierenvrienden',                          'amis-animaux',             'Gepersonaliseerde verhalen met schattige dieren, natuur en tedere ontdekkingen.'],
            'FETES_CELEBRATIONS'  => ['Feesten en Verjaardagen',                 'fetes-et-celebrations',    'Gepersonaliseerde boeken voor verjaardagen, geboorten en bijzondere momenten.'],
            'HEROS_DU_QUOTIDIEN'  => ['Helden van Alledag',                      'heros-du-quotidien',       'Gepersonaliseerde verhalen die zelfvertrouwen en autonomie versterken.'],
        ] as $taxCode => [$name, $slug, $desc]) {
            $tid = self::tax($taxCode);
            $this->addSql(<<<SQL
                INSERT INTO sylius_taxon_translation (id, translatable_id, name, slug, description, locale)
                SELECT nextval('sylius_taxon_translation_id_seq'), $tid,
                    '{$name}', '{$slug}', '{$desc}', 'nl_NL'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_taxon_translation WHERE translatable_id = $tid AND locale = 'nl_NL'
                )
                SQL);
        }

        // ─── 6. Correct product translations EN ──────────────────────────────
        foreach ([
            'BOOK_AVENTURE_ENCHANTEE'       => ['The Enchanted Adventure', 'When your child becomes the hero of a magical quest'],
            'BOOK_VOYAGE_DES_ETOILES'       => ['The Stars Journey',       'A personalized celestial odyssey for little dreamers'],
            'BOOK_FORET_DES_MERVEILLES'     => ['The Enchanted Forest',    'A gentle stroll among animals and magic'],
            'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => ['Everyday Superhero',      'Your child discovers their inner superpowers'],
            'BOOK_DOUCE_NUIT_ETOILEE'       => ['Starry Goodnight',        'The perfect bedtime story, with your child as the main character'],
        ] as $prdCode => [$name, $shortDesc]) {
            $pid = self::prd($prdCode);
            $this->addSql(<<<SQL
                UPDATE sylius_product_translation
                SET name = '{$name}', short_description = '{$shortDesc}'
                WHERE translatable_id = $pid AND locale = 'en_US'
                SQL);
        }

        // ─── 7. Add NL product translations ──────────────────────────────────
        foreach ([
            'BOOK_AVENTURE_ENCHANTEE'       => ['Het Betoverde Avontuur',        'aventure-enchantee',          'Wanneer uw kind de held wordt van een magische zoektocht'],
            'BOOK_VOYAGE_DES_ETOILES'       => ['De Sterrenstocht',              'voyage-des-etoiles',          'Een gepersonaliseerde sterrenreis voor kleine dromers'],
            'BOOK_FORET_DES_MERVEILLES'     => ['Het Wondere Woud',              'foret-des-merveilles',        'Een zachte wandeling tussen dieren en magie'],
            'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => ['De Superheld van Alledag',      'super-heros-du-quotidien',    'Uw kind ontdekt zijn innerlijke superkrachten'],
            'BOOK_DOUCE_NUIT_ETOILEE'       => ['Een Zachte Nacht vol Sterren',  'douce-nuit-etoilee',          'Het perfecte slaapverhaaltje, met uw kind als hoofdpersoon'],
        ] as $prdCode => [$name, $slug, $shortDesc]) {
            $pid = self::prd($prdCode);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_translation (id, translatable_id, name, slug, short_description, locale)
                SELECT nextval('sylius_product_translation_id_seq'), $pid,
                    '{$name}', '{$slug}', '{$shortDesc}', 'nl_NL'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_translation WHERE translatable_id = $pid AND locale = 'nl_NL'
                )
                SQL);
        }

        // ─── 8. Correct book_subtitle EN ────────────────────────────────────
        $subAttr = self::attr('book_subtitle');
        foreach ([
            'BOOK_AVENTURE_ENCHANTEE'       => 'When your child becomes the hero of a magical quest',
            'BOOK_VOYAGE_DES_ETOILES'       => 'A personalized celestial odyssey for little dreamers',
            'BOOK_FORET_DES_MERVEILLES'     => 'A gentle stroll among animals and magic',
            'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => 'Your child discovers their inner superpowers',
            'BOOK_DOUCE_NUIT_ETOILEE'       => 'The perfect bedtime story, with your child as the main character',
        ] as $prdCode => $val) {
            $pid = self::prd($prdCode);
            $this->addSql(<<<SQL
                UPDATE sylius_product_attribute_value
                SET text_value = '{$val}'
                WHERE product_id = $pid AND attribute_id = $subAttr AND locale_code = 'en_US'
                SQL);
        }

        // ─── 9. Add book_subtitle NL ─────────────────────────────────────────
        foreach ([
            'BOOK_AVENTURE_ENCHANTEE'       => 'Wanneer uw kind de held wordt van een magische zoektocht',
            'BOOK_VOYAGE_DES_ETOILES'       => 'Een gepersonaliseerde sterrenreis voor kleine dromers',
            'BOOK_FORET_DES_MERVEILLES'     => 'Een zachte wandeling tussen dieren en magie',
            'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => 'Uw kind ontdekt zijn innerlijke superkrachten',
            'BOOK_DOUCE_NUIT_ETOILEE'       => 'Het perfecte slaapverhaaltje, met uw kind als hoofdpersoon',
        ] as $prdCode => $val) {
            $pid = self::prd($prdCode);
            $this->addSql(<<<SQL
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'), $pid, $subAttr, 'nl_NL', '{$val}'
                WHERE NOT EXISTS (
                    SELECT 1 FROM sylius_product_attribute_value
                    WHERE product_id = $pid AND attribute_id = $subAttr AND locale_code = 'nl_NL'
                )
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $subAttr = self::attr('book_subtitle');
        $ch = self::ch();

        // Remove NL book_subtitle attribute values
        $this->addSql("DELETE FROM sylius_product_attribute_value WHERE attribute_id = $subAttr AND locale_code = 'nl_NL'");

        // Remove NL product translations
        $this->addSql("DELETE FROM sylius_product_translation WHERE locale = 'nl_NL'");

        // Remove NL taxon translations
        $this->addSql("DELETE FROM sylius_taxon_translation WHERE locale = 'nl_NL'");

        // Revert EN taxon translations to FR copy-paste
        $this->addSql("UPDATE sylius_taxon_translation SET name='Aventures Magiques', description='Livres personnalises d''aventure et de quetes douces pour enfants de 3 a 8 ans.' WHERE translatable_id=" . self::tax('AVENTURES_MAGIQUES') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_taxon_translation SET name='Histoires du Soir', description='Livres personnalises de coucher et de rituel du soir, axes sur le calme et l''imaginaire.' WHERE translatable_id=" . self::tax('HISTOIRES_DU_SOIR') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_taxon_translation SET name='Amis Animaux', description='Histoires personnalisees avec animaux attachants, nature et exploration tendre.' WHERE translatable_id=" . self::tax('AMIS_ANIMAUX') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_taxon_translation SET name='Fetes et Celebrations', description='Livres personnalises pour anniversaires, naissances et moments a celebrer.' WHERE translatable_id=" . self::tax('FETES_CELEBRATIONS') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_taxon_translation SET name='Heros du Quotidien', description='Histoires personnalisees qui valorisent la confiance, l''autonomie et les petits exploits du quotidien.' WHERE translatable_id=" . self::tax('HEROS_DU_QUOTIDIEN') . " AND locale='en_US'");

        // Revert EN product translations to FR copy-paste
        $this->addSql("UPDATE sylius_product_translation SET name='L''Aventure Enchantee', short_description='Quand votre enfant devient le heros d''une quete magique' WHERE translatable_id=" . self::prd('BOOK_AVENTURE_ENCHANTEE') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_product_translation SET name='Le Voyage des Etoiles', short_description='Une odyssee celeste personnalisee pour les petits reveurs' WHERE translatable_id=" . self::prd('BOOK_VOYAGE_DES_ETOILES') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_product_translation SET name='La Foret des Merveilles', short_description='Une promenade douce entre animaux et magie' WHERE translatable_id=" . self::prd('BOOK_FORET_DES_MERVEILLES') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_product_translation SET name='Super-Heros du Quotidien', short_description='Votre enfant decouvre ses super-pouvoirs interieurs' WHERE translatable_id=" . self::prd('BOOK_SUPER_HEROS_DU_QUOTIDIEN') . " AND locale='en_US'");
        $this->addSql("UPDATE sylius_product_translation SET name='Douce Nuit Etoilee', short_description='L''histoire du soir parfaite, avec votre enfant en personnage principal' WHERE translatable_id=" . self::prd('BOOK_DOUCE_NUIT_ETOILEE') . " AND locale='en_US'");

        // Revert EN book_subtitle to FR copy-paste
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Quand votre enfant devient le heros d''une quete magique' WHERE product_id=" . self::prd('BOOK_AVENTURE_ENCHANTEE') . " AND attribute_id=$subAttr AND locale_code='en_US'");
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Une odyssee celeste personnalisee pour les petits reveurs' WHERE product_id=" . self::prd('BOOK_VOYAGE_DES_ETOILES') . " AND attribute_id=$subAttr AND locale_code='en_US'");
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Une promenade douce entre animaux et magie' WHERE product_id=" . self::prd('BOOK_FORET_DES_MERVEILLES') . " AND attribute_id=$subAttr AND locale_code='en_US'");
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='Votre enfant decouvre ses super-pouvoirs interieurs' WHERE product_id=" . self::prd('BOOK_SUPER_HEROS_DU_QUOTIDIEN') . " AND attribute_id=$subAttr AND locale_code='en_US'");
        $this->addSql("UPDATE sylius_product_attribute_value SET text_value='L''histoire du soir parfaite, avec votre enfant en personnage principal' WHERE product_id=" . self::prd('BOOK_DOUCE_NUIT_ETOILEE') . " AND attribute_id=$subAttr AND locale_code='en_US'");

        // Remove en_US + nl_NL channel links
        $this->addSql("DELETE FROM sylius_channel_locales WHERE channel_id = $ch AND locale_id = (SELECT id FROM sylius_locale WHERE code = 'en_US')");
        $this->addSql("DELETE FROM sylius_channel_locales WHERE channel_id = $ch AND locale_id = (SELECT id FROM sylius_locale WHERE code = 'nl_NL')");
        $this->addSql("DELETE FROM sylius_locale WHERE code = 'nl_NL'");
    }
}
