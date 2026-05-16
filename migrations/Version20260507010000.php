<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds order_confirmation_email_sent boolean to personalization_session.
 * Used by PostPaymentProductionOrchestrator to prevent duplicate confirmation emails.
 */
final class Version20260507010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order_confirmation_email_sent flag to app_personalization_session for idempotent order confirmation emails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE app_personalization_session
            ADD COLUMN IF NOT EXISTS order_confirmation_email_sent BOOLEAN NOT NULL DEFAULT FALSE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session DROP COLUMN IF EXISTS order_confirmation_email_sent');
    }
}
