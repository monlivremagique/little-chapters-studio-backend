<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SignedUrlService;
use PHPUnit\Framework\TestCase;

final class SignedUrlServiceTest extends TestCase
{
    private const string SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string CONTEXT = 'photo_access';

    public function testSignAndVerifyReturnsId(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $id = 'photo-123';
        $token = $service->sign($id, self::CONTEXT, 3600);

        self::assertNotEmpty($token);
        $verified = $service->verify($token, self::CONTEXT);
        self::assertSame($id, $verified);
    }

    public function testBuildUrlContainsToken(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $url = $service->buildUrl('/api/photos/abc123', 'abc123', self::CONTEXT, 900);

        self::assertStringStartsWith('/api/photos/abc123/', $url);
        self::assertStringContainsString(rawurlencode('abc123'), $url);
    }

    public function testExpiredTokenThrows(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $token = $service->sign('id', self::CONTEXT, -1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $service->verify($token, self::CONTEXT);
    }

    public function testWrongContextThrows(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $token = $service->sign('id', self::CONTEXT, 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid signed URL signature');
        $service->verify($token, 'wrong_context');
    }

    public function testTamperedTokenThrows(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $token = $service->sign('id', self::CONTEXT, 3600);

        $parts = explode(':', $token);
        $parts[1] = '9999999999';
        $tampered = implode(':', $parts);

        $this->expectException(\RuntimeException::class);
        $service->verify($tampered, self::CONTEXT);
    }

    public function testInvalidFormatThrows(): void
    {
        $service = new SignedUrlService(self::SECRET);

        $this->expectException(\RuntimeException::class);
        $service->verify('not-a-valid-token', self::CONTEXT);
    }

    public function testDifferentSecretCannotVerify(): void
    {
        $serviceA = new SignedUrlService(self::SECRET);
        $serviceB = new SignedUrlService('f' . self::SECRET);

        $token = $serviceA->sign('id', self::CONTEXT, 3600);

        $this->expectException(\RuntimeException::class);
        $serviceB->verify($token, self::CONTEXT);
    }

    public function testMinimumTtlOneSecond(): void
    {
        $service = new SignedUrlService(self::SECRET);
        $token = $service->sign('id', self::CONTEXT, 1);

        $verified = $service->verify($token, self::CONTEXT);
        self::assertSame('id', $verified);
    }
}
