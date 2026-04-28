<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Personalization\PdfArtifact;
use App\Entity\Personalization\PersonalizationSession;
use App\Entity\Personalization\PreviewVersion;
use App\Support\OperationalEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class PersonalizationPdfRenderer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OperationalEventRecorder $operationalEventRecorder,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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

        $filename = sprintf('%s-v%d.pdf', $session->getId(), $previewVersion->getVersionNumber());
        $storagePath = sprintf('%s/%s', $directory, $filename);
        $binary = (string) $dompdf->output();
        file_put_contents($storagePath, $binary);
        $accessToken = strtolower(Uuid::v7()->toBase32());

        $artifact = new PdfArtifact(
            $session,
            $previewVersion,
            $storagePath,
            sprintf('/api/personalization/pdfs/%s', $accessToken),
            $accessToken,
            hash_file('sha256', $storagePath),
            filesize($storagePath) ?: 0,
        );

        $session->markPrintReady();
        $this->entityManager->persist($artifact);
        $this->operationalEventRecorder->record('pdf.print_ready', 'info', $session->getId(), $session->getSyliusOrderNumber(), [
            'pdf_path' => $artifact->getPublicPath(),
            'pdf_hash' => $artifact->getFileHash(),
        ]);

        return $artifact;
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
                'cover' => sprintf('<div class="cover-title">%s</div>%s', '' !== $title ? $title : $label, $image),
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
body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #232323; }
.page { page-break-after: always; width: 210mm; height: 210mm; box-sizing: border-box; padding: 16mm; text-align: center; }
.page h2 { margin: 0 0 10mm; font-size: 22pt; }
.page p { font-size: 14pt; line-height: 1.45; }
.page img { max-width: 172mm; max-height: 140mm; display: block; margin: 0 auto 8mm; object-fit: contain; }
.cover-title { margin-top: 12mm; font-size: 26pt; font-weight: bold; }
.text-page { display: flex; flex-direction: column; justify-content: center; height: 100%; }
.image-page { display: flex; justify-content: center; align-items: center; height: 100%; }
.story-page { display: block; }
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
            return $path;
        }

        return sprintf('%s/public%s', $this->projectDir, str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
