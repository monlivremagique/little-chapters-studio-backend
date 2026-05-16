<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        if ($this->getEnvironment() !== 'prod') {
            return;
        }

        $missing = [];
        foreach (self::requiredProductionEnv() as $name => $validator) {
            $value = $this->readEnv($name);

            if (!$validator($value)) {
                $missing[] = $name;
            }
        }

        if ([] !== $missing) {
            throw new \RuntimeException(sprintf(
                'Production environment is not go-live ready. Configure valid values for: %s',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * @return array<string, callable(?string): bool>
     */
    private static function requiredProductionEnv(): array
    {
        $present = static fn (?string $value): bool => null !== $value
            && '' !== trim($value)
            && !str_starts_with(trim($value), '__REQUIRED_')
            && !str_contains($value, 'À COMPLÉTER')
            && !str_contains($value, 'TO COMPLETE')
            && !str_contains($value, 'IN TE VULLEN');

        $httpsUrl = static fn (?string $value): bool => $present($value) && str_starts_with(trim((string) $value), 'https://');
        $secret = static fn (?string $value): bool => $present($value)
            && !in_array(trim((string) $value), ['s$cretf0rt3st', 'phase1-local-secret', 'EDITME'], true)
            && strlen(trim((string) $value)) >= 32;

        return [
            'APP_SECRET' => $secret,
            'DATABASE_URL' => $present,
            'MESSENGER_TRANSPORT_DSN' => static fn (?string $value): bool => $present($value) && trim((string) $value) !== 'sync://',
            'MAILER_DSN' => static fn (?string $value): bool => $present($value)
                && !str_starts_with(trim((string) $value), 'null://')
                && !str_contains(trim((string) $value), 'mailhog'),
            'ALERT_EMAIL_FROM' => $present,
            'DEFAULT_URI' => $httpsUrl,
            'FRONTEND_BASE_URL' => $httpsUrl,
            'JWT_SECRET_KEY' => $present,
            'JWT_PUBLIC_KEY' => $present,
            'JWT_PASSPHRASE' => $secret,
            'STRIPE_SECRET_KEY' => $present,
            'STRIPE_WEBHOOK_SECRET' => $present,
            'REPLICATE_API_TOKEN' => $present,
            'REPLICATE_MODEL' => $present,
            'GELATO_API_KEY' => $present,
            'GELATO_WEBHOOK_SECRET' => $present,
            'GELATO_PRODUCT_UID' => $present,
            'GELATO_SHIPMENT_METHOD_UID' => $present,
            'SUPPORT_OPERATIONS_TOKEN' => $secret,
            'PDF_STORAGE_PERSISTENT' => static fn (?string $value): bool => trim((string) $value) === 'true',
        ];
    }

    private function readEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return false === $value || null === $value ? null : (string) $value;
    }
}
