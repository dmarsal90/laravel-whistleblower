<?php

declare(strict_types=1);

namespace Whistleblower\Domain;

use DateTimeImmutable;

/** Append-only. Never put personal data or report content in the context. */
final readonly class AuditEntry
{
    /** @param array<string, scalar|null>|null $context */
    public function __construct(
        public string $reportId,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public ?array $context,
        public DateTimeImmutable $createdAt,
    ) {}
}
