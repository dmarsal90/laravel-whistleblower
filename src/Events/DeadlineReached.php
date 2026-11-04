<?php

declare(strict_types=1);

namespace Whistleblower\Events;

use Illuminate\Support\Carbon;
use Whistleblower\Enums\Deadline;

/** Fired by whistleblower:check-deadlines. Carries identifiers only, never report content. */
final readonly class DeadlineReached
{
    public function __construct(
        public string $reportId,
        public Deadline $deadline,
        public Carbon $dueAt,
        public bool $overdue,
    ) {}
}
