<?php

declare(strict_types=1);

use Whistleblower\DeadlineChecker;
use Whistleblower\Enums\Deadline;
use Whistleblower\Retention;
use Whistleblower\Tests\Support\FrozenClock;
use Whistleblower\Tests\Support\InMemoryReportStore;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    $this->clock = FrozenClock::at('2026-01-01 09:00:00');
    $this->store = new InMemoryReportStore;
    $this->wb = new Whistleblower($this->store, $this->clock, ['acknowledgement_days' => 7, 'resolution_months' => 3, 'extension_months' => 3]);
    $this->checker = new DeadlineChecker($this->store, $this->clock, ['warn_acknowledgement_days' => 2, 'warn_resolution_days' => 14]);
    $this->retention = fn (?int $closed = null) => new Retention($this->store, $this->wb, $this->clock, ['uninvestigated_days' => 90, 'closed_content_days' => $closed, 'registry_years' => 10]);
    $this->submit = fn () => $this->wb->submit(['subject' => 'S', 'description' => 'D', 'contact_email' => 'a@b.es'])->report;
});

it('alerts when the acknowledgement deadline is near and when it is missed', function (): void {
    $report = ($this->submit)();

    $this->clock->set('2026-01-05 09:00:00'); // 3 days left: outside the 2-day warning
    expect($this->checker->check())->toBe([]);

    $this->clock->set('2026-01-06 09:00:00'); // due 2026-01-08: inside the warning
    $events = $this->checker->check();
    expect($events)->toHaveCount(1)
        ->and($events[0]->reportId)->toBe($report->id)
        ->and($events[0]->deadline)->toBe(Deadline::Acknowledgement)
        ->and($events[0]->overdue)->toBeFalse();

    $this->clock->set('2026-01-09 09:00:00');
    expect($this->checker->check()[0]->overdue)->toBeTrue();
});

it('stops the acknowledgement alert once acknowledged and the resolution alert once resolved', function (): void {
    $report = $this->wb->acknowledge(($this->submit)(), 1);

    $this->clock->set('2026-01-09 09:00:00');
    expect($this->checker->check())->toBe([]);

    $this->clock->set('2026-04-02 09:00:00'); // resolution due 2026-04-01
    $events = $this->checker->check();
    expect($events)->toHaveCount(1)->and($events[0]->deadline)->toBe(Deadline::Resolution)->and($events[0]->overdue)->toBeTrue();

    $this->wb->resolve($this->wb->startInvestigation($report, 1), 1, 'done');
    expect($this->checker->check())->toBe([]);
});

it('walks more reports than one batch', function (): void {
    foreach (range(1, 130) as $_) {
        ($this->submit)();
    }
    $this->clock->set('2026-01-09 09:00:00');

    expect($this->checker->check())->toHaveCount(130);
});

it('anonymises reports with no investigation after 90 days only', function (): void {
    $stale = ($this->submit)();
    $investigated = $this->wb->startInvestigation(($this->submit)(), 1);

    $this->clock->set('2026-03-31 09:00:00'); // day 89
    expect(($this->retention)()->apply())->toBe(['anonymised' => 0, 'deleted' => 0]);

    $this->clock->set('2026-04-02 09:00:00'); // day 91
    expect(($this->retention)()->apply())->toBe(['anonymised' => 1, 'deleted' => 0])
        ->and($this->wb->find($stale->id)->anonymizedAt)->not->toBeNull()
        ->and($this->wb->find($stale->id)->reporterEmail)->toBeNull()
        ->and($this->wb->find($investigated->id)->anonymizedAt)->toBeNull()
        ->and($this->wb->find($investigated->id)->subject)->toBe('S');
});

it('anonymises closed reports only when closed_content_days is set', function (): void {
    $report = $this->wb->archive(($this->submit)(), 1);
    $this->wb->startInvestigation(($this->submit)(), 1);
    $this->clock->set('2026-02-10 09:00:00');

    ($this->retention)()->apply();
    expect($this->wb->find($report->id)->subject)->toBe('S');

    ($this->retention)(30)->apply();
    expect($this->wb->find($report->id)->subject)->toBeNull();
});

it('deletes registry entries after ten years, with their messages and audit rows', function (): void {
    $report = $this->wb->startInvestigation(($this->submit)(), 1);
    $this->wb->addMessage($report, 'reporter', null, 'hi');

    $this->clock->set('2035-12-31 09:00:00');
    expect(($this->retention)()->apply()['deleted'])->toBe(0);

    $this->clock->set('2036-01-02 09:00:00');
    expect(($this->retention)()->apply()['deleted'])->toBe(1)
        ->and($this->store->reports)->toBe([])
        ->and($this->store->audit)->toBe([])
        ->and($this->store->messages)->toBe([]);
});
