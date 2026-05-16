<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds book_locale to personalization_session so the chosen book language
 * is persisted and used throughout the entire pipeline
 * (preview generation, PDF rendering, Gelato fulfillment).
 *
 * Nullable: existing sessions default to NULL which the code treats as 'fr'.
 */
final class Version20260506140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add book_locale column to app_personalization_session for multilingual book pipeline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE app_personalization_session
            ADD COLUMN IF NOT EXISTS book_locale VARCHAR(10) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session DROP COLUMN IF EXISTS book_locale');
    }
}
