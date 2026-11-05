<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Laravel\Models\AuditLog;
use Whistleblower\Laravel\Models\Report;
use Whistleblower\Laravel\Notifications\DeadlineAlert;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    Carbon::setTestNow('2026-01-01 09:00:00');
    $this->wb = app(Whistleblower::class);
    $this->submit = fn (array $extra = []) => $this->wb->submit(['subject' => 'Invoice fraud', 'description' => 'Details of the case'] + $extra);
});

it('stores the tracking code hash and never the code', function (): void {
    $submission = ($this->submit)();

    expect(DB::table('whistleblower_reports')->value('tracking_code_hash'))->toBe($submission->report->trackingCodeHash)
        ->and(json_encode(DB::table('whistleblower_reports')->first()))->not->toContain(str_replace('-', '', $submission->trackingCode));
});

it('encrypts content and contact data at rest', function (): void {
    $report = ($this->submit)(['contact_name' => 'Marta Ruiz', 'contact_email' => 'marta@example.com'])->report;
    $this->wb->addMessage($report, 'reporter', null, 'secret follow-up');
    $this->wb->resolve($this->wb->startInvestigation($report, 1), 1, 'Private outcome');

    $raw = json_encode(DB::table('whistleblower_reports')->first());
    expect($raw)->not->toContain('Invoice fraud')->not->toContain('Details of the case')
        ->not->toContain('Marta')->not->toContain('marta@example.com')->not->toContain('Private outcome')
        ->and(json_encode(DB::table('whistleblower_messages')->get()))->not->toContain('secret follow-up')
        ->and($this->wb->find($report->id)->reporterEmail)->toBe('marta@example.com');
});

it('round-trips every field through the store', function (): void {
    $report = ($this->submit)(['contact_email' => 'a@b.es', 'category' => 'fraud'])->report;
    $loaded = $this->wb->find($report->id);

    expect($loaded->subject)->toBe('Invoice fraud')->and($loaded->category)->toBe('fraud')
        ->and($loaded->receivedAt->format('Y-m-d H:i:s'))->toBe('2026-01-01 09:00:00')
        ->and($loaded->acknowledgementDueAt->format('Y-m-d'))->toBe('2026-01-08')
        ->and($loaded->extendedAt)->toBeNull();
});

it('deletes children explicitly when purging, without relying on cascades', function (): void {
    $report = $this->wb->startInvestigation(($this->submit)()->report, 1);
    $this->wb->addMessage($report, 'reporter', null, 'hi');

    Carbon::setTestNow('2036-01-02 09:00:00');
    $this->artisan('whistleblower:apply-retention')->assertSuccessful();

    expect(Report::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0)
        ->and(DB::table('whistleblower_messages')->count())->toBe(0);
});

it('dispatches deadline events from the command', function (): void {
    Event::fake([DeadlineReached::class]);
    ($this->submit)();

    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines')->assertSuccessful();

    Event::assertDispatchedTimes(DeadlineReached::class, 1);
});

it('mails the configured recipients without report content', function (): void {
    Notification::fake();
    config(['whistleblower.notify' => ['dpo@example.com']]);
    $report = ($this->submit)(['contact_email' => 'a@b.es'])->report;

    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');

    Notification::assertSentOnDemand(DeadlineAlert::class, function (DeadlineAlert $n, array $channels, $notifiable) use ($report): bool {
        $mail = $n->toMail($notifiable);
        $text = implode(' ', $mail->introLines).$mail->subject;

        return $notifiable->routes['mail'] === ['dpo@example.com']
            && str_contains($text, $report->id)
            && ! str_contains($text, 'a@b.es') && ! str_contains($text, 'Invoice fraud');
    });
});

it('sends nothing when no recipients are configured', function (): void {
    Notification::fake();
    ($this->submit)();
    Carbon::setTestNow('2026-01-09 09:00:00');
    $this->artisan('whistleblower:check-deadlines');

    Notification::assertNothingSent();
});

it('anonymises through the command', function (): void {
    $stale = ($this->submit)()->report;
    Carbon::setTestNow('2026-04-02 09:00:00');

    $this->artisan('whistleblower:apply-retention')->assertSuccessful();

    expect($this->wb->find($stale->id)->anonymizedAt)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'anonymized')->count())->toBe(1);
});
