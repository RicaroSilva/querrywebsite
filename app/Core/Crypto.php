<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated symmetric encryption (libsodium XSalsa20-Poly1305) used to
 * store external database credentials at rest. The key lives only in .env.
 */
final class Crypto
{
    private static function key(): string
    {
        $raw = (string) config('app.key');
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY is missing or invalid. Run: php bin/console key:generate');
        }
        return $key;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(sodium_crypto_secretbox_keygen());
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }
        $bin = base64_decode($payload, true);
        if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted encrypted value.');
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt value (was APP_KEY changed?).');
        }
        return $plain;
    }
}
