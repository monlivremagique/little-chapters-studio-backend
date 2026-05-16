<?php

declare(strict_types=1);

namespace App\Personalization;

use App\Entity\Customer\Customer;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\User\ShopUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class PersonalizationSessionOwnershipGuard
{
    public const HEADER_NAME = 'X-Personalization-Owner-Token';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveOrCreateGuestOwnerToken(Request $request): string
    {
        $providedToken = $this->extractGuestOwnerToken($request);

        if (null !== $providedToken) {
            return $providedToken;
        }

        return strtolower(Uuid::v7()->toBase32());
    }

    public function assignOwnerOnCreate(PersonalizationSession $session): void
    {
        $customer = $this->getCurrentCustomer();

        if ($customer instanceof Customer) {
            $session->assignOwnerCustomer($customer);
        }
    }

    public function assertCanAccessSession(PersonalizationSession $session, Request $request): void
    {
        $customer = $this->getCurrentCustomer();

        if ($customer instanceof Customer && $session->isOwnedByCustomer($customer)) {
            return;
        }

        $guestOwnerToken = $this->extractGuestOwnerToken($request);

        if ($session->matchesGuestOwnerToken($guestOwnerToken)) {
            if ($customer instanceof Customer && !$session->hasOwnerCustomer()) {
                $session->assignOwnerCustomer($customer);
                $this->entityManager->flush();
            }

            return;
        }

        throw new NotFoundHttpException('Personalization session not found.');
    }

    /**
     * @param iterable<PersonalizationSession> $sessions
     */
    public function assertCanAccessSessions(iterable $sessions, Request $request): void
    {
        foreach ($sessions as $session) {
            $this->assertCanAccessSession($session, $request);
        }
    }

    private function extractGuestOwnerToken(Request $request): ?string
    {
        $token = trim((string) $request->headers->get(self::HEADER_NAME, ''));

        return '' !== $token ? $token : null;
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
