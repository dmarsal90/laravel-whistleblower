<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Store;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Page;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Laravel\Models\AuditLog;
use Whistleblower\Laravel\Models\Message as MessageModel;
use Whistleblower\Laravel\Models\Report as ReportModel;
use Whistleblower\Port\ReportStore;

/** Eloquent persistence. The model casts encrypt personal fields with the application key. */
final class EloquentReportStore implements ReportStore
{
    public function transactional(callable $callback): mixed
    {
        return DB::transaction(static fn () => $callback());
    }

    public function insert(Report $report): void
    {
        $model = new ReportModel;
        $this->fill($model, $report)->save();
    }

    public function update(Report $report): void
    {
        $this->fill(ReportModel::query()->findOrFail($report->id), $report)->save();
    }

    public function find(string $id): ?Report
    {
        $model = ReportModel::query()->find($id);

        return $model === null ? null : $this->toDomain($model);
    }

    public function lockById(string $id): ?Report
    {
        $model = ReportModel::query()->lockForUpdate()->find($id);

        return $model === null ? null : $this->toDomain($model);
    }

    public function findByTrackingHash(string $hash): ?Report
    {
        $model = ReportModel::query()->where('tracking_code_hash', $hash)->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function addMessage(Message $message): void
    {
        (new MessageModel)->forceFill([
            'report_id' => $message->reportId,
            'author' => $message->author,
            'user_id' => $message->userId,
            'body' => $message->body,
            'created_at' => $message->createdAt,
        ])->save();
    }

    public function messages(string $reportId, bool $includeInternal): array
    {
        $query = MessageModel::query()->where('report_id', $reportId)->orderBy('id');
        if (! $includeInternal) {
            $query->where('author', '!=', 'internal');
        }

        return array_values($query->get()->map(fn (MessageModel $m): Message => new Message(
            $m->report_id, $m->author, $m->user_id, $m->body, $m->created_at->toDateTimeImmutable(),
        ))->all());
    }

    public function deleteMessages(string $reportId): void
    {
        MessageModel::query()->where('report_id', $reportId)->delete();
    }

    public function addAudit(AuditEntry $entry): void
    {
        (new AuditLog)->forceFill([
            'report_id' => $entry->reportId,
            'actor_type' => $entry->actorType,
            'actor_id' => $entry->actorId,
            'action' => $entry->action,
            'context' => $entry->context,
            'created_at' => $entry->createdAt,
        ])->save();
    }

    public function auditEntries(string $reportId): array
    {
        return array_values(AuditLog::query()->where('report_id', $reportId)->orderBy('id')->get()
            ->map(fn (AuditLog $a): AuditEntry => new AuditEntry(
                $a->report_id, $a->actor_type, $a->actor_id, $a->action, $a->context, $a->created_at->toDateTimeImmutable(),
            ))->all());
    }

    public function openBatch(string $afterId, int $limit): array
    {
        return $this->batch(ReportModel::query()->whereNotIn('status', [ReportStatus::Resolved->value, ReportStatus::Archived->value]), $afterId, $limit);
    }

    public function uninvestigatedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch(ReportModel::query()->whereNull('anonymized_at')->whereNull('investigation_started_at')->where('received_at', '<=', $before), $afterId, $limit);
    }

    public function closedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch(
            ReportModel::query()->whereNull('anonymized_at')
                ->where(fn (Builder $q) => $q->where('resolved_at', '<=', $before)->orWhere('archived_at', '<=', $before)),
            $afterId,
            $limit,
        );
    }

    public function receivedBatch(DateTimeImmutable $before, string $afterId, int $limit): array
    {
        return $this->batch(ReportModel::query()->where('received_at', '<=', $before), $afterId, $limit);
    }

    public function purge(array $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            MessageModel::query()->whereIn('report_id', $ids)->delete();
            AuditLog::query()->whereIn('report_id', $ids)->delete();

            return ReportModel::query()->whereIn('id', $ids)->delete();
        });
    }

    public function page(?ReportStatus $status, bool $overdue, DateTimeImmutable $now, int $page, int $perPage): Page
    {
        $query = ReportModel::query();
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($overdue) {
            $query->whereNotIn('status', [ReportStatus::Resolved->value, ReportStatus::Archived->value])
                ->where(fn (Builder $q) => $q->where('resolution_due_at', '<', $now)
                    ->orWhere(fn (Builder $q) => $q->whereNull('acknowledged_at')->where('acknowledgement_due_at', '<', $now)));
        }

        $total = $query->count();
        $items = $query->orderByDesc('received_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return new Page($this->all($items), $total, $page, $perPage);
    }

    /**
     * @param  Builder<ReportModel>  $query
     * @return list<Report>
     */
    private function batch(Builder $query, string $afterId, int $limit): array
    {
        return $this->all($query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get());
    }

    /**
     * @param  Collection<int, ReportModel>  $models
     * @return list<Report>
     */
    private function all(Collection $models): array
    {
        return array_values($models->map(fn (ReportModel $m): Report => $this->toDomain($m))->all());
    }

    private function toDomain(ReportModel $m): Report
    {
        return new Report(
            id: $m->id,
            trackingCodeHash: $m->tracking_code_hash,
            status: $m->status,
            category: $m->category,
            subject: $m->subject,
            description: $m->description,
            anonymous: $m->anonymous,
            reporterName: $m->reporter_name,
            reporterEmail: $m->reporter_email,
            resolution: $m->resolution,
            receivedAt: $m->received_at->toDateTimeImmutable(),
            acknowledgementDueAt: $m->acknowledgement_due_at->toDateTimeImmutable(),
            acknowledgedAt: $m->acknowledged_at?->toDateTimeImmutable(),
            resolutionDueAt: $m->resolution_due_at->toDateTimeImmutable(),
            extendedAt: $m->extended_at?->toDateTimeImmutable(),
            investigationStartedAt: $m->investigation_started_at?->toDateTimeImmutable(),
            resolvedAt: $m->resolved_at?->toDateTimeImmutable(),
            archivedAt: $m->archived_at?->toDateTimeImmutable(),
            anonymizedAt: $m->anonymized_at?->toDateTimeImmutable(),
        );
    }

    private function fill(ReportModel $m, Report $r): ReportModel
    {
        return $m->forceFill([
            'id' => $r->id,
            'tracking_code_hash' => $r->trackingCodeHash,
            'status' => $r->status,
            'category' => $r->category,
            'subject' => $r->subject,
            'description' => $r->description,
            'anonymous' => $r->anonymous,
            'reporter_name' => $r->reporterName,
            'reporter_email' => $r->reporterEmail,
            'resolution' => $r->resolution,
            'received_at' => $r->receivedAt,
            'acknowledgement_due_at' => $r->acknowledgementDueAt,
            'acknowledged_at' => $r->acknowledgedAt,
            'resolution_due_at' => $r->resolutionDueAt,
            'extended_at' => $r->extendedAt,
            'investigation_started_at' => $r->investigationStartedAt,
            'resolved_at' => $r->resolvedAt,
            'archived_at' => $r->archivedAt,
            'anonymized_at' => $r->anonymizedAt,
        ]);
    }
}
