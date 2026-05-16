<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Personalization\PdfArtifact;

final class PdfPreflightValidator
{
    /** @return array{passed:bool,errors:list<string>,checks:array<string,mixed>} */
    public function validate(PdfArtifact $artifact): array
    {
        $errors = [];
        $checks = [];
        $path = $artifact->getStoragePath();

        $checks['storagePath'] = $path;
        $checks['exists'] = is_file($path);
        $checks['readable'] = is_readable($path);

        if (!is_file($path) || !is_readable($path)) {
            $errors[] = 'PDF file is missing or unreadable.';

            return ['passed' => false, 'errors' => $errors, 'checks' => $checks];
        }

        $binary = (string) file_get_contents($path);
        $fileSize = strlen($binary);
        $snapshot = $artifact->getPreviewVersion()->getSnapshotPayload();
        $expectedPageCount = count(is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : []);
        $actualPageCount = preg_match_all('/\/Type\s*\/Page\b/', $binary);

        $checks['fileSize'] = $fileSize;
        $checks['sha256'] = hash_file('sha256', $path);
        $checks['startsWithPdfHeader'] = str_starts_with($binary, '%PDF-');
        $checks['expectedPageCount'] = $expectedPageCount;
        $checks['detectedPageCount'] = $actualPageCount;
        $checks['square210mmMediaBoxPresent'] = str_contains($binary, '595.28') || str_contains($binary, '595.275');
        $checks['publicPath'] = $artifact->getPublicPath();

        if ($fileSize < 1024) {
            $errors[] = 'PDF file is unexpectedly small.';
        }

        if (!$checks['startsWithPdfHeader']) {
            $errors[] = 'PDF header is invalid.';
        }

        if ($expectedPageCount <= 0) {
            $errors[] = 'Approved preview snapshot has no pages.';
        }

        if ($actualPageCount < $expectedPageCount) {
            $errors[] = sprintf('PDF page count %d is lower than expected %d.', $actualPageCount, $expectedPageCount);
        }

        if ('' === trim($artifact->getPublicPath())) {
            $errors[] = 'PDF public path is empty.';
        }

        return [
            'passed' => [] === $errors,
            'errors' => $errors,
            'checks' => $checks,
        ];
    }
}
