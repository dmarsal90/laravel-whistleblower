<?php

declare(strict_types=1);

namespace Whistleblower;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Whistleblower\Domain\AuditEntry;
use Whistleblower\Domain\Message;
use Whistleblower\Domain\Page;
use Whistleblower\Domain\Report;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Exceptions\ReportNotFound;
use Whistleblower\Port\ReportStore;

/**
 * Report lifecycle. Every state change runs in a transaction on a freshly locked row and is audited.
 *
 * @phpstan-type Deadlines array{acknowledgement_days: int, resolution_months: int, extension_months: int}
 */
final class Whistleblower
{
    /** @param Deadlines $deadlines */
    public function __construct(
        private readonly ReportStore $store,
        private readonly ClockInterface $clock,
        private readonly array $deadlines,
    ) {}

    /** @param array{subject: string, description: string, category?: string|null, contact_name?: string|null, contact_email?: string|null} $data */
    public function submit(array $data): Submission
    {
        $code = TrackingCode::generate();
        $now = $this->clock->now();
        $name = $this->blankToNull($data['contact_name'] ?? null);
        $email = $this->blankToNull($data['contact_email'] ?? null);

        $report = new Report(
            id: Ulid::generate($now),
            trackingCodeHash: TrackingCode::hash((string) TrackingCode::normalize($code)),
            status: ReportStatus::Received,
            category: $data['category'] ?? null,
            subject: $data['subject'],
            description: $data['description'],
            anonymous: $name === null && $email === null,
            reporterName: $name,
            reporterEmail: $email,
            resolution: null,
            receivedAt: $now,
            acknowledgementDueAt: $now->modify("+{$this->deadlines['acknowledgement_days']} days"),
            acknowledgedAt: null,
            resolutionDueAt: self::addMonths($now, $this->deadlines['resolution_months']),
        );

        $this->store->transactional(function () use ($report): void {
            $this->store->insert($report);
            $this->audit($report->id, 'reporter', null, 'submitted');
        });

        return new Submission($report, $code);
    }

    public function find(string $id): ?Report
    {
        return $this->store->find($id);
    }

    public function findByCode(string $input): ?Report
    {
        $code = TrackingCode::normalize($input);

        return $code === null ? null : $this->store->findByTrackingHash(TrackingCode::hash($code));
    }

