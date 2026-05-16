<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personalized cart item snapshot fields for pre-payment validation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD order_token_value VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD variant_code VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD product_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD unit_price INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_personalization_order_item_link ADD currency_code VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP order_token_value');
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP variant_code');
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP product_name');
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP unit_price');
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP quantity');
        $this->addSql('ALTER TABLE app_personalization_order_item_link DROP currency_code');
    }
}
