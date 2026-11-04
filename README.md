# Laravel Whistleblower

An internal whistleblowing channel for Laravel, built around Spain's
[Ley 2/2023](https://www.boe.es/buscar/act.php?id=BOE-A-2023-4513) (protección de las personas
que informen sobre infracciones). It gives you the parts that are tedious to get right:
anonymous reports with a tracking code, the legal deadlines, an audit trail and data retention.

> **This package helps; it does not make you compliant.** Complying with the law also needs
> a designated system manager, a written procedure, training and a data-protection assessment.
> Have a lawyer or your DPO review your setup. Status: pre-release, no independent security review yet.

## What it does

- **Anonymous reports.** The reporter gets a one-time tracking code (80 random bits). Only its
  SHA-256 hash is stored. Contact data is optional.
- **Two-way anonymous messaging** between reporter and manager using that code.
- **Legal deadlines** (art. 9.2): acknowledgement within 7 days, resolution within 3 months,
  one extension of up to 3 more months. A scheduled command alerts you before each deadline and every day after it is missed, until the report is closed.
- **Retention** (arts. 26 and 32): content of reports with no investigation after 90 days is
  anonymised, and registry entries are deleted after 10 years. Both are configurable.
- **Audit trail** of every state change and every time a manager opens a report. It never stores content.
- **Encryption at rest** of subject, description, contact data, resolution and messages.
- **JSON API** for the public form and for case management. No UI is included.

## Requirements

PHP 8.2+, Laravel 11, 12 or 13. Content is encrypted with your `APP_KEY`: **if you rotate or lose
it, existing reports become unreadable.**

## Installation

```bash
composer require dmarsal90/laravel-whistleblower
php artisan migrate
php artisan vendor:publish --tag=whistleblower-config
```

Schedule the two commands daily (`routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('whistleblower:check-deadlines')->daily();
Schedule::command('whistleblower:apply-retention')->daily();
```

Define who can manage cases. **Until you do, nobody can**:

```php
Gate::define('whistleblower.manage', fn ($user) => $user->isSystemManager());
```

Set `notify` in `config/whistleblower.php` to get deadline alerts by e-mail. The alerts carry
the report ID and the due date, never its content. For other channels, listen to
`Whistleblower\Events\DeadlineReached`.

## API

Public routes (prefix `whistleblower`, throttled, JSON). The tracking code goes in the body, never in the URL.

| Method | Path | Purpose |
|---|---|---|
| POST | `reports` | Submit. Returns `tracking_code` once. |
| POST | `reports/status` | `tracking_code` → status, deadlines, reply messages, resolution. |
| POST | `reports/messages` | `tracking_code`, `body` → add a message. |

Management routes (`manage/...`, middleware `auth` + `can:whistleblower.manage` by default):

| Method | Path | Purpose |
|---|---|---|
| GET | `manage/reports` | List (`status`, `overdue`). No content or contact data. |
| GET | `manage/reports/{id}` | Full case. Recorded in the audit trail. |
| POST | `manage/reports/{id}/acknowledge` `investigate` `extend` `resolve` `archive` `messages` | State changes and messages. Invalid transitions return 409. |

## Not included (yet)

- Verbal channel (art. 7.2): phone or voice messages, and in-person meetings.
- File attachments.
- Per-report access lists. Art. 32.1 limits access to the manager, plus HR or legal only when
  measures may follow. Today one ability controls everything, so enforce that split in your Gate.
- A UI. Build your own on the API.

## Privacy notes

- No IP or user agent is stored with a report. The rate limiter keys on the IP in your cache
  for the throttle window only. Check that your web server and proxies do not log request bodies.
- The 3-month resolution deadline always counts from the date of receipt. Art. 9.2.d allows a
  later start when no acknowledgement was sent, so alerts can only be early, never late.

## Development

```bash
composer install
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan + Larastan
```

## Licence

MIT.
