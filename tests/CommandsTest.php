<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Whistleblower\Enums\Deadline;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Models\AuditLog;
use Whistleblower\Models\Report;
use Whistleblower\Notifications\DeadlineAlert;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    Carbon::setTestNow('2026-01-01 09:00:00');
    $this->wb = app(Whistleblower::class);
    $this->submit = fn () => $this->wb->submit(['subject' => 'S', 'description' => 'D', 'contact_email' => 'a@b.es'])->report;
});

it('alerts when the acknowledgement deadline is near and when it is missed', function (): void {
    Event::fake([DeadlineReached::class]);
    $report = ($this->submit)();

    Carbon::setTestNow('2026-01-05 09:00:00'); // 3 days left: outside the 2-day warning
    $this->artisan('whistleblower:check-deadlines')->assertSuccessful();
    Event::assertNotDispatched(DeadlineReached::class);

    Carbon::setTestNow('2026-01-06 09:00:00'); // due 2026-01-08: inside the warning
    $this->artisan('whistleblower:check-deadlines');
    Event::assertDispatched(DeadlineReached::class, fn ($e) => $e->reportId === $report->id && $e->deadline === Deadline::Acknowledgement && ! $e->overdue);

    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');
    Event::assertDispatched(DeadlineReached::class, fn ($e) => $e->deadline === Deadline::Acknowledgement && $e->overdue);
});

it('stops the acknowledgement alert once acknowledged and the resolution alert once resolved', function (): void {
    Event::fake([DeadlineReached::class]);
    $report = ($this->submit)();
    $report = $this->wb->acknowledge($report, 1);

    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');
    Event::assertNotDispatched(DeadlineReached::class);

    Carbon::setTestNow('2026-04-02 09:00:00'); // resolution due 2026-04-01
    $this->artisan('whistleblower:check-deadlines');
    Event::assertDispatched(DeadlineReached::class, fn ($e) => $e->deadline === Deadline::Resolution && $e->overdue);

    Event::fake([DeadlineReached::class]);
    $this->wb->resolve($this->wb->startInvestigation($report, 1), 1, 'done');
    $this->artisan('whistleblower:check-deadlines');
    Event::assertNotDispatched(DeadlineReached::class);
});

it('mails the configured recipients without report content', function (): void {
    Notification::fake();
    config(['whistleblower.notify' => ['dpo@example.com']]);
    $report = ($this->submit)();

    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');

    Notification::assertSentOnDemand(DeadlineAlert::class, function (DeadlineAlert $n, array $channels, $notifiable) use ($report): bool {
        $mail = $n->toMail($notifiable);

        return $notifiable->routes['mail'] === ['dpo@example.com']
            && str_contains(implode(' ', $mail->introLines), $report->id)
            && ! str_contains(implode(' ', $mail->introLines).$mail->subject, 'a@b.es');
    });
});

it('sends nothing when no recipients are configured', function (): void {
    Notification::fake();
    ($this->submit)();
    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');

    Notification::assertNothingSent();
});

it('anonymises reports with no investigation after 90 days only', function (): void {
    $stale = ($this->submit)();
    $investigated = $this->wb->startInvestigation(($this->submit)(), 1);

    Carbon::setTestNow('2026-03-31 09:00:00'); // day 89
    $this->artisan('whistleblower:apply-retention');
    expect(Report::query()->whereNotNull('anonymized_at')->count())->toBe(0);

    Carbon::setTestNow('2026-04-02 09:00:00'); // day 91
    $this->artisan('whistleblower:apply-retention')->assertSuccessful();

    expect(Report::query()->findOrFail($stale->id)->anonymized_at)->not->toBeNull()
        ->and(Report::query()->findOrFail($stale->id)->reporter_email)->toBeNull()
        ->and(Report::query()->findOrFail($investigated->id)->anonymized_at)->toBeNull()
        ->and(Report::query()->findOrFail($investigated->id)->subject)->toBe('S')
        ->and(AuditLog::query()->where('action', 'anonymized')->count())->toBe(1);
});

it('anonymises closed reports only when closed_content_days is set', function (): void {
    $report = $this->wb->archive(($this->submit)(), 1);
    $this->wb->startInvestigation(($this->submit)(), 1);
    Carbon::setTestNow('2026-02-10 09:00:00');

    $this->artisan('whistleblower:apply-retention');
    expect(Report::query()->findOrFail($report->id)->subject)->toBe('S');

    config(['whistleblower.retention.closed_content_days' => 30]);
    $this->artisan('whistleblower:apply-retention');
    expect(Report::query()->findOrFail($report->id)->subject)->toBeNull();
});

it('deletes registry entries after ten years, with their messages and audit rows', function (): void {
    $report = $this->wb->startInvestigation(($this->submit)(), 1);
    $this->wb->addMessage($report, 'reporter', null, 'hi');

    Carbon::setTestNow('2035-12-31 09:00:00');
    $this->artisan('whistleblower:apply-retention');
    expect(Report::query()->count())->toBe(1);

    Carbon::setTestNow('2036-01-02 09:00:00');
    $this->artisan('whistleblower:apply-retention');
    expect(Report::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(DB::table('whistleblower_messages')->count())->toBe(0);
});
