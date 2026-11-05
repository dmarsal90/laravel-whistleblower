<?php

declare(strict_types=1);

namespace Whistleblower\Laravel;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/** Follows Carbon::setTestNow(), so Laravel's time travel helpers work. */
final class Clock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
