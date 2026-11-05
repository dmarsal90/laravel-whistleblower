<?php

declare(strict_types=1);

use Whistleblower\Enums\ReportStatus;
use Whistleblower\Exceptions\InvalidTransition;
use Whistleblower\Tests\Support\FrozenClock;
use Whistleblower\Tests\Support\InMemoryReportStore;
use Whistleblower\TrackingCode;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    $this->clock = FrozenClock::at('2026-01-31 10:00:00');
    $this->store = new InMemoryReportStore;
    $this->wb = new Whistleblower($this->store, $this->clock, ['acknowledgement_days' => 7, 'resolution_months' => 3, 'extension_months' => 3]);
    $this->submit = fn (array $extra = []) => $this->wb->submit(['subject' => 'Invoice fraud', 'description' => 'Details of the case'] + $extra);
});

it('stores only the hash of the tracking code', function (): void {
    $submission = ($this->submit)();

    expect($submission->report->trackingCodeHash)->toBe(TrackingCode::hash((string) TrackingCode::normalize($submission->trackingCode)))
        ->and(json_encode($this->store->reports))->not->toContain(str_replace('-', '', $submission->trackingCode));
    expect($this->wb->findByCode(strtolower($submission->trackingCode))?->id)->toBe($submission->report->id)
        ->and($this->wb->findByCode('0000-0000-0000-0000'))->toBeNull()
        ->and($this->wb->findByCode('nonsense'))->toBeNull();
});

it('sets the legal deadlines at submission', function (): void {
    $report = ($this->submit)()->report;

    expect($report->status)->toBe(ReportStatus::Received)
        ->and($report->acknowledgementDueAt->format('Y-m-d'))->toBe('2026-02-07')
        ->and($report->resolutionDueAt->format('Y-m-d'))->toBe('2026-04-30');
});

it('marks a report anonymous only when no contact data is given', function (): void {
    expect(($this->submit)()->report->anonymous)->toBeTrue()
        ->and(($this->submit)(['contact_name' => '  ', 'contact_email' => ''])->report->anonymous)->toBeTrue()
        ->and(($this->submit)(['contact_email' => 'a@b.es'])->report->anonymous)->toBeFalse();
});

it('follows the happy path and audits every step', function (): void {
    $report = ($this->submit)()->report;
    $report = $this->wb->acknowledge($report, 7);
    $report = $this->wb->startInvestigation($report, 7);
    $report = $this->wb->resolve($report, 7, 'Measures adopted');
    $report = $this->wb->archive($report, 7);

    expect($report->status)->toBe(ReportStatus::Archived)
        ->and($report->acknowledgedAt)->not->toBeNull()
        ->and($report->resolution)->toBe('Measures adopted')
        ->and(array_map(fn ($a) => $a->action, $this->wb->auditTrail($report)))
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
    $stale = $this->wb->find($report->id);
    $this->wb->archive($report, 1);

    expect(fn () => $this->wb->acknowledge($stale, 1))->toThrow(InvalidTransition::class);
});

it('extends the resolution deadline once, to six months', function (): void {
    $report = $this->wb->startInvestigation(($this->submit)()->report, 1);
    $report = $this->wb->extendDeadline($report, 1, 'Complex case with many parties');

    expect($report->resolutionDueAt->format('Y-m-d'))->toBe('2026-07-31')
        ->and(fn () => $this->wb->extendDeadline($report, 1, 'again'))->toThrow(InvalidTransition::class);
    expect(json_encode($this->store->audit))->not->toContain('Complex case')
        ->and($this->wb->messages($report, false))->toBe([])
        ->and($this->wb->messages($report, true)[0]->body)->toBe('Complex case with many parties');
});

it('hides internal notes from the reporter view', function (): void {
    $report = ($this->submit)()->report;
    $this->wb->addMessage($report, 'manager', 1, 'Visible');
    $this->wb->addMessage($report, 'manager', 1, 'Internal', internal: true);

    expect(array_map(fn ($m) => $m->body, $this->wb->messages($report, false)))->toBe(['Visible'])
        ->and(array_map(fn ($m) => $m->body, $this->wb->messages($report, true)))->toBe(['Visible', 'Internal']);
});

it('refuses messages on archived reports', function (): void {
    $report = $this->wb->archive(($this->submit)()->report, 1);

    expect(fn () => $this->wb->addMessage($report, 'reporter', null, 'hi'))->toThrow(InvalidTransition::class);
});

it('anonymises content but keeps the registry entry', function (): void {
    $report = ($this->submit)(['contact_email' => 'a@b.es', 'category' => 'fraud'])->report;
    $this->wb->addMessage($report, 'reporter', null, 'note');
    $this->wb->anonymize($report, 'test');
    $report = $this->wb->find($report->id);

    expect($report->subject)->toBeNull()->and($report->description)->toBeNull()
        ->and($report->reporterEmail)->toBeNull()->and($report->category)->toBeNull()
        ->and($report->anonymizedAt)->not->toBeNull()
        ->and($report->status)->toBe(ReportStatus::Archived)
        ->and($this->wb->messages($report, true))->toBe([])
        ->and($report->receivedAt->format('Y-m-d'))->toBe('2026-01-31');
});

it('lists reports newest first, filtered by status and overdue', function (): void {
    ($this->submit)();
    $this->clock->set('2026-02-01 10:00:00');
    $second = ($this->submit)()->report;
    $this->wb->archive($second, 1);
    $this->clock->set('2026-02-09 10:00:00'); // first report is past its acknowledgement deadline

    expect($this->wb->list(null, false, 1)->total)->toBe(2)
        ->and($this->wb->list(ReportStatus::Archived, false, 1)->items[0]->id)->toBe($second->id)
        ->and($this->wb->list(null, true, 1)->total)->toBe(1);
});
