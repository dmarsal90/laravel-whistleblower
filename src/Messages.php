<?php

declare(strict_types=1);

namespace Whistleblower;

/** Reads the same lang/ files Laravel uses, for adapters without their own translator. */
final class Messages
{
    /** @param array<string, string> $replace */
    public static function get(string $locale, string $key, array $replace = []): string
    {
        $language = strtolower(substr($locale, 0, 2));
        $file = __DIR__.'/../lang/'.(in_array($language, ['es', 'en'], true) ? $language : 'en').'/messages.php';
        /** @var array<string, mixed> $messages */
        $messages = require $file;

        $value = $messages;
        foreach (explode('.', $key) as $part) {
            $value = is_array($value) && isset($value[$part]) ? $value[$part] : $key;
        }

        return strtr(is_string($value) ? $value : $key, array_combine(
            array_map(static fn (string $k): string => ':'.$k, array_keys($replace)),
            array_values($replace),
        ));
    }
}
