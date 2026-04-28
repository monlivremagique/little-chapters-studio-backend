<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add strict guest/customer ownership to personalization sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session ADD guest_owner_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_session ADD owner_customer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8D1A7D5B9F1BFD1D ON app_personalization_session (owner_customer_id)');
        $this->addSql('ALTER TABLE app_personalization_session ADD CONSTRAINT FK_8D1A7D5B9F1BFD1D FOREIGN KEY (owner_customer_id) REFERENCES sylius_customer (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("UPDATE app_personalization_session SET guest_owner_token = md5(random()::text || clock_timestamp()::text || id) WHERE guest_owner_token IS NULL OR guest_owner_token = ''");
        $this->addSql('ALTER TABLE app_personalization_session ALTER guest_owner_token SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session DROP CONSTRAINT FK_8D1A7D5B9F1BFD1D');
        $this->addSql('DROP INDEX IDX_8D1A7D5B9F1BFD1D');
        $this->addSql('ALTER TABLE app_personalization_session DROP guest_owner_token');
        $this->addSql('ALTER TABLE app_personalization_session DROP owner_customer_id');
    }
}
