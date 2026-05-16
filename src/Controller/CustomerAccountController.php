<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer\Customer;
use App\Entity\User\ShopUser;
use App\RateLimiting\RateLimit;
use App\Trait\ApiErrorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerAccountController
{
    use ApiErrorTrait;
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtTokenManager,
        private readonly Security $security,
    ) {
    }

    #[RateLimit('auth', 'ip')]
    #[Route(
        '/api/v2/shop/customers/register',
        name: 'app_custom_customers_register',
        methods: ['POST'],
        defaults: ['_profiler_collect' => false],
    )]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->readJsonPayload($request);
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $password = trim((string) ($payload['password'] ?? ''));
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));

        if ('' === $email || '' === $password || '' === $firstName || '' === $lastName) {
            return $this->errorResponse('The "email", "password", "firstName" and "lastName" fields are required.', Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('The email address is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($password) < 8) {
            return $this->errorResponse('The password must contain at least 8 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existingCustomer = $this->entityManager->getRepository(Customer::class)->findOneBy(['emailCanonical' => $email]);

        if ($existingCustomer instanceof Customer) {
            return $this->errorResponse('A customer account already exists for this email address.', Response::HTTP_CONFLICT);
        }

        $customer = new Customer();
        $customer->setEmail($email);
        $customer->setEmailCanonical($email);
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);

        $user = new ShopUser();
        $user->setCustomer($customer);
        $user->setUsername($email);
        $user->setUsernameCanonical($email);
        $user->setPlainPassword($password);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setEnabled(true);
        $user->setVerifiedAt(new \DateTimeImmutable());

        $this->entityManager->persist($customer);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'token' => $this->jwtTokenManager->create($user),
            'customer' => $this->normalizeCustomer($customer),
        ], Response::HTTP_CREATED);
    }

    #[Route(
        '/api/v2/shop/account/me',
        name: 'app_custom_customers_me',
        methods: ['GET'],
        defaults: ['_profiler_collect' => false],
    )]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof ShopUser || null === $user->getCustomer()) {
            return $this->errorResponse('Customer authentication is required.', Response::HTTP_UNAUTHORIZED);
        }

        /** @var Customer $customer */
        $customer = $user->getCustomer();

        return new JsonResponse($this->normalizeCustomer($customer));
    }

    /** @return array<string, mixed> */
    private function readJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function errorResponse(string $message, int $statusCode): JsonResponse
    {
        return $this->errorFromException(new \RuntimeException($message), $statusCode);
    }

    /** @return array<string, mixed> */
    private function normalizeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'fullName' => $customer->getFullName(),
        ];
    }
}
