<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use Whistleblower\Symfony\Store\SodiumCipher;
use Whistleblower\Tests\Symfony\ApiTestCase;

uses(ApiTestCase::class);

function command(string $name): CommandTester
{
    return test()->console($name);
}

it('stores the tracking code hash and never the code', function (): void {
    $this->boot();
    $submission = $this->wb()->submit(['subject' => 'S', 'description' => 'D']);

    expect($this->connection()->fetchOne('SELECT tracking_code_hash FROM whistleblower_reports'))->toBe($submission->report->trackingCodeHash)
        ->and(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM whistleblower_reports')))->not->toContain(str_replace('-', '', $submission->trackingCode));
});

it('encrypts content and contact data at rest', function (): void {
    $this->boot();
    $report = $this->wb()->submit(['subject' => 'Invoice fraud', 'description' => 'Details of the case', 'contact_name' => 'Marta Ruiz', 'contact_email' => 'marta@example.com'])->report;
    $this->wb()->addMessage($report, 'reporter', null, 'secret follow-up');
    $this->wb()->resolve($this->wb()->startInvestigation($report, 1), 1, 'Private outcome');

    $raw = json_encode($this->connection()->fetchAllAssociative('SELECT * FROM whistleblower_reports'));
    expect($raw)->not->toContain('Invoice fraud')->not->toContain('Details of the case')->not->toContain('Marta')
        ->not->toContain('marta@example.com')->not->toContain('Private outcome')
        ->and(json_encode($this->connection()->fetchAllAssociative('SELECT * FROM whistleblower_messages')))->not->toContain('secret follow-up')
        ->and($this->wb()->find($report->id)->reporterEmail)->toBe('marta@example.com');
});

it('round-trips every field through the store', function (): void {
    $this->boot();
    $report = $this->wb()->submit(['subject' => 'S', 'description' => 'D', 'contact_email' => 'a@b.es', 'category' => 'fraud'])->report;
    $loaded = $this->wb()->find($report->id);

    expect($loaded->category)->toBe('fraud')->and($loaded->anonymous)->toBeFalse()
        ->and($loaded->receivedAt->format('Y-m-d H:i:s'))->toBe('2026-01-01 09:00:00')
        ->and($loaded->acknowledgementDueAt->format('Y-m-d'))->toBe('2026-01-08')
        ->and($loaded->extendedAt)->toBeNull();
});

it('refuses to decrypt with the wrong key or tampered data', function (): void {
    $stored = (new SodiumCipher('key-a'))->encrypt('secret');

    expect((new SodiumCipher('key-a'))->decrypt($stored))->toBe('secret')
        ->and((new SodiumCipher('key-a'))->encrypt(null))->toBeNull()
        ->and(fn () => (new SodiumCipher('key-b'))->decrypt($stored))->toThrow(RuntimeException::class)
        ->and(fn () => (new SodiumCipher('key-a'))->decrypt('v1:'.base64_encode('short')))->toThrow(RuntimeException::class)
        ->and(fn () => (new SodiumCipher('key-a'))->decrypt('plain text'))->toThrow(RuntimeException::class)
        ->and(fn () => new SodiumCipher(''))->toThrow(RuntimeException::class);
});

it('deletes children explicitly when purging, without relying on cascades', function (): void {
    $this->boot();
    $report = $this->wb()->startInvestigation($this->wb()->submit(['subject' => 'S', 'description' => 'D'])->report, 1);
    $this->wb()->addMessage($report, 'reporter', null, 'hi');

    $this->time('2036-01-02 09:00:00');
    $output = command('whistleblower:apply-retention');
    $output->execute([]);

    expect($output->getStatusCode())->toBe(0)
        ->and($this->connection()->fetchOne('SELECT COUNT(*) FROM whistleblower_reports'))->toBe(0)
        ->and($this->connection()->fetchOne('SELECT COUNT(*) FROM whistleblower_messages'))->toBe(0)
        ->and($this->connection()->fetchOne('SELECT COUNT(*) FROM whistleblower_audit_logs'))->toBe(0);
});

it('anonymises through the command', function (): void {
    $this->boot();
    $stale = $this->wb()->submit(['subject' => 'S', 'description' => 'D', 'contact_email' => 'a@b.es'])->report;
    $this->time('2026-04-02 09:00:00');

    command('whistleblower:apply-retention')->execute([]);

    expect($this->wb()->find($stale->id)->anonymizedAt)->not->toBeNull()
        ->and($this->wb()->find($stale->id)->reporterEmail)->toBeNull();
});

it('reports deadline alerts from the command and mails the recipients without content', function (): void {
    $this->boot('test_notify');
    $report = $this->wb()->submit(['subject' => 'Invoice fraud', 'description' => 'D', 'contact_email' => 'a@b.es'])->report;
    $this->time('2026-01-09 09:00:00');

    $tester = command('whistleblower:check-deadlines');
    $tester->execute([]);

    $this->assertEmailCount(1);
    $mail = $this->getMailerMessage();
    expect($tester->getDisplay())->toContain('1 deadline alert')
        ->and($mail->getTo()[0]->getAddress())->toBe('dpo@example.com')
        ->and($mail->getTextBody())->toContain($report->id)->not->toContain('a@b.es')->not->toContain('Invoice fraud');
});

it('sends no mail when no recipients are configured', function (): void {
    $this->boot();
    $this->wb()->submit(['subject' => 'S', 'description' => 'D']);
    $this->time('2026-01-09 09:00:00');

    command('whistleblower:check-deadlines')->execute([]);

    $this->assertEmailCount(0);
});

it('installs the schema, skipping tables that exist, and can print the SQL', function (): void {
    $this->boot();
    $manager = $this->connection()->createSchemaManager();
    $manager->dropTable('whistleblower_audit_logs');

    $dump = command('whistleblower:schema:install');
    $dump->execute(['--dump-sql' => true]);
    expect($dump->getDisplay())->toContain('CREATE TABLE whistleblower_audit_logs')->not->toContain('CREATE TABLE whistleblower_reports');

    $install = command('whistleblower:schema:install');
    $install->execute([]);
    expect($install->getDisplay())->toContain('Created whistleblower_audit_logs')
        ->and($manager->tablesExist(['whistleblower_audit_logs']))->toBeTrue();
    $again = command('whistleblower:schema:install');
    $again->execute([]);
    expect($again->getDisplay())->toContain('Nothing to do');
});
