<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persisted generation jobs and preview artifacts for personalization previews.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_personalization_generation_job (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, status VARCHAR(32) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_APP_PERSONALIZATION_GENERATION_JOB_SESSION ON app_personalization_generation_job (personalization_session_id)');
        $this->addSql('COMMENT ON COLUMN app_personalization_generation_job.status IS \'(DC2Type:App\\Entity\\Personalization\\PersonalizationGenerationJobStatus)\'');
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD CONSTRAINT FK_APP_PERSONALIZATION_GENERATION_JOB_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE app_personalization_preview_artifact (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, generation_job_id INT NOT NULL, page_number INT NOT NULL, label VARCHAR(255) NOT NULL, is_personalized BOOLEAN NOT NULL, public_path VARCHAR(255) NOT NULL, mime_type VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_APP_PERSONALIZATION_PREVIEW_ARTIFACT_SESSION ON app_personalization_preview_artifact (personalization_session_id)');
        $this->addSql('CREATE INDEX IDX_APP_PERSONALIZATION_PREVIEW_ARTIFACT_JOB ON app_personalization_preview_artifact (generation_job_id)');
        $this->addSql('ALTER TABLE app_personalization_preview_artifact ADD CONSTRAINT FK_APP_PERSONALIZATION_PREVIEW_ARTIFACT_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE app_personalization_preview_artifact ADD CONSTRAINT FK_APP_PERSONALIZATION_PREVIEW_ARTIFACT_JOB FOREIGN KEY (generation_job_id) REFERENCES app_personalization_generation_job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_preview_artifact DROP CONSTRAINT FK_APP_PERSONALIZATION_PREVIEW_ARTIFACT_SESSION');
        $this->addSql('ALTER TABLE app_personalization_preview_artifact DROP CONSTRAINT FK_APP_PERSONALIZATION_PREVIEW_ARTIFACT_JOB');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP CONSTRAINT FK_APP_PERSONALIZATION_GENERATION_JOB_SESSION');
        $this->addSql('DROP TABLE app_personalization_preview_artifact');
        $this->addSql('DROP TABLE app_personalization_generation_job');
    }
}
