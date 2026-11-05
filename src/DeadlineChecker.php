<?php

declare(strict_types=1);

namespace Whistleblower;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\Deadline;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Port\ReportStore;

/** Finds open reports whose legal deadline is near or past. The adapter dispatches the events. */
final readonly class DeadlineChecker
{
    private const BATCH = 100;

    /** @param array{warn_acknowledgement_days: int, warn_resolution_days: int} $warn */
    public function __construct(private ReportStore $store, private ClockInterface $clock, private array $warn) {}

    /** @return list<DeadlineReached> */
    public function check(): array
    {
        $now = $this->clock->now();
        $events = [];
        $after = '';

        while (($batch = $this->store->openBatch($after, self::BATCH)) !== []) {
            foreach ($batch as $report) {
                if ($report->acknowledgedAt === null) {
                    $events = [...$events, ...$this->evaluate($report, Deadline::Acknowledgement, $report->acknowledgementDueAt, $now, $this->warn['warn_acknowledgement_days'])];
                }
                $events = [...$events, ...$this->evaluate($report, Deadline::Resolution, $report->resolutionDueAt, $now, $this->warn['warn_resolution_days'])];
                $after = $report->id;
            }
        }

        return $events;
    }

    /** @return list<DeadlineReached> */
    private function evaluate(Report $report, Deadline $deadline, DateTimeImmutable $due, DateTimeImmutable $now, int $warnDays): array
    {
        $overdue = $now > $due;
        if (! $overdue && $now < $due->modify("-{$warnDays} days")) {
            return [];
        }

        return [new DeadlineReached($report->id, $deadline, $due, $overdue)];
    }
}
