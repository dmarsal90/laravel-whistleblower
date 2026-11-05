# Whistleblower

An internal whistleblowing channel for **Laravel and Symfony**, built around Spain's
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
  one extension of up to 3 more months. A scheduled command alerts you before each deadline and every
  day after it is missed, until the report is closed.
- **Retention** (arts. 26 and 32): content of reports with no investigation after 90 days is
  anonymised, and registry entries are deleted after 10 years. Both are configurable.
- **Audit trail** of every state change and every time a manager opens a report. It never stores content.
- **Encryption at rest** of subject, description, contact data, resolution and messages.
- **JSON API** for the public form and for case management. No UI is included.

The rules live in a framework-free core. Laravel (Eloquent) and Symfony (Doctrine DBAL) are thin adapters
over the same code, and both run the same behaviour through their own HTTP, console and storage layers.

## Requirements

| | PHP | Framework |
|---|---|---|
| Laravel | 8.2+ with Laravel 12, 8.3+ with Laravel 13 | `illuminate/*`, any supported database |
| Symfony | 8.2+ with Symfony 7.4, 8.4+ with Symfony 8 | `symfony/framework-bundle`, `security-bundle`, `rate-limiter`, `validator`, `doctrine/dbal` 4, `ext-sodium` |

Personal data is encrypted with a key you control (`APP_KEY` on Laravel, `encryption_key` on Symfony,
which defaults to `kernel.secret`). **If you rotate or lose it, existing reports become unreadable.**
Tested on SQLite and MySQL 8.4.

## Installation: Laravel

```bash
composer require dmarsal90/whistleblower
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

## Installation: Symfony

```bash
composer require dmarsal90/whistleblower symfony/security-bundle symfony/rate-limiter symfony/validator doctrine/dbal
```

Register the bundle (`config/bundles.php`), import the routes and configure it:

```php
Whistleblower\Symfony\WhistleblowerBundle::class => ['all' => true],
```

```yaml
# config/routes/whistleblower.yaml
whistleblower:
    resource: '@WhistleblowerBundle/Controller/'
    type: attribute
    prefix: /whistleblower

# config/packages/whistleblower.yaml
whistleblower:
    connection: doctrine.dbal.default_connection   # service id of your DBAL connection
    encryption_key: '%env(WHISTLEBLOWER_KEY)%'      # defaults to %kernel.secret%
    notify: ['dpo@example.com']                     # optional, needs symfony/mailer
    notify_from: 'canal@example.com'
```

Create the tables, or print the SQL to paste into a Doctrine migration:

```bash
bin/console whistleblower:schema:install            # or --dump-sql
```

If you use `doctrine:migrations:diff`, exclude the tables so it does not try to drop them:
`doctrine.dbal.schema_filter: '~^(?!whistleblower_)~'`.

Grant the management attribute with a voter. **Until you do, nobody can**:

```php
final class WhistleblowerManagerVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'WHISTLEBLOWER_MANAGE';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array('ROLE_SYSTEM_MANAGER', $token->getRoleNames(), true);
    }
}
```

Run `whistleblower:check-deadlines` and `whistleblower:apply-retention` daily (cron or Symfony Scheduler).
Protect the management routes with a firewall that authenticates your managers.

## API

Public routes (prefix `whistleblower`, rate-limited, JSON). The tracking code goes in the body, never in the URL.

| Method | Path | Purpose |
|---|---|---|
| POST | `reports` | Submit. Returns `tracking_code` once. |
| POST | `reports/status` | `tracking_code` → status, deadlines, reply messages, resolution. |
| POST | `reports/messages` | `tracking_code`, `body` → add a message. |

Management routes (`manage/...`):

| Method | Path | Purpose |
|---|---|---|
| GET | `manage/reports` | List (`status`, `overdue`, `page`). No content or contact data. |
| GET | `manage/reports/{id}` | Full case. Recorded in the audit trail. |
| POST | `manage/reports/{id}/acknowledge` `investigate` `extend` `resolve` `archive` `messages` | State changes and messages. Invalid transitions return 409. |

Unauthenticated requests get 401 and authenticated users without the ability get 403.

## Not included (yet)

- Verbal channel (art. 7.2): phone or voice messages, and in-person meetings.
- File attachments.
- Per-report access lists. Art. 32.1 limits access to the manager, plus HR or legal only when
  measures may follow. Today one ability controls everything, so enforce that split in your Gate or voter.
- A UI. Build your own on the API.

## Privacy notes

- No IP or user agent is stored with a report. The rate limiter keys on the IP in your cache
  for the throttle window only. Check that your web server and proxies do not log request bodies.
- The 3-month resolution deadline always counts from the date of receipt. Art. 9.2.d allows a
  later start when no acknowledgement was sent, so alerts can only be early, never late.

## Development

```bash
composer install
composer test      # Pest: core, Laravel and Symfony
composer lint      # Pint
composer analyse   # PHPStan + Larastan
```

Set `DB_CONNECTION=mysql` (and `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) to run the
storage tests against MySQL.

## Licence

MIT.
