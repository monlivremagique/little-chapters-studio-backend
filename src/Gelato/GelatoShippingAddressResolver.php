<?php

declare(strict_types=1);

namespace App\Gelato;

use App\Entity\Personalization\PersonalizationSession;
use Doctrine\DBAL\Connection;

final class GelatoShippingAddressResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, string> */
    public function resolveForSession(PersonalizationSession $session): array
    {
        $orderId = $session->getSyliusOrderId();
        $orderNumber = $session->getSyliusOrderNumber();

        if (null === $orderId || null === $orderNumber) {
            throw new \RuntimeException('Cannot submit Gelato order without a completed Sylius order.');
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    a.first_name,
    a.last_name,
    a.company,
    a.street,
    a.city,
    a.postcode,
    a.country_code,
    a.province_code,
    a.province_name,
    a.phone_number,
    c.email
FROM sylius_order o
INNER JOIN sylius_address a ON a.id = o.shipping_address_id
LEFT JOIN sylius_customer c ON c.id = o.customer_id
WHERE o.id = :orderId
  AND o.number = :orderNumber
LIMIT 1
SQL,
            [
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
            ],
        );

        if (false === $row) {
            throw new \RuntimeException('Cannot submit Gelato order without a persisted shipping address.');
        }

        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone_number'] ?? ''));

        if ('' === $email) {
            $email = $session->getOwnerCustomer()?->getEmail() ?? '';
        }

        $address = [
            'companyName' => trim((string) ($row['company'] ?? '')),
            'firstName' => trim((string) $row['first_name']),
            'lastName' => trim((string) $row['last_name']),
            'addressLine1' => trim((string) $row['street']),
            'city' => trim((string) $row['city']),
            'postCode' => trim((string) $row['postcode']),
            'country' => mb_strtoupper(trim((string) $row['country_code'])),
            'email' => $email,
            'phone' => $phone,
        ];

        $state = trim((string) ($row['province_code'] ?? $row['province_name'] ?? ''));

        if ('' !== $state) {
            $address['state'] = $state;
        }

        foreach (['firstName', 'lastName', 'addressLine1', 'city', 'postCode', 'country', 'email'] as $requiredField) {
            if ('' === $address[$requiredField]) {
                throw new \RuntimeException(sprintf('Cannot submit Gelato order because shipping address field "%s" is missing.', $requiredField));
            }
        }

        return array_filter($address, static fn (string $value): bool => '' !== $value);
    }
}
