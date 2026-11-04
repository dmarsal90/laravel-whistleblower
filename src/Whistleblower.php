<?php

declare(strict_types=1);

namespace Whistleblower;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Models\AuditLog;
use Whistleblower\Models\Message;
use Whistleblower\Models\Report;

/**
 * Report lifecycle. Every state change runs in a transaction on a freshly locked row and is audited.
 *
 * @phpstan-type Deadlines array{acknowledgement_days: int, resolution_months: int, extension_months: int}
 */
final class Whistleblower
{
    /** @param Deadlines $deadlines */
    public function __construct(private readonly array $deadlines) {}

    /** @param array{subject: string, description: string, category?: string|null, contact_name?: string|null, contact_email?: string|null} $data */
    public function submit(array $data): Submission
    {
        $code = TrackingCode::generate();
        $now = Carbon::now();
        $name = $this->blankToNull($data['contact_name'] ?? null);
        $email = $this->blankToNull($data['contact_email'] ?? null);

        $report = new Report;
        $report->forceFill([
            'tracking_code_hash' => TrackingCode::hash((string) TrackingCode::normalize($code)),
            'status' => ReportStatus::Received,
            'category' => $data['category'] ?? null,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'anonymous' => $name === null && $email === null,
            'reporter_name' => $name,
            'reporter_email' => $email,
            'received_at' => $now,
            'acknowledgement_due_at' => $now->copy()->addDays($this->deadlines['acknowledgement_days']),
            'resolution_due_at' => $now->copy()->addMonthsNoOverflow($this->deadlines['resolution_months']),
        ]);

        DB::transaction(function () use ($report): void {
            $report->save();
            $this->audit($report, 'reporter', null, 'submitted');
        });

        return new Submission($report, $code);
    }

    public function findByCode(string $input): ?Report
    {
        $code = TrackingCode::normalize($input);

        return $code === null ? null : Report::query()->where('tracking_code_hash', TrackingCode::hash($code))->first();
    }

    public function acknowledge(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'acknowledge', [ReportStatus::Received], $actor, 'acknowledged', function (Report $r): void {
            $r->status = ReportStatus::Acknowledged;
            $r->acknowledged_at = Carbon::now();
        });
    }

    public function startInvestigation(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'investigate', [ReportStatus::Received, ReportStatus::Acknowledged], $actor, 'investigation_started', function (Report $r): void {
            $r->status = ReportStatus::Investigating;
            $r->investigation_started_at = Carbon::now();
        });
    }

    /** Art. 9.2.d: a single extension of up to three more months for especially complex cases. */
    public function extendDeadline(Report $report, string|int|null $actor, string $justification): Report
    {
        $report = $this->transition($report, 'extend', [ReportStatus::Acknowledged, ReportStatus::Investigating, ReportStatus::Received], $actor, 'deadline_extended', function (Report $r): void {
            if ($r->extended_at !== null) {
                throw InvalidTransition::for('extend twice', $r->status);
            }
            $r->extended_at = Carbon::now();
            $r->resolution_due_at = $r->received_at->copy()
                ->addMonthsNoOverflow($this->deadlines['resolution_months'] + $this->deadlines['extension_months']);
        }, ['justification_length' => mb_strlen($justification)]);

        // The justification can name people: keep it as a manager message, which is encrypted.
        $this->addMessage($report, 'manager', $actor, $justification, internal: true);

        return $report;
    }

    public function resolve(Report $report, string|int|null $actor, string $summary): Report
    {
        return $this->transition($report, 'resolve', [ReportStatus::Investigating], $actor, 'resolved', function (Report $r) use ($summary): void {
            $r->status = ReportStatus::Resolved;
            $r->resolution = $summary;
            $r->resolved_at = Carbon::now();
        });
    }

    public function archive(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'archive', [ReportStatus::Received, ReportStatus::Acknowledged, ReportStatus::Investigating, ReportStatus::Resolved], $actor, 'archived', function (Report $r): void {
            $r->status = ReportStatus::Archived;
            $r->archived_at = Carbon::now();
        });
    }

    public function addMessage(Report $report, string $author, string|int|null $userId, string $body, bool $internal = false): Message
    {
        if ($report->status === ReportStatus::Archived || $report->anonymized_at !== null) {
            throw InvalidTransition::for('message', $report->status);
        }

        return DB::transaction(function () use ($report, $author, $userId, $body, $internal): Message {
            $message = new Message;
            $message->forceFill([
                'report_id' => $report->getKey(),
                'author' => $internal ? 'internal' : $author,
                'user_id' => $userId === null ? null : (string) $userId,
                'body' => $body,
                'created_at' => Carbon::now(),
            ])->save();
            $this->audit($report, $author, $userId, $internal ? 'internal_note_added' : 'message_added');

            return $message;
        });
    }

    /** Records that a manager opened a report (art. 32.1 access must be traceable). */
    public function recordView(Report $report, string|int|null $actor): void
    {
        $this->audit($report, 'manager', $actor, 'viewed');
    }

    /** @return Builder<Report> */
    public function open(): Builder
    {
        return Report::query()->whereNotIn('status', [ReportStatus::Resolved->value, ReportStatus::Archived->value]);
    }

    /**
     * Erases personal data and content, keeping the registry entry (art. 26 and 32.4).
     */
    public function anonymize(Report $report, string $reason): void
    {
        DB::transaction(function () use ($report, $reason): void {
            Message::query()->where('report_id', $report->getKey())->delete();
            $report->forceFill([
                'subject' => null,
                'description' => null,
                'reporter_name' => null,
                'reporter_email' => null,
                'resolution' => null,
                'category' => null,
                'anonymized_at' => Carbon::now(),
            ]);
            if ($report->status !== ReportStatus::Archived) {
                $report->status = ReportStatus::Archived;
                $report->archived_at = Carbon::now();
            }
            $report->save();
            $this->audit($report, 'system', null, 'anonymized', ['reason' => $reason]);
        });
    }

    /**
     * @param  list<ReportStatus>  $from
     * @param  array<string, scalar|null>  $context
     */
    private function transition(Report $report, string $verb, array $from, string|int|null $actor, string $action, callable $change, array $context = []): Report
    {
        return DB::transaction(function () use ($report, $verb, $from, $actor, $action, $change, $context): Report {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($locked->status, $from, true) || $locked->anonymized_at !== null) {
                throw InvalidTransition::for($verb, $locked->status);
            }
            $change($locked);
            $locked->save();
            $this->audit($locked, 'manager', $actor, $action, $context);

            return $locked;
        });
    }

    /** @param array<string, scalar|null> $context */
    private function audit(Report $report, string $actorType, string|int|null $actorId, string $action, array $context = []): void
    {
        $log = new AuditLog;
        $log->forceFill([
            'report_id' => $report->getKey(),
            'actor_type' => $actorType,
            'actor_id' => $actorId === null ? null : (string) $actorId,
            'action' => $action,
            'context' => $context === [] ? null : $context,
            'created_at' => Carbon::now(),
        ])->save();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
