<?php

declare(strict_types=1);

namespace Whistleblower;

use Psr\Clock\ClockInterface;
use Whistleblower\Port\ReportStore;

/** Applies the retention rules of arts. 26.2 and 32.4. */
final readonly class Retention
{
    private const BATCH = 100;

    /** @param array{uninvestigated_days: int, closed_content_days: int|null, registry_years: int} $rules */
    public function __construct(
        private ReportStore $store,
        private Whistleblower $whistleblower,
        private ClockInterface $clock,
        private array $rules,
    ) {}

    /** @return array{anonymised: int, deleted: int} */
    public function apply(): array
    {
        $now = $this->clock->now();
        $anonymised = 0;

        // Art. 32.4: no investigation started within 3 months.
        $limit = $now->modify("-{$this->rules['uninvestigated_days']} days");
        for ($after = ''; ($batch = $this->store->uninvestigatedBatch($limit, $after, self::BATCH)) !== [];) {
            foreach ($batch as $report) {
                $this->whistleblower->anonymize($report, 'uninvestigated_retention');
                $anonymised++;
                $after = $report->id;
            }
        }

        if ($this->rules['closed_content_days'] !== null) {
            $limit = $now->modify("-{$this->rules['closed_content_days']} days");
            for ($after = ''; ($batch = $this->store->closedBatch($limit, $after, self::BATCH)) !== [];) {
                foreach ($batch as $report) {
                    $this->whistleblower->anonymize($report, 'closed_content_retention');
                    $anonymised++;
                    $after = $report->id;
                }
            }
        }

        // Art. 26.2: the registry never outlives 10 years.
        $deleted = 0;
        $limit = $now->modify("-{$this->rules['registry_years']} years");
        for ($after = ''; ($batch = $this->store->receivedBatch($limit, $after, self::BATCH)) !== [];) {
            $ids = array_map(static fn ($r) => $r->id, $batch);
            $deleted += $this->store->purge($ids);
            $after = end($ids);
        }

        return ['anonymised' => $anonymised, 'deleted' => $deleted];
    }
}
