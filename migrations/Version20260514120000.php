<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden go-live locale and payment production contracts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE app_personalization_session SET book_locale = 'fr' WHERE book_locale IS NULL OR book_locale NOT IN ('fr', 'en', 'nl')");
        $this->addSql("ALTER TABLE app_personalization_session ALTER book_locale SET DEFAULT 'fr'");
        $this->addSql('ALTER TABLE app_personalization_session ALTER book_locale SET NOT NULL');
        $this->addSql("CREATE TABLE app_stripe_pending_webhook_event (id SERIAL NOT NULL, provider_event_id VARCHAR(255) NOT NULL, provider_session_id VARCHAR(255) NOT NULL, type VARCHAR(191) NOT NULL, payload JSON NOT NULL, status VARCHAR(32) DEFAULT 'pending' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_stripe_pending_webhook_event_provider_event_id ON app_stripe_pending_webhook_event (provider_event_id)');
        $this->addSql('CREATE INDEX idx_stripe_pending_webhook_event_provider_session_id ON app_stripe_pending_webhook_event (provider_session_id)');
        $this->addSql("ALTER TABLE app_personalization_pdf_artifact ADD preflight_status VARCHAR(32) DEFAULT 'not_checked' NOT NULL");
        $this->addSql("ALTER TABLE app_personalization_pdf_artifact ADD preflight_report JSON DEFAULT '{}'::json NOT NULL");
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact ADD preflight_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact DROP preflight_checked_at');
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact DROP preflight_report');
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact DROP preflight_status');
        $this->addSql('DROP TABLE app_stripe_pending_webhook_event');
        $this->addSql('ALTER TABLE app_personalization_session ALTER book_locale DROP NOT NULL');
        $this->addSql('ALTER TABLE app_personalization_session ALTER book_locale DROP DEFAULT');
    }
}
