<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track uploaded photo deletion reason for RGPD-oriented retention and auditability.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_uploaded_photo ADD deleted_reason VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_uploaded_photo DROP deleted_reason');
    }
}
