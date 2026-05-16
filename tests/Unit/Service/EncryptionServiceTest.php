<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    private const string CONTEXT = 'test_context';

    private function createService(): EncryptionService
    {
        return new EncryptionService(bin2hex(random_bytes(32)));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $service = $this->createService();
        $plaintext = 'Hello RGPD world!';
        $encrypted = $service->encrypt($plaintext, self::CONTEXT);
        $decrypted = $service->decrypt($encrypted, self::CONTEXT);
        self::assertSame($plaintext, $decrypted);
    }

    public function testDifferentContextProducesDifferentCiphertext(): void
    {
        $service = $this->createService();
        $plaintext = 'Same text';
        $ctx1 = $service->encrypt($plaintext, 'context_a');
        $ctx2 = $service->encrypt($plaintext, 'context_b');
        self::assertNotSame($ctx1, $ctx2);
    }

    public function testDecryptWithWrongContextFails(): void
    {
        $service = $this->createService();
        $encrypted = $service->encrypt('secret', 'correct_context');
        $this->expectException(\RuntimeException::class);
        $service->decrypt($encrypted, 'wrong_context');
    }

    public function testDecryptWithTamperedCiphertextFails(): void
    {
        $service = $this->createService();
        $encrypted = $service->encrypt('secret', self::CONTEXT);
        $tampered = $encrypted;
        $tampered[10] = ~$tampered[10];
        $this->expectException(\RuntimeException::class);
        $service->decrypt($tampered, self::CONTEXT);
    }

    public function testDecryptWithEmptyStringFails(): void
    {
        $service = $this->createService();
        $this->expectException(\SodiumException::class);
        $service->decrypt('', self::CONTEXT);
    }

    public function testKeyGenerationProducesValidHexKey(): void
    {
        $service = $this->createService();
        $key = $service->generateKey();
        self::assertSame(64, strlen($key));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function testEncryptLargePayload(): void
    {
        $service = $this->createService();
        $large = str_repeat('A', 1_000_000);
        $encrypted = $service->encrypt($large, self::CONTEXT);
        $decrypted = $service->decrypt($encrypted, self::CONTEXT);
        self::assertSame($large, $decrypted);
    }
}
