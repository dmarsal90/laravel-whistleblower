<?php

declare(strict_types=1);

namespace Whistleblower;

use Whistleblower\Domain\Report;

/** Result of a submission. The tracking code exists only here: it cannot be recovered later. */
final readonly class Submission
{
    public function __construct(public Report $report, public string $trackingCode) {}
}
