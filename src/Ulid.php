<?php

declare(strict_types=1);

namespace Whistleblower;

use DateTimeImmutable;

/** Sortable 26-character identifiers: 48 bits of milliseconds followed by 80 random bits. */
final class Ulid
{
    public static function generate(DateTimeImmutable $at): string
    {
        $millis = $at->getTimestamp() * 1000 + (int) $at->format('v');
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = Crockford::ALPHABET[$millis % 32].$time;
            $millis = intdiv($millis, 32);
        }

        return $time.Crockford::encode(random_bytes(10));
    }
}
