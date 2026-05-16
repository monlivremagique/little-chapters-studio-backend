<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:backfill-catalog-locales',
    description: 'Replays multilingual catalog data migrations after fixtures so local databases match production data shape.',
)]
final class BackfillCatalogLocalesCommand extends Command
{
    /** @var list<string> */
    private const MIGRATION_VERSIONS = [
        'DoctrineMigrations\\Version20260506100000',
        'DoctrineMigrations\\Version20260506110000',
        'DoctrineMigrations\\Version20260506120000',
        'DoctrineMigrations\\Version20260506130000',
        'DoctrineMigrations\\Version20260506150000',
        'DoctrineMigrations\\Version20260512000000',
        'DoctrineMigrations\\Version20260512100000',
        'DoctrineMigrations\\Version20260512200000',
    ];

    /** @var array<string, array<string, array<string, string>>> */
    private const TRANSLATED_ATTRIBUTE_VALUES = [
        'BOOK_AVENTURE_ENCHANTEE' => [
            'book_badge' => ['fr_FR' => 'Best-seller', 'en_US' => 'Best-seller', 'nl_NL' => 'Bestseller'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'aventure', 'en_US' => 'aventure', 'nl_NL' => 'aventure'],
        ],
        'BOOK_VOYAGE_DES_ETOILES' => [
            'book_badge' => ['fr_FR' => 'Nouveau', 'en_US' => 'New', 'nl_NL' => 'Nieuw'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'aventure', 'en_US' => 'aventure', 'nl_NL' => 'aventure'],
        ],
        'BOOK_FORET_DES_MERVEILLES' => [
            'book_badge' => ['fr_FR' => '', 'en_US' => '', 'nl_NL' => ''],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'avancee', 'en_US' => 'advanced', 'nl_NL' => 'advanced'],
            'book_theme' => ['fr_FR' => 'animaux', 'en_US' => 'animaux', 'nl_NL' => 'animaux'],
        ],
        'BOOK_SUPER_HEROS_DU_QUOTIDIEN' => [
            'book_badge' => ['fr_FR' => 'Coup de coeur', 'en_US' => 'Our pick', 'nl_NL' => 'Aanrader'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'heros', 'en_US' => 'heros', 'nl_NL' => 'heros'],
        ],
        'BOOK_DOUCE_NUIT_ETOILEE' => [
            'book_badge' => ['fr_FR' => '', 'en_US' => '', 'nl_NL' => ''],
            'book_cover_type' => ['fr_FR' => 'souple', 'en_US' => 'softcover', 'nl_NL' => 'softcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'simple', 'en_US' => 'simple', 'nl_NL' => 'simple'],
            'book_theme' => ['fr_FR' => 'douceur', 'en_US' => 'douceur', 'nl_NL' => 'douceur'],
        ],
        'BOOK_FOREST_OF_LOST_STARS' => [
            'book_badge' => ['fr_FR' => 'Nouveau', 'en_US' => 'New', 'nl_NL' => 'Nieuw'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'aventure', 'en_US' => 'aventure', 'nl_NL' => 'aventure'],
        ],
        'BOOK_VILLE_ECOLE' => [
            'book_badge' => ['fr_FR' => 'Nouveau', 'en_US' => 'New', 'nl_NL' => 'Nieuw'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'aventure', 'en_US' => 'aventure', 'nl_NL' => 'aventure'],
        ],
        'BOOK_ESPACE_ROBOT' => [
            'book_badge' => ['fr_FR' => 'Nouveau', 'en_US' => 'New', 'nl_NL' => 'Nieuw'],
            'book_cover_type' => ['fr_FR' => 'rigide', 'en_US' => 'hardcover', 'nl_NL' => 'hardcover'],
            'book_format' => ['fr_FR' => '21 x 21 cm', 'en_US' => '21 x 21 cm', 'nl_NL' => '21 x 21 cm'],
            'book_language' => ['fr_FR' => 'fr', 'en_US' => 'en', 'nl_NL' => 'nl'],
            'book_personalization_level' => ['fr_FR' => 'premium', 'en_US' => 'premium', 'nl_NL' => 'premium'],
            'book_theme' => ['fr_FR' => 'aventure', 'en_US' => 'aventure', 'nl_NL' => 'aventure'],
        ],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $this->connection->executeStatement(
            'DELETE FROM sylius_migrations WHERE version IN (?)',
            [self::MIGRATION_VERSIONS],
            [ArrayParameterType::STRING],
        );

        foreach (self::MIGRATION_VERSIONS as $version) {
            $output->writeln(sprintf('<info>Replaying %s</info>', $version));

            $statusCode = $application->run(new ArrayInput([
                'command' => 'doctrine:migrations:execute',
                '--up' => true,
                '--no-interaction' => true,
                'versions' => [$version],
            ]), $output);

            if (Command::SUCCESS !== $statusCode) {
                return $statusCode;
            }
        }

        $this->backfillSecondaryTranslatedAttributes();

        $output->writeln('<info>Catalog locale backfill completed.</info>');

        return Command::SUCCESS;
    }

    private function backfillSecondaryTranslatedAttributes(): void
    {
        foreach (self::TRANSLATED_ATTRIBUTE_VALUES as $productCode => $attributeValues) {
            foreach ($attributeValues as $attributeCode => $localizedValues) {
                foreach ($localizedValues as $localeCode => $value) {
                    $this->upsertAttributeValue($productCode, $attributeCode, $localeCode, $value);
                }
            }
        }
    }

    private function upsertAttributeValue(string $productCode, string $attributeCode, string $localeCode, string $value): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO sylius_product_attribute_value (id, product_id, attribute_id, locale_code, text_value)
                SELECT nextval('sylius_product_attribute_value_id_seq'),
                       (SELECT id FROM sylius_product WHERE code = :productCode),
                       (SELECT id FROM sylius_product_attribute WHERE code = :attributeCode),
                       :localeCode,
                       :value
                WHERE (SELECT id FROM sylius_product WHERE code = :productCode) IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1
                    FROM sylius_product_attribute_value
                    WHERE product_id = (SELECT id FROM sylius_product WHERE code = :productCode)
                      AND attribute_id = (SELECT id FROM sylius_product_attribute WHERE code = :attributeCode)
                      AND locale_code = :localeCode
                  )
            SQL,
            [
                'productCode' => $productCode,
                'attributeCode' => $attributeCode,
                'localeCode' => $localeCode,
                'value' => $value,
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE sylius_product_attribute_value
                SET text_value = :value
                WHERE product_id = (SELECT id FROM sylius_product WHERE code = :productCode)
                  AND attribute_id = (SELECT id FROM sylius_product_attribute WHERE code = :attributeCode)
                  AND locale_code = :localeCode
                  AND (SELECT id FROM sylius_product WHERE code = :productCode) IS NOT NULL
            SQL,
            [
                'productCode' => $productCode,
                'attributeCode' => $attributeCode,
                'localeCode' => $localeCode,
                'value' => $value,
            ],
        );
    }
}
