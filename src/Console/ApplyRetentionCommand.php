<?php

declare(strict_types=1);

namespace Whistleblower\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Whistleblower\Models\AuditLog;
use Whistleblower\Models\Message;
use Whistleblower\Models\Report;
use Whistleblower\Whistleblower;

final class ApplyRetentionCommand extends Command
{
    protected $signature = 'whistleblower:apply-retention';

    protected $description = 'Anonymise reports past their retention period and delete expired registry entries. Schedule it daily.';

    public function handle(Whistleblower $whistleblower): int
    {
        $now = Carbon::now();
        $anonymised = 0;

        // Art. 32.4: no investigation started within 3 months.
        $days = (int) config('whistleblower.retention.uninvestigated_days');
        Report::query()
            ->whereNull('anonymized_at')->whereNull('investigation_started_at')
            ->where('received_at', '<=', $now->copy()->subDays($days))
            ->chunkById(100, function ($reports) use ($whistleblower, &$anonymised): void {
                /** @var Report $report */
                foreach ($reports as $report) {
                    $whistleblower->anonymize($report, 'uninvestigated_retention');
                    $anonymised++;
                }
            });

        $closedDays = config('whistleblower.retention.closed_content_days');
        if (is_numeric($closedDays)) {
            $limit = $now->copy()->subDays((int) $closedDays);
            Report::query()
                ->whereNull('anonymized_at')
                ->where(fn ($q) => $q->where('resolved_at', '<=', $limit)->orWhere('archived_at', '<=', $limit))
                ->chunkById(100, function ($reports) use ($whistleblower, &$anonymised): void {
                    /** @var Report $report */
                    foreach ($reports as $report) {
                        $whistleblower->anonymize($report, 'closed_content_retention');
                        $anonymised++;
                    }
                });
        }

        // Art. 26.2: the registry never outlives 10 years. Children are deleted explicitly
        // instead of relying on foreign-key cascades, which not every database enforces.
        $years = (int) config('whistleblower.retention.registry_years');
        $deleted = 0;
        Report::query()->where('received_at', '<=', $now->copy()->subYears($years))
            ->chunkById(100, function ($reports) use (&$deleted): void {
                $ids = $reports->modelKeys();
                DB::transaction(function () use ($ids, &$deleted): void {
                    Message::query()->whereIn('report_id', $ids)->delete();
                    AuditLog::query()->whereIn('report_id', $ids)->delete();
                    $deleted += Report::query()->whereIn('id', $ids)->delete();
                });
            });

        $this->info("{$anonymised} report(s) anonymised, {$deleted} registry entr(y/ies) deleted.");

        return self::SUCCESS;
    }
}
