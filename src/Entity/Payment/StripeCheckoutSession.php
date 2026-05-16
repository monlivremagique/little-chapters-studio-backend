<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use App\Entity\Customer\Customer;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_stripe_checkout_session')]
#[ORM\UniqueConstraint(name: 'uniq_app_stripe_checkout_session_provider_id', columns: ['provider_session_id'])]
#[ORM\Index(name: 'idx_app_stripe_checkout_session_order_number', columns: ['sylius_order_number'])]
#[ORM\Index(name: 'idx_app_stripe_checkout_session_payment_id', columns: ['sylius_payment_id'])]
class StripeCheckoutSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'provider_session_id', type: 'string', length: 255)]
    private string $providerSessionId;

    #[ORM\Column(name: 'provider_payment_intent_id', type: 'string', length: 255, nullable: true)]
    private ?string $providerPaymentIntentId = null;

    #[ORM\Column(name: 'sylius_order_id', type: 'integer')]
    private int $syliusOrderId;

    #[ORM\Column(name: 'sylius_order_number', type: 'string', length: 64)]
    private string $syliusOrderNumber;

    #[ORM\Column(name: 'sylius_order_token_value', type: 'string', length: 255)]
    private string $syliusOrderTokenValue;

    #[ORM\Column(name: 'sylius_payment_id', type: 'integer')]
    private int $syliusPaymentId;

    #[ORM\Column(name: 'amount_total', type: 'integer')]
    private int $amountTotal;

    #[ORM\Column(name: 'currency_code', type: 'string', length: 3)]
    private string $currencyCode;

    #[ORM\Column(name: 'checkout_url', type: 'text', nullable: true)]
    private ?string $checkoutUrl = null;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = StripeCheckoutSessionStatus::Open->value;

    #[ORM\Column(name: 'payment_status', type: 'string', length: 32)]
    private string $paymentStatus = 'unpaid';

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'guest_owner_token', type: 'string', length: 128, nullable: true)]
    private ?string $guestOwnerToken = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'owner_customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $ownerCustomer = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'expired_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    public function __construct(
        string $providerSessionId,
        int $syliusOrderId,
        string $syliusOrderNumber,
        string $syliusOrderTokenValue,
        int $syliusPaymentId,
        int $amountTotal,
        string $currencyCode,
        ?Customer $ownerCustomer = null,
        ?string $guestOwnerToken = null,
    ) {
        $now = new \DateTimeImmutable();

        $this->providerSessionId = $providerSessionId;
        $this->syliusOrderId = $syliusOrderId;
        $this->syliusOrderNumber = $syliusOrderNumber;
        $this->syliusOrderTokenValue = $syliusOrderTokenValue;
        $this->syliusPaymentId = $syliusPaymentId;
        $this->amountTotal = $amountTotal;
        $this->currencyCode = mb_strtoupper($currencyCode);
        $this->ownerCustomer = $ownerCustomer;
        $this->guestOwnerToken = null !== $guestOwnerToken ? trim($guestOwnerToken) : null;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderSessionId(): string
    {
        return $this->providerSessionId;
    }

    public function getProviderPaymentIntentId(): ?string
    {
        return $this->providerPaymentIntentId;
    }

    public function getSyliusOrderId(): int
    {
        return $this->syliusOrderId;
    }

    public function getSyliusOrderNumber(): string
    {
        return $this->syliusOrderNumber;
    }

    public function getSyliusOrderTokenValue(): string
    {
        return $this->syliusOrderTokenValue;
    }

    public function getSyliusPaymentId(): int
    {
        return $this->syliusPaymentId;
    }

    public function getAmountTotal(): int
    {
        return $this->amountTotal;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPaymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getGuestOwnerToken(): ?string
    {
        return $this->guestOwnerToken;
    }

    public function matchesGuestOwnerToken(?string $guestOwnerToken): bool
    {
        return null !== $this->guestOwnerToken
            && null !== $guestOwnerToken
            && hash_equals($this->guestOwnerToken, trim($guestOwnerToken));
    }

    public function getOwnerCustomer(): ?Customer
    {
        return $this->ownerCustomer;
    }

    public function hasOwnerCustomer(): bool
    {
        return null !== $this->ownerCustomer;
    }

    public function isOwnedByCustomer(Customer $customer): bool
    {
        return null !== $this->ownerCustomer && $this->ownerCustomer->getId() === $customer->getId();
    }

    public function assignOwnerCustomer(Customer $customer): void
    {
        $this->ownerCustomer = $customer;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function markOpen(string $checkoutUrl, string $paymentStatus = 'unpaid', ?string $providerPaymentIntentId = null): void
    {
        $this->checkoutUrl = $checkoutUrl;
        $this->status = StripeCheckoutSessionStatus::Open->value;
        $this->paymentStatus = $paymentStatus;
        $this->providerPaymentIntentId = $providerPaymentIntentId;
        $this->errorMessage = null;
        $this->expiredAt = null;
        $this->touch();
    }

    public function markCompleted(string $paymentStatus = 'paid', ?string $providerPaymentIntentId = null): void
    {
        $now = new \DateTimeImmutable();

        $this->status = StripeCheckoutSessionStatus::Complete->value;
        $this->paymentStatus = $paymentStatus;
        $this->providerPaymentIntentId = $providerPaymentIntentId;
        $this->errorMessage = null;
        $this->completedAt = $now;
        $this->updatedAt = $now;
    }

    public function markExpired(string $paymentStatus = 'unpaid', ?string $providerPaymentIntentId = null): void
    {
        $now = new \DateTimeImmutable();

        $this->status = StripeCheckoutSessionStatus::Expired->value;
        $this->paymentStatus = $paymentStatus;
        $this->providerPaymentIntentId = $providerPaymentIntentId;
        $this->expiredAt = $now;
        $this->updatedAt = $now;
    }

    public function markFailed(string $errorMessage, string $paymentStatus = 'unpaid', ?string $providerPaymentIntentId = null): void
    {
        $this->status = StripeCheckoutSessionStatus::Failed->value;
        $this->paymentStatus = $paymentStatus;
        $this->providerPaymentIntentId = $providerPaymentIntentId;
        $this->errorMessage = trim($errorMessage);
        $this->touch();
    }

    public function isPaid(): bool
    {
        return $this->status === StripeCheckoutSessionStatus::Complete->value && $this->paymentStatus === 'paid';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
