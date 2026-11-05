<?php

declare(strict_types=1);

namespace Whistleblower;

/** Crockford base32, the alphabet shared by tracking codes and ULIDs. */
final class Crockford
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Encodes whole bytes; the byte count must be a multiple of 5. */
    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $out;
    }
}
