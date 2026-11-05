<?php

declare(strict_types=1);

namespace Whistleblower;

/**
 * Anonymous follow-up code: 80 random bits in Crockford base32, shown once as XXXX-XXXX-XXXX-XXXX.
 * Only its SHA-256 hash is stored, so a database leak cannot be used to follow a report.
 */
final class TrackingCode
{
    public static function generate(): string
    {
        return implode('-', str_split(Crockford::encode(random_bytes(10)), 4));
    }

    /** Returns the canonical form, or null when the input cannot be a tracking code. */
    public static function normalize(string $input): ?string
    {
        $code = strtr(strtoupper($input), ['I' => '1', 'L' => '1', 'O' => '0']);
        $code = (string) preg_replace('/[\s-]/', '', $code);

        return preg_match('/^['.Crockford::ALPHABET.']{16}$/', $code) === 1 ? $code : null;
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }
}
