<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420224000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align personalization session statuses with blueprint generation workflow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE app_personalization_session SET status = 'generation_requested' WHERE status = 'generation_queued'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE app_personalization_session SET status = 'generation_queued' WHERE status = 'generation_requested'");
    }
}
