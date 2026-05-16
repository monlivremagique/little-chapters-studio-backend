<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PreviewVersion;
use App\Service\EncryptionService;
use App\Service\SignedUrlService;
use App\Support\CriticalAlertDispatcher;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PersonalizationPdfRenderer
{
    private const string ENCRYPTION_CONTEXT_PDF = 'pdf_artifact';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        private readonly CriticalAlertDispatcher $criticalAlertDispatcher,
        private readonly PdfPreflightValidator $pdfPreflightValidator,
        private readonly EncryptionService $encryptionService,
        private readonly SignedUrlService $signedUrlService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function render(PreviewVersion $previewVersion): PdfArtifact
    {
        /** @var PdfArtifact|null $existing */
        $existing = $this->entityManager->getRepository(PdfArtifact::class)->findOneBy([
            'previewVersion' => $previewVersion,
        ]);

        if ($existing instanceof PdfArtifact) {
            return $existing;
        }

        $session = $previewVersion->getSession();
        $session->markPdfRendering();
        $this->operationalEventRecorder->record('pdf.rendering_started', 'info', $session->getId(), $session->getSyliusOrderNumber());

        $html = $this->buildHtml($previewVersion);
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 595.28, 595.28], 'portrait');
        $dompdf->render();

        $directory = sprintf('%s/var/storage/personalizations/pdfs', $this->projectDir);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create PDF directory "%s".', $directory));
        }

        $filename = sprintf('%s-v%d.pdf.enc', $session->getId(), $previewVersion->getVersionNumber());
        $storagePath = sprintf('%s/%s', $directory, $filename);
        $binary = (string) $dompdf->output();

        $encryptedPdf = $this->encryptionService->encrypt($binary, self::ENCRYPTION_CONTEXT_PDF);
        $written = @file_put_contents($storagePath, $encryptedPdf, LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException('Failed to write encrypted PDF to private storage.');
        }
        chmod($storagePath, 0640);

        $pdfAccessToken = $this->signedUrlService->sign($session->getId(), 'pdf_access', 3600);

        $artifact = new PdfArtifact(
            $session,
            $previewVersion,
            $storagePath,
            sprintf('/api/personalization/pdfs/signed/%s', rawurlencode($pdfAccessToken)),
            $pdfAccessToken,
            hash('sha256', $encryptedPdf),
            filesize($storagePath) ?: 0,
        );
        $preflightReport = $this->pdfPreflightValidator->validate($artifact);
        if ($preflightReport['passed']) {
            $artifact->markPreflightPassed($preflightReport);
        } else {
            $artifact->markPreflightFailed($preflightReport);
            $this->entityManager->persist($artifact);
            throw new \RuntimeException(sprintf('PDF preflight failed: %s', implode('; ', $preflightReport['errors'])));
        }

        $session->markPrintReady();
        $this->entityManager->persist($artifact);
        $this->operationalEventRecorder->record('pdf.print_ready', 'info', $session->getId(), $session->getSyliusOrderNumber(), [
            'pdf_artifact_id' => (string) ($artifact->getId() ?? 'pending'),
            'pdf_path' => $artifact->getPublicPath(),
            'pdf_hash' => $artifact->getFileHash(),
        ]);

        return $artifact;
    }

    public function dispatchFailureAlert(PersonalizationSession $session, \Throwable $exception): void
    {
        $this->criticalAlertDispatcher->dispatch('pdf.render_failed', [
            'session_id' => $session->getId(),
            'order_number' => $session->getSyliusOrderNumber(),
            'payment_id' => null,
            'provider_order_id' => null,
            'message' => $exception->getMessage(),
        ]);
    }

    private function buildHtml(PreviewVersion $previewVersion): string
    {
        $payload = $previewVersion->getSnapshotPayload();
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $htmlPages = '';

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageType = (string) ($page['type'] ?? 'story');
            $text = htmlspecialchars((string) ($page['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $label = htmlspecialchars((string) ($page['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $imageUrl = htmlspecialchars($this->absolutePublicPath((string) ($page['imageUrl'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $image = '' !== $imageUrl ? sprintf('<img src="%s" alt="">', $imageUrl) : '';

            $content = match ($pageType) {
                'cover' => sprintf('<div class="cover-page"><h2 class="cover-title">%s</h2>%s</div>', '' !== $title ? $title : $label, $image),
                'dedication', 'summary' => sprintf('<div class="text-page"><h2>%s</h2><p>%s</p></div>', $label, $text),
                'backCover' => sprintf('<div class="image-page">%s</div>', $image),
                default => sprintf('<div class="story-page"><h2>%s</h2>%s<p>%s</p></div>', '' !== $title ? $title : $label, $image, $text),
            };

            $htmlPages .= sprintf('<section class="page %s">%s</section>', htmlspecialchars($pageType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);
        }

        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 0; size: 210mm 210mm; }
html, body { margin: 0; padding: 0; width: 210mm; height: 210mm; font-family: DejaVu Sans, sans-serif; color: #232323; }
.page { page-break-after: always; width: 210mm; height: 210mm; box-sizing: border-box; padding: 12mm; text-align: center; overflow: hidden; }
.page h2, .page p { margin: 0; }
.page h2 { font-size: 18pt; line-height: 1.2; }
.page p { font-size: 11pt; line-height: 1.35; }
.page img { max-width: 178mm; max-height: 128mm; display: block; margin: 0 auto; object-fit: contain; }
.cover-page, .text-page, .image-page, .story-page { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.cover-page, .story-page { gap: 6mm; }
.text-page { gap: 5mm; padding: 0 8mm; }
.image-page { padding: 0; }
.cover-title { font-size: 22pt; font-weight: bold; }
</style>
</head>
<body>
{$htmlPages}
</body>
</html>
HTML;
    }

    private function absolutePublicPath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $allowedHost = parse_url($this->defaultUri, PHP_URL_HOST);
            $imageHost = parse_url($path, PHP_URL_HOST);

            if (!is_string($allowedHost) || '' === $allowedHost || $imageHost !== $allowedHost) {
                throw new \RuntimeException('PDF image host is not allowed.');
            }

            return $path;
        }

        return sprintf('%s/public%s', $this->projectDir, str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
