<?php

declare(strict_types=1);

namespace App\Production;

use App\Entity\Personalization\PersonalizationSession;
use App\Support\OperationalEventRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a one-time order confirmation email to the customer after payment.
 * Idempotent: checks PersonalizationSession.orderConfirmationEmailSent before sending.
 * Never throws — logs failures and returns false so PDF/Gelato pipeline continues.
 */
final class OrderConfirmationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        #[Autowire('%env(default::ALERT_EMAIL_FROM)%')]
        private readonly ?string $fromEmail,
        #[Autowire('%env(FRONTEND_BASE_URL)%')]
        private readonly string $frontendBaseUrl,
    ) {
    }

    /**
     * Send the confirmation email for a paid session.
     * Returns true if sent (or already sent), false on error.
     */
    public function sendIfNotSent(PersonalizationSession $session): bool
    {
        if ($session->isOrderConfirmationEmailSent()) {
            return true;
        }

        $from = trim((string) $this->fromEmail);
        if ('' === $from) {
            $this->logger->warning('order_confirmation.skipped_no_from', [
                'session_id' => $session->getId(),
                'reason' => 'ALERT_EMAIL_FROM not configured',
            ]);

            return false;
        }

        $customerEmail = $this->resolveCustomerEmail($session);
        if (null === $customerEmail) {
            $this->logger->warning('order_confirmation.skipped_no_customer_email', [
                'session_id' => $session->getId(),
                'order_number' => $session->getSyliusOrderNumber(),
            ]);

            return false;
        }

        try {
            return $this->connection->transactional(function () use ($session, $customerEmail, $from): bool {
                $this->acquireEmailLock($session);

                $this->entityManager->refresh($session);

                if ($session->isOrderConfirmationEmailSent()) {
                    return true;
                }

                $email = $this->buildEmail($session, $customerEmail, $from);
                $this->mailer->send($email);
                $session->markOrderConfirmationEmailSent();
                $this->entityManager->flush();

                $this->operationalEventRecorder->record('email.order_confirmation_sent', 'info', $session->getId(), $session->getSyliusOrderNumber(), [
                    'recipient' => $customerEmail,
                ]);

                return true;
            });
        } catch (\Throwable $exception) {
            $this->logger->error('order_confirmation.send_failed', [
                'session_id' => $session->getId(),
                'order_number' => $session->getSyliusOrderNumber(),
                'recipient' => $customerEmail,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function acquireEmailLock(PersonalizationSession $session): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:lockKey))',
            ['lockKey' => sprintf('order-confirmation-email:%s', $session->getId())],
        );
    }

    private function buildEmail(PersonalizationSession $session, string $toEmail, string $fromEmail): Email
    {
        $locale = $session->getBookLocale();
        $orderNumber = $session->getSyliusOrderNumber() ?? '—';
        $childName = $session->getChildName() ?? '';
        $accountUrl = rtrim($this->frontendBaseUrl, '/') . '/compte/commandes';

        [$subject, $html] = match ($locale) {
            'en' => $this->buildEnglishEmail($orderNumber, $childName, $accountUrl),
            'nl' => $this->buildDutchEmail($orderNumber, $childName, $accountUrl),
            default => $this->buildFrenchEmail($orderNumber, $childName, $accountUrl),
        };

        return (new Email())
            ->from(new Address($fromEmail, 'Mon Livre Magique'))
            ->to($toEmail)
            ->subject($subject)
            ->html($html)
            ->text(strip_tags(str_replace(['</p>', '</li>', '<br>'], "\n", $html)));
    }

    /** @return array{string, string} [subject, html] */
    private function buildFrenchEmail(string $orderNumber, string $childName, string $accountUrl): array
    {
        $for = '' !== $childName ? " pour <strong>{$childName}</strong>" : '';
        $subject = "Votre commande {$orderNumber} est confirmée — Mon Livre Magique";
        $html = <<<HTML
        <div style="font-family:Georgia,serif;max-width:560px;margin:0 auto;color:#2d2d2d">
          <h1 style="font-size:22px;margin-bottom:8px">Merci pour votre commande&nbsp;!</h1>
          <p>Votre commande <strong>{$orderNumber}</strong>{$for} a bien été enregistrée et le paiement confirmé.</p>
          <p>Nous préparons votre livre personnalisé. La fabrication prend généralement <strong>3 à 5 jours ouvrés</strong>, suivie de la livraison sous 2 à 4 jours.</p>
          <p>Vous pouvez suivre l'avancement de votre commande depuis votre espace client&nbsp;:</p>
          <p><a href="{$accountUrl}" style="background:#c8a96e;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block">Suivre ma commande</a></p>
          <hr style="margin:24px 0;border:none;border-top:1px solid #e5e5e5">
          <p style="font-size:13px;color:#888">Mon Livre Magique — www.monlivremagique.be<br>Des questions ? Répondez à cet email ou contactez-nous sur le site.</p>
        </div>
        HTML;

        return [$subject, $html];
    }

    /** @return array{string, string} */
    private function buildEnglishEmail(string $orderNumber, string $childName, string $accountUrl): array
    {
        $for = '' !== $childName ? " for <strong>{$childName}</strong>" : '';
        $subject = "Your order {$orderNumber} is confirmed — Mon Livre Magique";
        $html = <<<HTML
        <div style="font-family:Georgia,serif;max-width:560px;margin:0 auto;color:#2d2d2d">
          <h1 style="font-size:22px;margin-bottom:8px">Thank you for your order!</h1>
          <p>Your order <strong>{$orderNumber}</strong>{$for} has been placed and your payment confirmed.</p>
          <p>We are preparing your personalised book. Production usually takes <strong>3 to 5 business days</strong>, followed by delivery in 2 to 4 days.</p>
          <p>You can track your order from your account:</p>
          <p><a href="{$accountUrl}" style="background:#c8a96e;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block">Track my order</a></p>
          <hr style="margin:24px 0;border:none;border-top:1px solid #e5e5e5">
          <p style="font-size:13px;color:#888">Mon Livre Magique — www.monlivremagique.be<br>Questions? Reply to this email or contact us on the website.</p>
        </div>
        HTML;

        return [$subject, $html];
    }

    /** @return array{string, string} */
    private function buildDutchEmail(string $orderNumber, string $childName, string $accountUrl): array
    {
        $for = '' !== $childName ? " voor <strong>{$childName}</strong>" : '';
        $subject = "Uw bestelling {$orderNumber} is bevestigd — Mon Livre Magique";
        $html = <<<HTML
        <div style="font-family:Georgia,serif;max-width:560px;margin:0 auto;color:#2d2d2d">
          <h1 style="font-size:22px;margin-bottom:8px">Bedankt voor uw bestelling!</h1>
          <p>Uw bestelling <strong>{$orderNumber}</strong>{$for} is geplaatst en uw betaling bevestigd.</p>
          <p>We bereiden uw gepersonaliseerde boek voor. De productie duurt doorgaans <strong>3 tot 5 werkdagen</strong>, gevolgd door levering in 2 tot 4 dagen.</p>
          <p>U kunt uw bestelling volgen via uw account:</p>
          <p><a href="{$accountUrl}" style="background:#c8a96e;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block">Mijn bestelling volgen</a></p>
          <hr style="margin:24px 0;border:none;border-top:1px solid #e5e5e5">
          <p style="font-size:13px;color:#888">Mon Livre Magique — www.monlivremagique.be<br>Vragen? Beantwoord deze e-mail of neem contact op via de website.</p>
        </div>
        HTML;

        return [$subject, $html];
    }

    private function resolveCustomerEmail(PersonalizationSession $session): ?string
    {
        $orderId = $session->getSyliusOrderId();
        if (null === $orderId) {
            return null;
        }

        $email = $this->connection->fetchOne(
            <<<'SQL'
SELECT COALESCE(c.email, p.details #>> '{stripe,customer_email}')
FROM sylius_order o
LEFT JOIN sylius_customer c ON c.id = o.customer_id
LEFT JOIN sylius_payment p ON p.order_id = o.id
WHERE o.id = :orderId
ORDER BY p.id DESC NULLS LAST
LIMIT 1
SQL,
            ['orderId' => $orderId],
        );

        return is_string($email) && '' !== trim($email) ? trim($email) : null;
    }
}
