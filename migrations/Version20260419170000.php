<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add preview approval and cart attachment fields to personalization sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_session ADD cart_token_value VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_session ADD cart_item_id VARCHAR(64) DEFAULT NULL');
        $this->addSql("UPDATE app_personalization_session SET status = 'content_completed' WHERE status = 'content_saved'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE app_personalization_session SET status = 'content_saved' WHERE status IN ('content_completed', 'preview_ready', 'approved', 'attached_to_cart')");
        $this->addSql('ALTER TABLE app_personalization_session DROP approved_at');
        $this->addSql('ALTER TABLE app_personalization_session DROP cart_token_value');
        $this->addSql('ALTER TABLE app_personalization_session DROP cart_item_id');
    }
}
