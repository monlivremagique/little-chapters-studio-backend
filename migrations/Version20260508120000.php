<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unverified social proof review attributes from the storefront catalog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sylius_product_attribute_value
            SET text_value = '[]'
            WHERE attribute_id = (
                SELECT id FROM sylius_product_attribute WHERE code = 'book_reviews_json'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Intentionally not restoring unverified reviews.
    }
}
