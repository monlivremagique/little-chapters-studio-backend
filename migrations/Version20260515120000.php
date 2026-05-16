<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create book_creation_project table for admin pipeline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE book_creation_project (
            id SERIAL NOT NULL,
            slug VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            brief JSON NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'draft\',
            current_step VARCHAR(50) DEFAULT NULL,
            progress_pct INT NOT NULL DEFAULT 0,
            qa_scores JSON DEFAULT NULL,
            error TEXT DEFAULT NULL,
            logs JSON NOT NULL DEFAULT \'[]\',
            blueprint_path VARCHAR(255) DEFAULT NULL,
            master_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_creation_project');
    }
}
