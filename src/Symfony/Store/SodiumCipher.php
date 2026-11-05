<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Store;

use RuntimeException;

/** Authenticated encryption (XSalsa20-Poly1305) with a key derived from the configured secret. */
final readonly class SodiumCipher
{
    private string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new RuntimeException('The whistleblower encryption key must not be empty.');
        }
        $this->key = sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }
        $raw = str_starts_with($stored, 'v1:') ? base64_decode(substr($stored, 3), true) : false;
        $plain = $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? false : sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if ($plain === false) {
            throw new RuntimeException('Cannot decrypt a stored value: wrong key or corrupted data.');
        }

        return $plain;
    }
}
