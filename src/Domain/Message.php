<?php

declare(strict_types=1);

namespace Whistleblower\Domain;

use DateTimeImmutable;

/** Authors: "reporter", "manager", or "internal" for notes the reporter never sees. */
final readonly class Message
{
    public function __construct(
        public string $reportId,
        public string $author,
        public ?string $userId,
        public string $body,
        public DateTimeImmutable $createdAt,
    ) {}
}
