<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Entity\Customer\Customer;
use App\Entity\Payment\StripeCheckoutSession;
use App\Entity\User\ShopUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StripeCheckoutOwnershipGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function assertCanAccessCheckoutSession(StripeCheckoutSession $checkoutSession, Request $request): void
    {
        $currentCustomer = $this->getCurrentCustomer();
        $guestOwnerToken = trim((string) $request->headers->get('X-Personalization-Owner-Token', ''));

        if (null !== $currentCustomer && $checkoutSession->isOwnedByCustomer($currentCustomer)) {
            return;
        }

        if ($checkoutSession->matchesGuestOwnerToken($guestOwnerToken)) {
            if (null !== $currentCustomer && !$checkoutSession->hasOwnerCustomer()) {
                $checkoutSession->assignOwnerCustomer($currentCustomer);
                $this->entityManager->flush();
            }

            return;
        }

        if (!$checkoutSession->hasOwnerCustomer() && null === $checkoutSession->getGuestOwnerToken()) {
            return;
        }

        throw new NotFoundHttpException('Stripe checkout session not found.');
    }

    private function getCurrentCustomer(): ?Customer
    {
        $user = $this->security->getUser();

        if (!$user instanceof ShopUser) {
            return null;
        }

        $customer = $user->getCustomer();

        return $customer instanceof Customer ? $customer : null;
    }
}
