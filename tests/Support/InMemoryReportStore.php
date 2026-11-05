<?php

declare(strict_types=1);

namespace Whistleblower\Tests\Support;

use DateTimeImmutable;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Page;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Port\ReportStore;

/** Reference implementation for the core tests. Returns clones so stale copies behave like detached rows. */
final class InMemoryReportStore implements ReportStore
{
    /** @var array<string, Report> */
    public array $reports = [];

    /** @var list<Message> */
    public array $messages = [];

    /** @var list<AuditEntry> */
    public array $audit = [];

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }

    public function insert(Report $report): void
    {
        $this->reports[$report->id] = clone $report;
    }

    public function update(Report $report): void
    {
        $this->reports[$report->id] = clone $report;
    }

    public function find(string $id): ?Report
    {
        return isset($this->reports[$id]) ? clone $this->reports[$id] : null;
    }

    public function lockById(string $id): ?Report
    {
        return $this->find($id);
    }

    public function findByTrackingHash(string $hash): ?Report
    {
        foreach ($this->reports as $report) {
            if ($report->trackingCodeHash === $hash) {
                return clone $report;
            }
        }

        return null;
    }

    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function messages(string $reportId, bool $includeInternal): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (Message $m): bool => $m->reportId === $reportId && ($includeInternal || $m->author !== 'internal'),
        ));
    }

    public function deleteMessages(string $reportId): void
    {
        $this->messages = array_values(array_filter($this->messages, static fn (Message $m): bool => $m->reportId !== $reportId));
    }

    public function addAudit(AuditEntry $entry): void
    {
        $this->audit[] = $entry;
    }

    public function auditEntries(string $reportId): array
    {
        return array_values(array_filter($this->audit, static fn (AuditEntry $a): bool => $a->reportId === $reportId));
    }

    public function openBatch(string $afterId, int $limit): array
    {
        return $this->batch($afterId, $limit, static fn (Report $r): bool => $r->status->isOpen());
    }

    public function uninvestigatedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($afterId, $limit, static fn (Report $r): bool => $r->anonymizedAt === null && $r->investigationStartedAt === null && $r->receivedAt <= $before);
    }

    public function closedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($afterId, $limit, static fn (Report $r): bool => $r->anonymizedAt === null
            && (($r->resolvedAt !== null && $r->resolvedAt <= $before) || ($r->archivedAt !== null && $r->archivedAt <= $before)));
    }

    public function receivedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($afterId, $limit, static fn (Report $r): bool => $r->receivedAt <= $before);
    }

    public function purge(array $ids): int
    {
        foreach ($ids as $id) {
            unset($this->reports[$id]);
            $this->deleteMessages($id);
            $this->audit = array_values(array_filter($this->audit, static fn (AuditEntry $a): bool => $a->reportId !== $id));
        }

        return count($ids);
    }

    public function page(?ReportStatus $status, bool $overdue, DateTimeImmutable $now, int $page, int $perPage): Page
    {
        $all = array_values(array_filter($this->reports, static function (Report $r) use ($status, $overdue, $now): bool {
            if ($status !== null && $r->status !== $status) {
                return false;
            }

            return ! $overdue || ($r->status->isOpen() && ($r->resolutionDueAt < $now || ($r->acknowledgedAt === null && $r->acknowledgementDueAt < $now)));
        }));
        usort($all, static fn (Report $a, Report $b): int => $b->receivedAt <=> $a->receivedAt);

        return new Page(array_map(static fn (Report $r): Report => clone $r, array_slice($all, ($page - 1) * $perPage, $perPage)), count($all), $page, $perPage);
    }

    /**
     * @param  callable(Report): bool  $filter
     * @return list<Report>
     */
    private function batch(string $afterId, int $limit, callable $filter): array
    {
        $ids = array_keys($this->reports);
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            if ($id > $afterId && $filter($this->reports[$id])) {
                $out[] = clone $this->reports[$id];
                if (count($out) === $limit) {
                    break;
                }
            }
        }

        return $out;
    }
}