    public function acknowledge(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'acknowledge', [ReportStatus::Received], $actor, 'acknowledged', function (Report $r): void {
            $r->status = ReportStatus::Acknowledged;
            $r->acknowledgedAt = $this->clock->now();
        });
    }

    public function startInvestigation(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'investigate', [ReportStatus::Received, ReportStatus::Acknowledged], $actor, 'investigation_started', function (Report $r): void {
            $r->status = ReportStatus::Investigating;
            $r->investigationStartedAt = $this->clock->now();
        });
    }

    /** Art. 9.2.d: a single extension of up to three more months for especially complex cases. */
    public function extendDeadline(Report $report, string|int|null $actor, string $justification): Report
    {
        $report = $this->transition($report, 'extend', [ReportStatus::Received, ReportStatus::Acknowledged, ReportStatus::Investigating], $actor, 'deadline_extended', function (Report $r): void {
            if ($r->extendedAt !== null) {
                throw InvalidTransition::for('extend twice', $r->status);
            }
            $r->extendedAt = $this->clock->now();
            $r->resolutionDueAt = self::addMonths($r->receivedAt, $this->deadlines['resolution_months'] + $this->deadlines['extension_months']);
        }, ['justification_length' => mb_strlen($justification)]);

        // The justification can name people: keep it as an internal note, which stores encrypt.
        $this->addMessage($report, 'manager', $actor, $justification, internal: true);

        return $report;
    }

    public function resolve(Report $report, string|int|null $actor, string $summary): Report
    {
        return $this->transition($report, 'resolve', [ReportStatus::Investigating], $actor, 'resolved', function (Report $r) use ($summary): void {
            $r->status = ReportStatus::Resolved;
            $r->resolution = $summary;
            $r->resolvedAt = $this->clock->now();
        });
    }

    public function archive(Report $report, string|int|null $actor): Report
    {
        return $this->transition($report, 'archive', [ReportStatus::Received, ReportStatus::Acknowledged, ReportStatus::Investigating, ReportStatus::Resolved], $actor, 'archived', function (Report $r): void {
            $r->status = ReportStatus::Archived;
            $r->archivedAt = $this->clock->now();
        });
    }

    public function addMessage(Report $report, string $author, string|int|null $userId, string $body, bool $internal = false): Message
    {
        if ($report->status === ReportStatus::Archived || $report->anonymizedAt !== null) {
            throw InvalidTransition::for('message', $report->status);
        }

        return $this->store->transactional(function () use ($report, $author, $userId, $body, $internal): Message {
            $message = new Message($report->id, $internal ? 'internal' : $author, $userId === null ? null : (string) $userId, $body, $this->clock->now());
            $this->store->addMessage($message);
            $this->audit($report->id, $author, $userId, $internal ? 'internal_note_added' : 'message_added');

            return $message;
        });
    }

    /** Records that a manager opened a report (art. 32.1 access must be traceable). */
    public function recordView(Report $report, string|int|null $actor): void
    {
        $this->audit($report->id, 'manager', $actor, 'viewed');
    }

    /** @return list<Message> */
    public function messages(Report $report, bool $includeInternal): array
    {
        return $this->store->messages($report->id, $includeInternal);
    }

    /** @return list<AuditEntry> */
    public function auditTrail(Report $report): array
    {
        return $this->store->auditEntries($report->id);
    }

    public function list(?ReportStatus $status, bool $overdue, int $page, int $perPage = 25): Page
    {
        return $this->store->page($status, $overdue, $this->clock->now(), max(1, $page), $perPage);
    }

    /** Erases personal data and content, keeping the registry entry (art. 26 and 32.4). */
    public function anonymize(Report $report, string $reason): void
    {
        $this->store->transactional(function () use ($report, $reason): void {
            $locked = $this->store->lockById($report->id) ?? throw new ReportNotFound($report->id);
            $now = $this->clock->now();
            $this->store->deleteMessages($locked->id);
            $locked->subject = $locked->description = $locked->reporterName = $locked->reporterEmail = null;
            $locked->resolution = $locked->category = null;
            $locked->anonymizedAt = $now;
            if ($locked->status !== ReportStatus::Archived) {
                $locked->status = ReportStatus::Archived;
                $locked->archivedAt = $now;
            }
            $this->store->update($locked);
            $this->audit($locked->id, 'system', null, 'anonymized', ['reason' => $reason]);
        });
    }

    /**
     * @param  list<ReportStatus>  $from
     * @param  callable(Report): void  $change
     * @param  array<string, scalar|null>  $context
     */
    private function transition(Report $report, string $verb, array $from, string|int|null $actor, string $action, callable $change, array $context = []): Report
    {
        return $this->store->transactional(function () use ($report, $verb, $from, $actor, $action, $change, $context): Report {
            $locked = $this->store->lockById($report->id) ?? throw new ReportNotFound($report->id);
            if (! in_array($locked->status, $from, true) || $locked->anonymizedAt !== null) {
                throw InvalidTransition::for($verb, $locked->status);
            }
            $change($locked);
            $this->store->update($locked);
            $this->audit($locked->id, 'manager', $actor, $action, $context);

            return $locked;
        });
    }

    /** @param array<string, scalar|null> $context */
    private function audit(string $reportId, string $actorType, string|int|null $actorId, string $action, array $context = []): void
    {
        $this->store->addAudit(new AuditEntry(
            $reportId,
            $actorType,
            $actorId === null ? null : (string) $actorId,
            $action,
            $context === [] ? null : $context,
            $this->clock->now(),
        ));
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $day = (int) $date->format('j');
        $first = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->modify("+{$months} months");

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')));
    }
}
