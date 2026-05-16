<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EncryptionService
{
    private const int KEY_LENGTH = 32;
    private string $masterKey;

    public function __construct(
        #[Autowire('%env(PHOTO_ENCRYPTION_KEY)%')]
        string $photoEncryptionKey,
    ) {
        $key = trim($photoEncryptionKey);
        if ('' === $key) {
            throw new \RuntimeException('PHOTO_ENCRYPTION_KEY is required for production. Generate with: php -r "echo bin2hex(sodium_crypto_aead_aes256gcm_keygen());"');
        }
        $this->masterKey = sodium_hex2bin($key);
    }

    public function encrypt(string $plaintext, string $context = 'default'): string
    {
        $key = $this->deriveKey($context);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        if (sodium_crypto_aead_aes256gcm_is_available()) {
            $ciphertext = sodium_crypto_aead_aes256gcm_encrypt($plaintext, $nonce, $nonce, $key);
        } else {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $nonce, $nonce, $key);
        }

        return sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $encoded, string $context = 'default'): string
    {
        $key = $this->deriveKey($context);
        $decoded = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_ORIGINAL);

        $nonceLength = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
        $nonce = mb_substr($decoded, 0, $nonceLength, '8bit');
        $ciphertext = mb_substr($decoded, $nonceLength, null, '8bit');

        if (sodium_crypto_aead_aes256gcm_is_available()) {
            $plaintext = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $nonce, $nonce, $key);
        } else {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $nonce, $nonce, $key);
        }

        if (false === $plaintext) {
            throw new \RuntimeException('Decryption failed: invalid key, corrupted data, or tampered ciphertext.');
        }

        sodium_memzero($key);

        return $plaintext;
    }

    public function generateKey(): string
    {
        if (sodium_crypto_aead_aes256gcm_is_available()) {
            return sodium_bin2hex(sodium_crypto_aead_aes256gcm_keygen());
        }
        return sodium_bin2hex(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    private function deriveKey(string $context): string
    {
        return sodium_crypto_generichash(
            $context,
            $this->masterKey,
            self::KEY_LENGTH,
        );
    }
}
