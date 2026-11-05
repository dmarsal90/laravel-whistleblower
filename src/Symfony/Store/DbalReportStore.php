<?php

declare(strict_types=1);

namespace Whistleblower\Symfony\Store;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Page;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Port\ReportStore;

/**
 * Doctrine DBAL persistence. Dates are stored in UTC and the personal fields are encrypted
 * with SodiumCipher.
 *
 * @phpstan-type Row array<string, mixed>
 */
final class DbalReportStore implements ReportStore
{
    private const REPORTS = 'whistleblower_reports';

    private const MESSAGES = 'whistleblower_messages';

    private const AUDIT = 'whistleblower_audit_logs';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    private readonly DateTimeZone $utc;

    public function __construct(private readonly Connection $connection, private readonly SodiumCipher $cipher)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional(static fn () => $callback());
    }

    public function insert(Report $report): void
    {
        $this->connection->insert(self::REPORTS, $this->row($report), $this->reportTypes());
    }

    public function update(Report $report): void
    {
        $row = $this->row($report);
        unset($row['id']);
        $this->connection->update(self::REPORTS, $row, ['id' => $report->id], $this->reportTypes());
    }

    public function find(string $id): ?Report
    {
        return $this->one($this->select()->where('id = :id')->setParameter('id', $id));
    }

    public function lockById(string $id): ?Report
    {
        $query = $this->select()->where('id = :id')->setParameter('id', $id);
        // SQLite has no row locks: a write transaction already locks the whole database.
        if (! $this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->forUpdate();
        }

        return $this->one($query);
    }

    public function findByTrackingHash(string $hash): ?Report
    {
        return $this->one($this->select()->where('tracking_code_hash = :hash')->setParameter('hash', $hash));
    }

    public function addMessage(Message $message): void
    {
        $this->connection->insert(self::MESSAGES, [
            'report_id' => $message->reportId,
            'author' => $message->author,
            'user_id' => $message->userId,
            'body' => $this->cipher->encrypt($message->body),
            'created_at' => $this->date($message->createdAt),
        ]);
    }

    public function messages(string $reportId, bool $includeInternal): array
    {
        $query = $this->connection->createQueryBuilder()->select('*')->from(self::MESSAGES)
            ->where('report_id = :id')->setParameter('id', $reportId)->orderBy('id');
        if (! $includeInternal) {
            $query->andWhere("author <> 'internal'");
        }

        return array_map(fn (array $r): Message => new Message(
            (string) $r['report_id'], (string) $r['author'], $this->nullableString($r['user_id']),
            (string) $this->cipher->decrypt((string) $r['body']), $this->parse((string) $r['created_at']),
        ), $query->executeQuery()->fetchAllAssociative());
    }

    public function deleteMessages(string $reportId): void
    {
        $this->connection->delete(self::MESSAGES, ['report_id' => $reportId]);
    }

    public function addAudit(AuditEntry $entry): void
    {
        $this->connection->insert(self::AUDIT, [
            'report_id' => $entry->reportId,
            'actor_type' => $entry->actorType,
            'actor_id' => $entry->actorId,
            'action' => $entry->action,
            'context' => $entry->context === null ? null : json_encode($entry->context, JSON_THROW_ON_ERROR),
            'created_at' => $this->date($entry->createdAt),
        ]);
    }

    public function auditEntries(string $reportId): array
    {
        $rows = $this->connection->createQueryBuilder()->select('*')->from(self::AUDIT)
            ->where('report_id = :id')->setParameter('id', $reportId)->orderBy('id')
            ->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $r): AuditEntry => new AuditEntry(
            (string) $r['report_id'], (string) $r['actor_type'], $this->nullableString($r['actor_id']), (string) $r['action'],
            $r['context'] === null ? null : $this->context((string) $r['context']), $this->parse((string) $r['created_at']),
        ), $rows);
    }

    public function openBatch(string $afterId, int $limit): array
    {
        return $this->batch($this->select()->where('status NOT IN (:closed)')
            ->setParameter('closed', [ReportStatus::Resolved->value, ReportStatus::Archived->value], ArrayParameterType::STRING), $afterId, $limit);
    }

    public function uninvestigatedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($this->select()->where('anonymized_at IS NULL AND investigation_started_at IS NULL AND received_at <= :before')
            ->setParameter('before', $this->date($before)), $afterId, $limit);
    }

    public function closedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($this->select()->where('anonymized_at IS NULL AND (resolved_at <= :before OR archived_at <= :before)')
            ->setParameter('before', $this->date($before)), $afterId, $limit);
    }

    public function receivedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch($this->select()->where('received_at <= :before')->setParameter('before', $this->date($before)), $afterId, $limit);
    }

    public function purge(array $ids): int
    {
        return (int) $this->connection->transactional(function () use ($ids): int {
            foreach ([self::MESSAGES, self::AUDIT] as $table) {
                $this->connection->executeStatement("DELETE FROM {$table} WHERE report_id IN (:ids)", ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
            }

            return (int) $this->connection->executeStatement('DELETE FROM '.self::REPORTS.' WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
        });
    }

    public function page(?ReportStatus $status, bool $overdue, DateTimeImmutable $now, int $page, int $perPage): Page
    {
        $filter = static function (QueryBuilder $q) use ($status, $overdue, $now): QueryBuilder {
            if ($status !== null) {
                $q->andWhere('status = :status')->setParameter('status', $status->value);
            }
            if ($overdue) {
                $q->andWhere('status NOT IN (:closed)')
                    ->setParameter('closed', [ReportStatus::Resolved->value, ReportStatus::Archived->value], ArrayParameterType::STRING)
                    ->andWhere('(resolution_due_at < :now OR (acknowledged_at IS NULL AND acknowledgement_due_at < :now))')
                    ->setParameter('now', $now->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT));
            }

            return $q;
        };

        $total = (int) $filter($this->connection->createQueryBuilder()->select('COUNT(*)')->from(self::REPORTS))->executeQuery()->fetchOne();
        $rows = $filter($this->select())->orderBy('received_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->executeQuery()->fetchAllAssociative();

        return new Page(array_map($this->hydrate(...), $rows), $total, $page, $perPage);
    }

    private function select(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select('*')->from(self::REPORTS);
    }

    private function one(QueryBuilder $query): ?Report
    {
        $row = $query->executeQuery()->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<Report> */
    private function batch(QueryBuilder $query, string $afterId, int $limit): array
    {
        $rows = $query->andWhere('id > :after')->setParameter('after', $afterId)->orderBy('id')->setMaxResults($limit)->executeQuery()->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /** @param Row $r */
    private function hydrate(array $r): Report
    {
        return new Report(
            id: (string) $r['id'],
            trackingCodeHash: (string) $r['tracking_code_hash'],
            status: ReportStatus::from((string) $r['status']),
            category: $this->nullableString($r['category']),
            subject: $this->cipher->decrypt($this->nullableString($r['subject'])),
            description: $this->cipher->decrypt($this->nullableString($r['description'])),
            anonymous: (bool) $r['anonymous'],
            reporterName: $this->cipher->decrypt($this->nullableString($r['reporter_name'])),
            reporterEmail: $this->cipher->decrypt($this->nullableString($r['reporter_email'])),
            resolution: $this->cipher->decrypt($this->nullableString($r['resolution'])),
            receivedAt: $this->parse((string) $r['received_at']),
            acknowledgementDueAt: $this->parse((string) $r['acknowledgement_due_at']),
            acknowledgedAt: $this->parseNullable($r['acknowledged_at']),
            resolutionDueAt: $this->parse((string) $r['resolution_due_at']),
            extendedAt: $this->parseNullable($r['extended_at']),
            investigationStartedAt: $this->parseNullable($r['investigation_started_at']),
            resolvedAt: $this->parseNullable($r['resolved_at']),
            archivedAt: $this->parseNullable($r['archived_at']),
            anonymizedAt: $this->parseNullable($r['anonymized_at']),
        );
    }

    /** @return Row */
    private function row(Report $r): array
    {
        return [
            'id' => $r->id,
            'tracking_code_hash' => $r->trackingCodeHash,
            'status' => $r->status->value,
            'category' => $r->category,
            'subject' => $this->cipher->encrypt($r->subject),
            'description' => $this->cipher->encrypt($r->description),
            'anonymous' => $r->anonymous,
            'reporter_name' => $this->cipher->encrypt($r->reporterName),
            'reporter_email' => $this->cipher->encrypt($r->reporterEmail),
            'resolution' => $this->cipher->encrypt($r->resolution),
            'received_at' => $this->date($r->receivedAt),
            'acknowledgement_due_at' => $this->date($r->acknowledgementDueAt),
            'acknowledged_at' => $this->dateOrNull($r->acknowledgedAt),
            'resolution_due_at' => $this->date($r->resolutionDueAt),
            'extended_at' => $this->dateOrNull($r->extendedAt),
            'investigation_started_at' => $this->dateOrNull($r->investigationStartedAt),
            'resolved_at' => $this->dateOrNull($r->resolvedAt),
            'archived_at' => $this->dateOrNull($r->archivedAt),
            'anonymized_at' => $this->dateOrNull($r->anonymizedAt),
        ];
    }

    /** @return array<string, string> */
    private function reportTypes(): array
    {
        return ['anonymous' => Types::BOOLEAN];
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone($this->utc)->format(self::DATE_FORMAT);
    }

    private function dateOrNull(?DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $this->date($date);
    }

    private function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->utc);
    }

    private function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? $this->parse($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return array<string, scalar|null> */
    private function context(string $json): array
    {
        /** @var array<string, scalar|null> */
        return json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    }
}
