<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add approved preview versions, PDF artifacts, fulfillment orders, and operational events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_personalization_preview_version (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, generation_job_id INT NOT NULL, version_number INT NOT NULL, child_name VARCHAR(255) NOT NULL, dedication TEXT DEFAULT NULL, snapshot_payload JSON NOT NULL, content_hash VARCHAR(64) NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_preview_version_session_version ON app_personalization_preview_version (personalization_session_id, version_number)');
        $this->addSql('CREATE INDEX IDX_PREVIEW_VERSION_SESSION ON app_personalization_preview_version (personalization_session_id)');
        $this->addSql('CREATE INDEX IDX_PREVIEW_VERSION_GENERATION_JOB ON app_personalization_preview_version (generation_job_id)');
        $this->addSql('ALTER TABLE app_personalization_preview_version ADD CONSTRAINT FK_PREVIEW_VERSION_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE app_personalization_preview_version ADD CONSTRAINT FK_PREVIEW_VERSION_GENERATION_JOB FOREIGN KEY (generation_job_id) REFERENCES app_personalization_generation_job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE app_personalization_pdf_artifact (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, preview_version_id INT NOT NULL, status VARCHAR(32) NOT NULL, storage_path VARCHAR(255) NOT NULL, public_path VARCHAR(255) NOT NULL, file_hash VARCHAR(64) NOT NULL, file_size INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_pdf_preview_version ON app_personalization_pdf_artifact (preview_version_id)');
        $this->addSql('CREATE INDEX IDX_PDF_SESSION ON app_personalization_pdf_artifact (personalization_session_id)');
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact ADD CONSTRAINT FK_PDF_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE app_personalization_pdf_artifact ADD CONSTRAINT FK_PDF_PREVIEW_VERSION FOREIGN KEY (preview_version_id) REFERENCES app_personalization_preview_version (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE app_fulfillment_order (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, pdf_artifact_id INT NOT NULL, provider VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, order_number VARCHAR(255) NOT NULL, provider_order_id VARCHAR(255) DEFAULT NULL, tracking_url VARCHAR(512) DEFAULT NULL, tracking_number VARCHAR(255) DEFAULT NULL, request_payload JSON NOT NULL, response_payload JSON DEFAULT NULL, error_message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_fulfillment_order_session ON app_fulfillment_order (personalization_session_id)');
        $this->addSql('CREATE INDEX IDX_FULFILLMENT_PDF ON app_fulfillment_order (pdf_artifact_id)');
        $this->addSql('ALTER TABLE app_fulfillment_order ADD CONSTRAINT FK_FULFILLMENT_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE app_fulfillment_order ADD CONSTRAINT FK_FULFILLMENT_PDF FOREIGN KEY (pdf_artifact_id) REFERENCES app_personalization_pdf_artifact (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE app_operational_event (id SERIAL NOT NULL, type VARCHAR(64) NOT NULL, level VARCHAR(32) NOT NULL, session_id VARCHAR(36) DEFAULT NULL, order_number VARCHAR(255) DEFAULT NULL, context JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_operational_event_session ON app_operational_event (session_id)');
        $this->addSql('CREATE INDEX idx_operational_event_order ON app_operational_event (order_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_operational_event');
        $this->addSql('DROP TABLE app_fulfillment_order');
        $this->addSql('DROP TABLE app_personalization_pdf_artifact');
        $this->addSql('DROP TABLE app_personalization_preview_version');
    }
}
