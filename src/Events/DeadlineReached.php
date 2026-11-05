<?php

declare(strict_types=1);

namespace Whistleblower\Events;

use DateTimeImmutable;
use Whistleblower\Enums\Deadline;

/** Carries identifiers only, never report content. */
final readonly class DeadlineReached
{
    public function __construct(
        public string $reportId,
        public Deadline $deadline,
        public DateTimeImmutable $dueAt,
        public bool $overdue,
    ) {}
}
