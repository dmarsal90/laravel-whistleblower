<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Models\Report;
use Whistleblower\TrackingCode;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    Carbon::setTestNow('2026-01-31 10:00:00');
    $this->wb = app(Whistleblower::class);
    $this->submit = fn (array $extra = []) => $this->wb->submit(['subject' => 'Invoice fraud', 'description' => 'Details of the case'] + $extra);
});

it('stores only the hash of the tracking code', function (): void {
    $submission = ($this->submit)();

    expect($submission->report->tracking_code_hash)->toBe(TrackingCode::hash((string) TrackingCode::normalize($submission->trackingCode)))
        ->and(json_encode(DB::table('whistleblower_reports')->first()))->not->toContain(str_replace('-', '', $submission->trackingCode));
    expect($this->wb->findByCode(strtolower($submission->trackingCode))?->is($submission->report))->toBeTrue()
        ->and($this->wb->findByCode('0000-0000-0000-0000'))->toBeNull()
        ->and($this->wb->findByCode('nonsense'))->toBeNull();
});

it('sets the legal deadlines at submission', function (): void {
    $report = ($this->submit)()->report;

    expect($report->status)->toBe(ReportStatus::Received)
        ->and($report->acknowledgement_due_at->toDateString())->toBe('2026-02-07')
        ->and($report->resolution_due_at->toDateString())->toBe('2026-04-30');
});

it('marks a report anonymous only when no contact data is given', function (): void {
    expect(($this->submit)()->report->anonymous)->toBeTrue()
        ->and(($this->submit)(['contact_name' => '  ', 'contact_email' => ''])->report->anonymous)->toBeTrue()
        ->and(($this->submit)(['contact_email' => 'a@b.es'])->report->anonymous)->toBeFalse();
});

it('encrypts content and contact data at rest', function (): void {
    ($this->submit)(['contact_name' => 'Marta Ruiz', 'contact_email' => 'marta@example.com']);
    $raw = json_encode(DB::table('whistleblower_reports')->first());

    expect($raw)->not->toContain('Invoice fraud')->not->toContain('Details of the case')
        ->not->toContain('Marta')->not->toContain('marta@example.com');
    expect(Report::query()->first()?->reporter_email)->toBe('marta@example.com');

    $this->wb->addMessage(Report::query()->firstOrFail(), 'reporter', null, 'secret follow-up');
    expect(json_encode(DB::table('whistleblower_messages')->first()))->not->toContain('secret follow-up');
});

it('follows the happy path and audits every step', function (): void {
    $report = ($this->submit)()->report;
    $report = $this->wb->acknowledge($report, 7);
    $report = $this->wb->startInvestigation($report, 7);
    $report = $this->wb->resolve($report, 7, 'Measures adopted');
    $report = $this->wb->archive($report, 7);

    expect($report->status)->toBe(ReportStatus::Archived)
        ->and($report->acknowledged_at)->not->toBeNull()
        ->and($report->resolution)->toBe('Measures adopted')
        ->and($report->auditLogs()->pluck('action')->all())
        ->toBe(['submitted', 'acknowledged', 'investigation_started', 'resolved', 'archived']);
});

it('rejects invalid transitions', function (string $method): void {
    $report = ($this->submit)()->report;

    expect(fn () => match ($method) {
        'resolve' => $this->wb->resolve($report, 1, 'x'),
        'double-ack' => $this->wb->acknowledge($this->wb->acknowledge($report, 1), 1),
        'after-archive' => $this->wb->startInvestigation($this->wb->archive($report, 1), 1),
    })->toThrow(InvalidTransition::class);
})->with(['resolve', 'double-ack', 'after-archive']);

it('does not let a stale copy override a newer state', function (): void {
    $report = ($this->submit)()->report;
    $stale = Report::query()->findOrFail($report->id);
    $this->wb->archive($report, 1);

    expect(fn () => $this->wb->acknowledge($stale, 1))->toThrow(InvalidTransition::class);
});

it('extends the resolution deadline once, to six months', function (): void {
    $report = $this->wb->startInvestigation(($this->submit)()->report, 1);
    $report = $this->wb->extendDeadline($report, 1, 'Complex case with many parties');

    expect($report->resolution_due_at->toDateString())->toBe('2026-07-31')
        ->and(fn () => $this->wb->extendDeadline($report, 1, 'again'))->toThrow(InvalidTransition::class);
    expect(json_encode(DB::table('whistleblower_audit_logs')->get()))->not->toContain('Complex case');
});

it('refuses messages on archived or anonymised reports', function (): void {
    $report = $this->wb->archive(($this->submit)()->report, 1);

    expect(fn () => $this->wb->addMessage($report, 'reporter', null, 'hi'))->toThrow(InvalidTransition::class);
});

it('anonymises content but keeps the registry entry', function (): void {
    $report = ($this->submit)(['contact_email' => 'a@b.es', 'category' => 'fraud'])->report;
    $this->wb->addMessage($report, 'reporter', null, 'note');
    $this->wb->anonymize($report, 'test');
    $report = Report::query()->findOrFail($report->id);

    expect($report->subject)->toBeNull()->and($report->description)->toBeNull()
        ->and($report->reporter_email)->toBeNull()->and($report->category)->toBeNull()
        ->and($report->anonymized_at)->not->toBeNull()
        ->and($report->status)->toBe(ReportStatus::Archived)
        ->and($report->messages()->count())->toBe(0)
        ->and($report->received_at->toDateString())->toBe('2026-01-31');
});
