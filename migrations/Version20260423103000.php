<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Stripe checkout persistence tables and rename the local payment method to Stripe checkout';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_stripe_checkout_session (id SERIAL NOT NULL, owner_customer_id INT DEFAULT NULL, provider_session_id VARCHAR(255) NOT NULL, provider_payment_intent_id VARCHAR(255) DEFAULT NULL, sylius_order_id INT NOT NULL, sylius_order_number VARCHAR(64) NOT NULL, sylius_order_token_value VARCHAR(255) NOT NULL, sylius_payment_id INT NOT NULL, amount_total INT NOT NULL, currency_code VARCHAR(3) NOT NULL, checkout_url TEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, payment_status VARCHAR(32) NOT NULL, error_message TEXT DEFAULT NULL, guest_owner_token VARCHAR(128) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_APP_STRIPE_CHECKOUT_SESSION_OWNER_CUSTOMER ON app_stripe_checkout_session (owner_customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_stripe_checkout_session_provider_id ON app_stripe_checkout_session (provider_session_id)');
        $this->addSql('CREATE INDEX idx_app_stripe_checkout_session_order_number ON app_stripe_checkout_session (sylius_order_number)');
        $this->addSql('CREATE INDEX idx_app_stripe_checkout_session_payment_id ON app_stripe_checkout_session (sylius_payment_id)');
        $this->addSql('CREATE TABLE app_stripe_webhook_event (id SERIAL NOT NULL, provider_event_id VARCHAR(255) NOT NULL, type VARCHAR(191) NOT NULL, payload JSON NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_stripe_webhook_event_provider_event_id ON app_stripe_webhook_event (provider_event_id)');
        $this->addSql('ALTER TABLE app_stripe_checkout_session ADD CONSTRAINT FK_APP_STRIPE_CHECKOUT_SESSION_OWNER_CUSTOMER FOREIGN KEY (owner_customer_id) REFERENCES sylius_customer (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("UPDATE sylius_payment_method SET code = 'stripe_checkout_be' WHERE code = 'bank_transfer_be'");
        $this->addSql("UPDATE sylius_payment_method_translation SET name = 'Carte / Bancontact (Stripe)' WHERE name = 'Virement bancaire'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE sylius_payment_method SET code = 'bank_transfer_be' WHERE code = 'stripe_checkout_be'");
        $this->addSql("UPDATE sylius_payment_method_translation SET name = 'Virement bancaire' WHERE name = 'Carte / Bancontact (Stripe)'");
        $this->addSql('ALTER TABLE app_stripe_checkout_session DROP CONSTRAINT FK_APP_STRIPE_CHECKOUT_SESSION_OWNER_CUSTOMER');
        $this->addSql('DROP TABLE app_stripe_webhook_event');
        $this->addSql('DROP TABLE app_stripe_checkout_session');
    }
}
