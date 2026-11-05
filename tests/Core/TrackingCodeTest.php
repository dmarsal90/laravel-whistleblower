<?php

declare(strict_types=1);

use Whistleblower\TrackingCode;

it('generates 16 Crockford characters in four groups', function (): void {
    expect(TrackingCode::generate())->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/');
});

it('does not repeat', function (): void {
    $codes = array_map(fn () => TrackingCode::generate(), range(1, 200));

    expect(array_unique($codes))->toHaveCount(200);
});

it('normalizes case, separators and look-alike characters', function (): void {
    expect(TrackingCode::normalize('abcd-efgh-jkmn-pqrs'))->toBe('ABCDEFGHJKMNPQRS')
        ->and(TrackingCode::normalize(' 0O1I-L000 0000 0000 '))->toBe('0011100000000000');
});

it('rejects input that cannot be a code', function (string $input): void {
    expect(TrackingCode::normalize($input))->toBeNull();
})->with(['', 'short', 'ABCD-EFGH-JKMN-PQR', 'ABCD-EFGH-JKMN-PQRSS', 'ABCD-EFGH-JKMN-PQR!', 'ABCD-EFGH-JKMN-PQRU']);

it('hashes deterministically', function (): void {
    expect(TrackingCode::hash('ABCDEFGHJKMNPQRS'))->toBe(hash('sha256', 'ABCDEFGHJKMNPQRS'));
});
