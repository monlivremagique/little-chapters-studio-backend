<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CriticalAlertDispatcher
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        #[Autowire('%env(default::ALERT_EMAIL_TO)%')]
        private readonly ?string $alertEmailTo,
        #[Autowire('%env(default::ALERT_EMAIL_FROM)%')]
        private readonly ?string $alertEmailFrom,
        #[Autowire('%env(default::ALERT_WEBHOOK_URL)%')]
        private readonly ?string $alertWebhookUrl,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function dispatch(string $type, array $context = []): void
    {
        $normalizedContext = $this->normalizeContext($context);
        $subject = sprintf('[Little Chapters][CRITICAL] %s', $type);
        $body = json_encode($normalizedContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $emailTo = trim((string) $this->alertEmailTo);
            $emailFrom = trim((string) $this->alertEmailFrom);

            if ('' !== $emailTo && '' !== $emailFrom) {
                $this->mailer->send((new Email())
                    ->from($emailFrom)
                    ->to($emailTo)
                    ->subject($subject)
                    ->text($body ?: $subject));
            }

            $webhookUrl = trim((string) $this->alertWebhookUrl);

            if ('' !== $webhookUrl) {
                $this->httpClient->request('POST', $webhookUrl, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'type' => $type,
                        'context' => $normalizedContext,
                    ],
                ])->getStatusCode();
            }

            $this->operationalEventRecorder->record('alert.dispatched', 'warning', $normalizedContext['session_id'], $normalizedContext['order_number'], [
                'alert_type' => $type,
                'payment_id' => $normalizedContext['payment_id'],
                'provider_order_id' => $normalizedContext['provider_order_id'],
                'channel_email' => '' !== trim((string) $this->alertEmailTo),
                'channel_webhook' => '' !== trim((string) $this->alertWebhookUrl),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('critical.alert.dispatch_failed', [
                'alert_type' => $type,
                'exception' => $exception->getMessage(),
            ] + $normalizedContext);
        }
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function normalizeContext(array $context): array
    {
        return [
            'session_id' => isset($context['session_id']) ? (string) $context['session_id'] : null,
            'order_number' => isset($context['order_number']) ? (string) $context['order_number'] : null,
            'payment_id' => isset($context['payment_id']) ? (string) $context['payment_id'] : null,
            'provider_order_id' => isset($context['provider_order_id']) ? (string) $context['provider_order_id'] : null,
            'provider_job_id' => isset($context['provider_job_id']) ? (string) $context['provider_job_id'] : null,
            'pdf_artifact_id' => isset($context['pdf_artifact_id']) ? (string) $context['pdf_artifact_id'] : null,
            'message' => isset($context['message']) ? (string) $context['message'] : null,
            'extra' => $context,
        ];
    }
}
