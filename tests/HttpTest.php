<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Whistleblower\Models\Report;
use Whistleblower\Whistleblower;

beforeEach(function (): void {
    $this->payload = ['subject' => 'Harassment', 'description' => 'A long description', 'contact_email' => 'me@example.com'];
    $this->manager = new GenericUser(['id' => 42]);
});

function submitReport(array $payload): array
{
    return test()->postJson('/whistleblower/reports', $payload)->assertCreated()->json();
}

it('accepts an anonymous report and returns the tracking code once, uncached', function (): void {
    $response = $this->postJson('/whistleblower/reports', ['subject' => 'S', 'description' => 'D']);

    $response->assertCreated()->assertJsonStructure(['tracking_code', 'received_at', 'acknowledgement_due_at']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Report::query()->firstOrFail()->anonymous)->toBeTrue();
});

it('validates the submission', function (): void {
    $this->postJson('/whistleblower/reports', ['subject' => '', 'description' => str_repeat('x', 10001), 'contact_email' => 'nope'])
        ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'description', 'contact_email']);
});

it('restricts categories when configured', function (): void {
    config(['whistleblower.categories' => ['fraud', 'harassment']]);

    $this->postJson('/whistleblower/reports', ['subject' => 'S', 'description' => 'D', 'category' => 'other'])->assertUnprocessable();
    $this->postJson('/whistleblower/reports', ['subject' => 'S', 'description' => 'D', 'category' => 'fraud'])->assertCreated();
});

it('lets the reporter follow the case with the code and exchange messages', function (): void {
    $code = submitReport($this->payload)['tracking_code'];
    $report = Report::query()->firstOrFail();
    $wb = app(Whistleblower::class);
    $wb->addMessage($report, 'manager', 42, 'Visible reply');
    $wb->addMessage($report, 'manager', 42, 'Internal note', internal: true);

    $this->postJson('/whistleblower/reports/messages', ['tracking_code' => $code, 'body' => 'More info'])->assertCreated();
    $status = $this->postJson('/whistleblower/reports/status', ['tracking_code' => strtolower($code)])->assertOk();

    expect($status->headers->get('Cache-Control'))->toContain('no-store')
        ->and($status->json('status'))->toBe('received')
        ->and(collect($status->json('messages'))->pluck('body')->all())->toBe(['Visible reply', 'More info'])
        ->and($status->getContent())->not->toContain('Internal note')->not->toContain('me@example.com')->not->toContain('Harassment');
});

it('answers 404 to unknown codes without revealing anything', function (): void {
    $this->postJson('/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD'])->assertNotFound();
    $this->postJson('/whistleblower/reports/messages', ['tracking_code' => 'garbage', 'body' => 'x'])->assertNotFound();
    $this->getJson('/whistleblower/reports/status?tracking_code=AAAA')->assertStatus(405);
});

it('rate-limits the public endpoints', function (): void {
    config(['whistleblower.routes.throttle_per_minute' => 2]);

    foreach (range(1, 2) as $_) {
        $this->postJson('/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD'])->assertNotFound();
    }
    $this->postJson('/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD'])->assertTooManyRequests();
});

it('denies management to guests and to users without the ability', function (): void {
    $this->getJson('/whistleblower/manage/reports')->assertUnauthorized();
    $this->actingAs($this->manager)->getJson('/whistleblower/manage/reports')->assertForbidden();
});

it('lets an authorised manager run the full case', function (): void {
    Gate::define('whistleblower.manage', fn () => true);
    submitReport($this->payload);
    $id = Report::query()->firstOrFail()->id;
    $base = "/whistleblower/manage/reports/{$id}";
    $this->actingAs($this->manager);

    $list = $this->getJson('/whistleblower/manage/reports')->assertOk();
    expect($list->getContent())->not->toContain('Harassment')->not->toContain('me@example.com');

    $this->postJson("{$base}/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged');
    $this->postJson("{$base}/acknowledge")->assertStatus(409);
    $this->postJson("{$base}/investigate")->assertOk();
    $this->postJson("{$base}/messages", ['body' => 'Could you clarify?'])->assertCreated();
    $this->postJson("{$base}/extend", ['justification' => 'Complex'])->assertOk()->assertJsonPath('extended', true);
    $this->postJson("{$base}/resolve", ['summary' => ''])->assertUnprocessable();
    $this->postJson("{$base}/resolve", ['summary' => 'Closed with measures'])->assertOk()->assertJsonPath('status', 'resolved');

    $show = $this->getJson($base)->assertOk();
    expect($show->json('subject'))->toBe('Harassment')
        ->and($show->json('reporter_email'))->toBe('me@example.com')
        ->and(collect($show->json('audit'))->pluck('action')->all())->toContain('viewed', 'resolved');

    $this->postJson("{$base}/archive")->assertOk();
    $this->postJson("{$base}/messages", ['body' => 'late'])->assertStatus(409);
});

it('filters the list by status and overdue', function (): void {
    Gate::define('whistleblower.manage', fn () => true);
    submitReport($this->payload);
    submitReport($this->payload);
    $this->travel(8)->days();
    $this->actingAs($this->manager);

    expect($this->getJson('/whistleblower/manage/reports?overdue=1')->json('total'))->toBe(2)
        ->and($this->getJson('/whistleblower/manage/reports?status=resolved')->json('total'))->toBe(0);
    $this->getJson('/whistleblower/manage/reports?status=bogus')->assertUnprocessable();
});
