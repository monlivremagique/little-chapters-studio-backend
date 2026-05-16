<?php

declare(strict_types=1);

namespace App\Service;

final class PhotoOptimizer
{
    public const int MAX_DIMENSION = 1024;
    public const int JPEG_QUALITY = 85;

    /** @return array{content:string,mimeType:string,width:int,height:int,originalWidth:int,originalHeight:int} */
    public function optimize(string $filePath): array
    {
        $imageInfo = @getimagesize($filePath);
        if (false === $imageInfo) {
            throw new \RuntimeException('Cannot read image dimensions for optimization.');
        }

        $originalWidth = (int) $imageInfo[0];
        $originalHeight = (int) $imageInfo[1];
        $mimeType = strtolower(trim((string) $imageInfo['mime']));

        $srcImage = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($filePath),
            'image/png' => @imagecreatefrompng($filePath),
            'image/webp' => @imagecreatefromwebp($filePath),
            default => throw new \RuntimeException("Unsupported mime type for optimization: {$mimeType}"),
        };

        if (false === $srcImage) {
            throw new \RuntimeException('Failed to decode source image for optimization.');
        }

        [$newWidth, $newHeight] = $this->computeResizeDimensions($originalWidth, $originalHeight);

        if ($newWidth === $originalWidth && $newHeight === $originalHeight && 'image/jpeg' === $mimeType) {
            $content = file_get_contents($filePath);
            if (false === $content) {
                throw new \RuntimeException('Failed to read already-optimized image from disk.');
            }
            @imagedestroy($srcImage);
            return [
                'content' => $content,
                'mimeType' => 'image/jpeg',
                'width' => $originalWidth,
                'height' => $originalHeight,
                'originalWidth' => $originalWidth,
                'originalHeight' => $originalHeight,
            ];
        }

        $resampled = imagecreatetruecolor($newWidth, $newHeight);
        if (false === $resampled) {
            throw new \RuntimeException('Failed to create resampled image canvas.');
        }

        imagefill($resampled, 0, 0, imagecolorallocate($resampled, 255, 255, 255));
        imagecopyresampled($resampled, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        @imagedestroy($srcImage);

        ob_start();
        $success = imagejpeg($resampled, null, self::JPEG_QUALITY);
        $content = ob_get_clean();

        if (!$success || false === $content) {
            throw new \RuntimeException('JPEG compression of resampled photo failed.');
        }

        @imagedestroy($resampled);

        return [
            'content' => $content,
            'mimeType' => 'image/jpeg',
            'width' => $newWidth,
            'height' => $newHeight,
            'originalWidth' => $originalWidth,
            'originalHeight' => $originalHeight,
        ];
    }

    /** @return array{int,int} */
    private function computeResizeDimensions(int $width, int $height): array
    {
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $ratio = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);

        return [
            (int) round($width * $ratio),
            (int) round($height * $ratio),
        ];
    }
}
