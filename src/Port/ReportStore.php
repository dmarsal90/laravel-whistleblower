<?php

declare(strict_types=1);

namespace Whistleblower\Port;

use DateTimeImmutable;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Page;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;

/**
 * Persistence port. Implementations must encrypt subject, description, contact data, resolution
 * and message bodies at rest, and must delete child rows explicitly in purge() instead of relying
 * on foreign-key cascades. Every *Batch method returns up to $limit reports with an id greater
 * than $afterId, ordered by id, so callers can walk a large table in a stable order.
 */
interface ReportStore
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    public function insert(Report $report): void;

    public function update(Report $report): void;

    public function find(string $id): ?Report;

    /** Reads the report with a row lock. Only meaningful inside transactional(). */
    public function lockById(string $id): ?Report;

    public function findByTrackingHash(string $hash): ?Report;

    public function addMessage(Message $message): void;

    /** @return list<Message> Oldest first. */
    public function messages(string $reportId, bool $includeInternal): array;

    public function deleteMessages(string $reportId): void;

    public function addAudit(AuditEntry $entry): void;

    /** @return list<AuditEntry> Oldest first. */
    public function auditEntries(string $reportId): array;

    /** @return list<Report> Reports that are neither resolved nor archived. */
    public function openBatch(string $afterId, int $limit): array;

    /** @return list<Report> Not anonymised, no investigation started, received at or before $before. */
    public function uninvestigatedBatch(DateTimeImmutable $before, string $afterId, int $limit): array;

    /** @return list<Report> Not anonymised, resolved or archived at or before $before. */
    public function closedBatch(DateTimeImmutable $before, string $afterId, int $limit): array;

    /** @return list<Report> Received at or before $before, regardless of state. */
    public function receivedBatch(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * Deletes the reports, their messages and their audit entries.
     *
     * @param  list<string>  $ids
     */
    public function purge(array $ids): int;

    /** Newest first. With $overdue, only open reports past their acknowledgement or resolution deadline. */
    public function page(?ReportStatus $status, bool $overdue, DateTimeImmutable $now, int $page, int $perPage): Page;
}
