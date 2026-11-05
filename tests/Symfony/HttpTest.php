<?php

declare(strict_types=1);

use Whistleblower\Tests\Symfony\ApiTestCase;

uses(ApiTestCase::class);

const PAYLOAD = ['subject' => 'Harassment', 'description' => 'A long description', 'contact_email' => 'me@example.com'];

function submit(array $payload = PAYLOAD): string
{
    $body = test()->call('POST', '/whistleblower/reports', $payload);
    expect(test()->httpStatus())->toBe(201);

    return $body['tracking_code'];
}

it('accepts an anonymous report and returns the tracking code once, uncached', function (): void {
    $this->boot();
    $body = $this->call('POST', '/whistleblower/reports', ['subject' => 'S', 'description' => 'D']);

    expect($this->httpStatus())->toBe(201)
        ->and($body)->toHaveKeys(['tracking_code', 'received_at', 'acknowledgement_due_at'])
        ->and($this->client->getResponse()->headers->get('Cache-Control'))->toContain('no-store');
    expect($this->connection()->fetchOne('SELECT anonymous FROM whistleblower_reports'))->toBeIn([1, '1', true]);
});

it('validates the submission', function (): void {
    $this->boot();
    $body = $this->call('POST', '/whistleblower/reports', ['subject' => '', 'description' => str_repeat('x', 10001), 'contact_email' => 'nope']);

    expect($this->httpStatus())->toBe(422)->and($body['errors'])->toHaveKeys(['subject', 'description', 'contact_email']);
    $this->call('POST', '/whistleblower/reports', ['description' => 'only a description']);
    expect($this->httpStatus())->toBe(422);
});

it('rejects a body that is not JSON', function (): void {
    $this->boot();
    $this->client->request('POST', '/whistleblower/reports', server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');

    expect($this->httpStatus())->toBe(400);
});

it('restricts categories when configured', function (): void {
    $this->boot('test_categories');

    $this->call('POST', '/whistleblower/reports', ['subject' => 'S', 'description' => 'D', 'category' => 'other']);
    expect($this->httpStatus())->toBe(422);
    $this->call('POST', '/whistleblower/reports', ['subject' => 'S', 'description' => 'D', 'category' => 'fraud']);
    expect($this->httpStatus())->toBe(201);
});

it('lets the reporter follow the case with the code and exchange messages', function (): void {
    $this->boot();
    $code = submit();
    $report = $this->wb()->findByCode($code);
    $this->wb()->addMessage($report, 'manager', 42, 'Visible reply');
    $this->wb()->addMessage($report, 'manager', 42, 'Internal note', internal: true);

    $this->call('POST', '/whistleblower/reports/messages', ['tracking_code' => $code, 'body' => 'More info']);
    expect($this->httpStatus())->toBe(201);
    $status = $this->call('POST', '/whistleblower/reports/status', ['tracking_code' => strtolower($code)]);
    $raw = (string) $this->client->getResponse()->getContent();

    expect($this->httpStatus())->toBe(200)
        ->and($this->client->getResponse()->headers->get('Cache-Control'))->toContain('no-store')
        ->and($status['status'])->toBe('received')
        ->and(array_column($status['messages'], 'body'))->toBe(['Visible reply', 'More info'])
        ->and($raw)->not->toContain('Internal note')->not->toContain('me@example.com')->not->toContain('Harassment');
});

it('answers 404 to unknown codes without revealing anything', function (): void {
    $this->boot();

    $this->call('POST', '/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD']);
    expect($this->httpStatus())->toBe(404);
    $this->call('POST', '/whistleblower/reports/messages', ['tracking_code' => 'garbage', 'body' => 'x']);
    expect($this->httpStatus())->toBe(404);
    $this->client->request('GET', '/whistleblower/reports/status?tracking_code=AAAA');
    expect($this->httpStatus())->toBe(405);
});

it('rate-limits the public endpoints', function (): void {
    $this->boot('test_throttle');

    foreach (range(1, 2) as $_) {
        $this->call('POST', '/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD']);
        expect($this->httpStatus())->toBe(404);
    }
    $this->call('POST', '/whistleblower/reports/status', ['tracking_code' => 'AAAA-BBBB-CCCC-DDDD']);
    expect($this->httpStatus())->toBe(429)->and($this->client->getResponse()->headers->has('Retry-After'))->toBeTrue();
});

it('denies management to guests and to users without the attribute', function (): void {
    $this->boot();

    $this->call('GET', '/whistleblower/manage/reports');
    expect($this->httpStatus())->toBe(401);
    $this->call('GET', '/whistleblower/manage/reports', as: 'visitor');
    expect($this->httpStatus())->toBe(403);
});

it('lets an authorised manager run the full case', function (): void {
    $this->boot();
    submit();
    $id = $this->wb()->list(null, false, 1)->items[0]->id;
    $base = "/whistleblower/manage/reports/{$id}";

    $list = $this->call('GET', '/whistleblower/manage/reports', as: 'manager');
    expect($this->httpStatus())->toBe(200)
        ->and(json_encode($list))->not->toContain('Harassment')->not->toContain('me@example.com');

    expect($this->call('POST', "{$base}/acknowledge")['status'])->toBe('acknowledged');
    $this->call('POST', "{$base}/acknowledge");
    expect($this->httpStatus())->toBe(409);
    $this->call('POST', "{$base}/investigate");
    $this->call('POST', "{$base}/messages", ['body' => 'Could you clarify?']);
    expect($this->httpStatus())->toBe(201);
    expect($this->call('POST', "{$base}/extend", ['justification' => 'Complex'])['extended'])->toBeTrue();
    $this->call('POST', "{$base}/resolve", ['summary' => '']);
    expect($this->httpStatus())->toBe(422);
    expect($this->call('POST', "{$base}/resolve", ['summary' => 'Closed with measures'])['status'])->toBe('resolved');

    $show = $this->call('GET', $base);
    expect($show['subject'])->toBe('Harassment')->and($show['reporter_email'])->toBe('me@example.com')
        ->and(array_column($show['audit'], 'action'))->toContain('viewed', 'resolved');

    $this->call('POST', "{$base}/archive");
    $this->call('POST', "{$base}/messages", ['body' => 'late']);
    expect($this->httpStatus())->toBe(409);
    $this->call('GET', '/whistleblower/manage/reports/00000000000000000000000000');
    expect($this->httpStatus())->toBe(404);
});

it('filters the list by status and overdue', function (): void {
    $this->boot();
    submit();
    submit();
    $this->time('2026-01-09 09:00:00');

    expect($this->call('GET', '/whistleblower/manage/reports?overdue=1', as: 'manager')['total'])->toBe(2)
        ->and($this->call('GET', '/whistleblower/manage/reports?status=resolved')['total'])->toBe(0);
    $this->call('GET', '/whistleblower/manage/reports?status=bogus');
    expect($this->httpStatus())->toBe(422);
});
