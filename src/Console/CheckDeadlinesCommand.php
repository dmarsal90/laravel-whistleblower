<?php

declare(strict_types=1);

namespace Whistleblower\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Whistleblower\Enums\Deadline;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Models\Report;
use Whistleblower\Whistleblower;

final class CheckDeadlinesCommand extends Command
{
    protected $signature = 'whistleblower:check-deadlines';

    protected $description = 'Dispatch an event for every open report whose legal deadline is near or past. Schedule it daily.';

    public function handle(Whistleblower $whistleblower, Dispatcher $events): int
    {
        $ackWarn = (int) config('whistleblower.deadlines.warn_acknowledgement_days');
        $resWarn = (int) config('whistleblower.deadlines.warn_resolution_days');
        $now = Carbon::now();
        $count = 0;

        $whistleblower->open()->chunkById(100, function ($reports) use ($events, $now, $ackWarn, $resWarn, &$count): void {
            /** @var Report $report */
            foreach ($reports as $report) {
                if ($report->acknowledged_at === null) {
                    $count += $this->check($events, $report, Deadline::Acknowledgement, $report->acknowledgement_due_at, $now, $ackWarn);
                }
                $count += $this->check($events, $report, Deadline::Resolution, $report->resolution_due_at, $now, $resWarn);
            }
        });

        $this->info("{$count} deadline alert(s) dispatched.");

        return self::SUCCESS;
    }

    private function check(Dispatcher $events, Report $report, Deadline $deadline, Carbon $due, Carbon $now, int $warnDays): int
    {
        $overdue = $now->greaterThan($due);
        if (! $overdue && $now->lessThan($due->copy()->subDays($warnDays))) {
            return 0;
        }
        $events->dispatch(new DeadlineReached($report->id, $deadline, $due, $overdue));

        return 1;
    }
}
