<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist Replicate provider metadata on personalization generation jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_personalization_generation_job ADD provider VARCHAR(32) DEFAULT 'replicate' NOT NULL");
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD provider_job_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD provider_status VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD model_reference VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD attempt_number INT DEFAULT 1 NOT NULL');
        $this->addSql("ALTER TABLE app_personalization_generation_job ADD request_payload JSON DEFAULT '{}'::json NOT NULL");
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD response_payload JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_generation_job ADD last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE app_personalization_generation_job SET provider = 'local_backend_generator' WHERE provider IS NULL OR provider = 'replicate'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP provider');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP provider_job_id');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP provider_status');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP model_reference');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP attempt_number');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP request_payload');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP response_payload');
        $this->addSql('ALTER TABLE app_personalization_generation_job DROP last_polled_at');
    }
}
