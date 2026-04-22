<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stable session to order item linkage and checkout propagation fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_session ADD sylius_order_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_session ADD sylius_order_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE app_personalization_order_item_link (id SERIAL NOT NULL, personalization_session_id VARCHAR(36) NOT NULL, order_item_id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_personalization_link_session ON app_personalization_order_item_link (personalization_session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_personalization_link_order_item ON app_personalization_order_item_link (order_item_id)');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD CONSTRAINT FK_PERSONALIZATION_LINK_SESSION FOREIGN KEY (personalization_session_id) REFERENCES app_personalization_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("UPDATE app_personalization_session SET status = 'cart_attached' WHERE status = 'attached_to_cart'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE app_personalization_session SET status = 'attached_to_cart' WHERE status = 'cart_attached'");
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP CONSTRAINT FK_PERSONALIZATION_LINK_SESSION');
        $this->addSql('DROP TABLE app_personalization_order_item_link');
        $this->addSql('ALTER TABLE app_personalization_session DROP sylius_order_id');
        $this->addSql('ALTER TABLE app_personalization_session DROP sylius_order_number');
    }
}
