<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PhotoOptimizer;
use PHPUnit\Framework\TestCase;

final class PhotoOptimizerTest extends TestCase
{
    private PhotoOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new PhotoOptimizer();
    }

    public function testOptimizeJpegKeepsValidImage(): void
    {
        $source = $this->createTestImage(800, 600, 'image/jpeg');
        $result = $this->optimizer->optimize($source);
        $info = getimagesizefromstring($result['content']);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
        self::assertLessThanOrEqual(PhotoOptimizer::MAX_DIMENSION, $result['width']);
        self::assertLessThanOrEqual(PhotoOptimizer::MAX_DIMENSION, $result['height']);
    }

    public function testOptimizePngProducesJpeg(): void
    {
        $source = $this->createTestImage(400, 300, 'image/png');
        $result = $this->optimizer->optimize($source);
        $info = getimagesizefromstring($result['content']);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testOptimizeWebpProducesJpeg(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            self::markTestSkipped('WebP support not available in this PHP build.');
        }
        $source = $this->createTestImage(500, 500, 'image/webp', 'webp');
        $result = $this->optimizer->optimize($source);
        $info = getimagesizefromstring($result['content']);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testDimensionsAreScaledDownWhenExceedingMax(): void
    {
        $source = $this->createTestImage(2048, 1024, 'image/jpeg');
        $result = $this->optimizer->optimize($source);
        self::assertLessThanOrEqual(PhotoOptimizer::MAX_DIMENSION, $result['width']);
        self::assertLessThanOrEqual(PhotoOptimizer::MAX_DIMENSION, $result['height']);
    }

    public function testOriginalDimensionsArePreservedInResult(): void
    {
        $source = $this->createTestImage(100, 200, 'image/jpeg');
        $result = $this->optimizer->optimize($source);
        self::assertSame(100, $result['originalWidth']);
        self::assertSame(200, $result['originalHeight']);
    }

    public function testNonImageFileThrowsException(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'photo_test_');
        file_put_contents($temp, 'not an image');
        $this->expectException(\RuntimeException::class);
        try {
            $this->optimizer->optimize($temp);
        } finally {
            @unlink($temp);
        }
    }

    public function testImageSmallerThanMaxIsNotUpscaled(): void
    {
        $source = $this->createTestImage(50, 80, 'image/jpeg');
        $result = $this->optimizer->optimize($source);
        self::assertSame(50, $result['width']);
        self::assertSame(80, $result['height']);
    }

    /** @param non-empty-string $format */
    private function createTestImage(int $width, int $height, string $mimeType, string $format = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 128, 64));
        $path = tempnam(sys_get_temp_dir(), 'photo_test_') . '.' . $format;

        match ($format) {
            'jpeg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path, 9),
            'webp' => imagewebp($image, $path, 90),
            default => throw new \InvalidArgumentException('Unsupported format'),
        };

        imagedestroy($image);

        return $path;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
