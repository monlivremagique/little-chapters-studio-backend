<?php

declare(strict_types=1);

namespace App\Production;

use App\Entity\Personalization\PersonalizationSession;
use App\Message\TriggerFulfillmentMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class PostPaymentProductionOrchestrator
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrderConfirmationMailer $confirmationMailer,
    ) {
    }

    /** @param list<PersonalizationSession> $sessions */
    public function processPaidSessions(array $sessions): void
    {
        foreach ($sessions as $session) {
            $this->processPaidSession($session);
        }
    }

    public function processPaidSession(PersonalizationSession $session): void
    {
        // Send confirmation email immediately — non-blocking, idempotent.
        // Customer is informed even if PDF/Gelato pipeline is still pending.
        $this->confirmationMailer->sendIfNotSent($session);

        // Dispatch async fulfillment — retried up to 3 times by Symfony Messenger
        // with exponential backoff (5 min / 15 min / 60 min) if Gelato or PDF fails.
        $orderNumber = $session->getSyliusOrderNumber() ?? '';

        if ('' === $orderNumber) {
            return;
        }

        $this->messageBus->dispatch(new TriggerFulfillmentMessage($session->getId(), $orderNumber));
    }
}
