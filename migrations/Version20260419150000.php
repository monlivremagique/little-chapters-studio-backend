<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add minimal personalization session and uploaded photo custom domain tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_personalization_session (id VARCHAR(36) NOT NULL, book_id VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, step INT NOT NULL, child_name VARCHAR(255) DEFAULT NULL, dedication TEXT DEFAULT NULL, extra_fields JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE app_uploaded_photo (id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, status VARCHAR(32) NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, file_size INT NOT NULL, public_path VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3E66B298613FECDF ON app_uploaded_photo (session_id)');
        $this->addSql('ALTER TABLE app_uploaded_photo ADD CONSTRAINT FK_3E66B298613FECDF FOREIGN KEY (session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_uploaded_photo DROP CONSTRAINT FK_3E66B298613FECDF');
        $this->addSql('DROP TABLE app_uploaded_photo');
        $this->addSql('DROP TABLE app_personalization_session');
    }
}
