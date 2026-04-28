<?php

declare(strict_types=1);

namespace App\Entity\Personalization;

use App\Personalization\PersonalizationSessionTransitionMatrix;
use App\Entity\Customer\Customer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'app_personalization_session')]
class PersonalizationSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(name: 'book_id', length: 64)]
    private string $bookId;

    #[ORM\Column(name: 'guest_owner_token', length: 128)]
    private string $guestOwnerToken;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'owner_customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $ownerCustomer = null;

    #[ORM\Column(length: 32, enumType: PersonalizationSessionStatus::class)]
    private PersonalizationSessionStatus $status;

    #[ORM\Column]
    private int $step = 0;

    #[ORM\Column(name: 'child_name', length: 255, nullable: true)]
    private ?string $childName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $dedication = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'extra_fields', type: 'json')]
    private array $extraFields = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'approved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(name: 'cart_token_value', length: 255, nullable: true)]
    private ?string $cartTokenValue = null;

    #[ORM\Column(name: 'cart_item_id', length: 64, nullable: true)]
    private ?string $cartItemId = null;

    #[ORM\Column(name: 'sylius_order_id', nullable: true)]
    private ?int $syliusOrderId = null;

    #[ORM\Column(name: 'sylius_order_number', length: 255, nullable: true)]
    private ?string $syliusOrderNumber = null;

    /** @var Collection<int, UploadedPhoto> */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: UploadedPhoto::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $photos;

    public function __construct(string $bookId, ?string $guestOwnerToken = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->bookId = $bookId;
        $normalizedGuestOwnerToken = trim((string) $guestOwnerToken);
        $this->guestOwnerToken = $normalizedGuestOwnerToken !== '' ? $normalizedGuestOwnerToken : strtolower(Uuid::v7()->toBase32());
        $this->status = PersonalizationSessionStatus::Draft;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->photos = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBookId(): string
    {
        return $this->bookId;
    }

    public function getGuestOwnerToken(): string
    {
        return $this->guestOwnerToken;
    }

    public function getOwnerCustomer(): ?Customer
    {
        return $this->ownerCustomer;
    }

    public function hasOwnerCustomer(): bool
    {
        return null !== $this->ownerCustomer;
    }

    public function matchesGuestOwnerToken(?string $guestOwnerToken): bool
    {
        $providedToken = trim((string) $guestOwnerToken);

        return '' !== $providedToken && hash_equals($this->guestOwnerToken, $providedToken);
    }

    public function isOwnedByCustomer(Customer $customer): bool
    {
        return null !== $this->ownerCustomer && $this->ownerCustomer->getId() === $customer->getId();
    }

    public function assignOwnerCustomer(Customer $customer): void
    {
        if ($this->isOwnedByCustomer($customer)) {
            return;
        }

        $this->ownerCustomer = $customer;
        $this->touch();
    }

    public function getStatus(): PersonalizationSessionStatus
    {
        return $this->status;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function setStep(int $step): void
    {
        $this->step = max(0, $step);
        $this->touch();
    }

    public function getChildName(): ?string
    {
        return $this->childName;
    }

    public function getDedication(): ?string
    {
        return $this->dedication;
    }

    /** @return array<string, mixed> */
    public function getExtraFields(): array
    {
        return $this->extraFields;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getCartTokenValue(): ?string
    {
        return $this->cartTokenValue;
    }

    public function getCartItemId(): ?string
    {
        return $this->cartItemId;
    }

    public function getSyliusOrderId(): ?int
    {
        return $this->syliusOrderId;
    }

    public function getSyliusOrderNumber(): ?string
    {
        return $this->syliusOrderNumber;
    }

    /** @return Collection<int, UploadedPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(UploadedPhoto $photo): void
    {
        if ($this->photos->contains($photo)) {
            return;
        }

        $this->photos->add($photo);
        $this->transitionTo(PersonalizationSessionStatus::PhotoUploaded);
        $this->touch();
    }

    public function getLatestPhoto(): ?UploadedPhoto
    {
        foreach ($this->photos as $photo) {
            if (!$photo->isDeleted()) {
                return $photo;
            }
        }

        return null;
    }

    public function syncAfterPhotoDeletion(): void
    {
        if (null !== $this->getLatestPhoto()) {
            $this->touch();

            return;
        }

        $this->transitionTo($this->hasPersonalizedContent()
            ? PersonalizationSessionStatus::ContentCompleted
            : PersonalizationSessionStatus::Draft);
        $this->touch();
    }

    /** @param array<string, mixed> $extraFields */
    public function saveContent(
        ?string $childName,
        ?string $dedication,
        array $extraFields,
        int $step,
    ): void {
        $this->childName = null !== $childName ? trim($childName) : null;
        $this->dedication = null !== $dedication ? trim($dedication) : null;
        $this->extraFields = $extraFields;
        $this->step = max(0, $step);
        $this->transitionTo(PersonalizationSessionStatus::ContentCompleted);
        $this->approvedAt = null;
        $this->cartTokenValue = null;
        $this->cartItemId = null;
        $this->syliusOrderId = null;
        $this->syliusOrderNumber = null;
        $this->touch();
    }

    public function invalidateApprovalAndCommerce(PersonalizationSessionStatus $status): void
    {
        $this->transitionTo($status);
        $this->approvedAt = null;
        $this->cartTokenValue = null;
        $this->cartItemId = null;
        $this->syliusOrderId = null;
        $this->syliusOrderNumber = null;
        $this->touch();
    }

    public function markGenerationRequested(int $step = 4): void
    {
        $this->step = max($this->step, $step);
        $this->transitionTo(PersonalizationSessionStatus::GenerationRequested);
        $this->touch();
    }

    public function markGenerating(int $step = 4): void
    {
        $this->step = max($this->step, $step);
        $this->transitionTo(PersonalizationSessionStatus::Generating);
        $this->touch();
    }

    public function markPreviewPartialReady(int $step = 5): void
    {
        if ($this->status === PersonalizationSessionStatus::Approved || $this->status === PersonalizationSessionStatus::CartAttached || $this->status === PersonalizationSessionStatus::CheckoutCompleted) {
            return;
        }

        $this->step = max($this->step, $step);
        $this->transitionTo(PersonalizationSessionStatus::PreviewPartialReady);
        $this->touch();
    }

    public function markPreviewReady(int $step = 5): void
    {
        if ($this->status === PersonalizationSessionStatus::Approved || $this->status === PersonalizationSessionStatus::CartAttached || $this->status === PersonalizationSessionStatus::CheckoutCompleted) {
            return;
        }

        $this->step = max($this->step, $step);
        $this->transitionTo(PersonalizationSessionStatus::PreviewReady);
        $this->touch();
    }

    public function approve(int $step = 6): void
    {
        $this->step = max($this->step, $step);
        $this->transitionTo(PersonalizationSessionStatus::Approved);
        $this->approvedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function attachToCart(string $cartTokenValue, string $cartItemId): void
    {
        $this->cartTokenValue = trim($cartTokenValue);
        $this->cartItemId = trim($cartItemId);
        $this->transitionTo(PersonalizationSessionStatus::CartAttached);
        $this->touch();
    }

    public function detachFromCart(): void
    {
        $this->cartTokenValue = null;
        $this->cartItemId = null;
        $this->syliusOrderId = null;
        $this->syliusOrderNumber = null;
        $this->transitionTo(PersonalizationSessionStatus::Approved);
        $this->touch();
    }

    public function markCheckoutCompleted(int $syliusOrderId, string $syliusOrderNumber): void
    {
        $this->syliusOrderId = $syliusOrderId;
        $this->syliusOrderNumber = trim($syliusOrderNumber);
        $this->transitionTo(PersonalizationSessionStatus::CheckoutCompleted);
        $this->touch();
    }

    public function markPdfRendering(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::PdfRendering);
        $this->touch();
    }

    public function markPrintReady(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::PrintReady);
        $this->touch();
    }

    public function markSubmittedToGelato(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::SubmittedToGelato);
        $this->touch();
    }

    public function markInProduction(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::InProduction);
        $this->touch();
    }

    public function markShipped(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::Shipped);
        $this->touch();
    }

    public function markDelivered(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::Delivered);
        $this->touch();
    }

    public function markFailed(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::Failed);
        $this->touch();
    }

    public function markCancelled(): void
    {
        $this->transitionTo(PersonalizationSessionStatus::Cancelled);
        $this->touch();
    }

    public function isApproved(): bool
    {
        return null !== $this->approvedAt || $this->status === PersonalizationSessionStatus::Approved || $this->status === PersonalizationSessionStatus::CartAttached || $this->status === PersonalizationSessionStatus::CheckoutCompleted;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function transitionTo(PersonalizationSessionStatus $status): void
    {
        PersonalizationSessionTransitionMatrix::assertCanTransition($this->status, $status);
        $this->status = $status;
    }

    private function hasPersonalizedContent(): bool
    {
        return '' !== trim((string) $this->childName)
            || '' !== trim((string) $this->dedication)
            || [] !== $this->extraFields;
    }
}
