<?php

declare(strict_types=1);

namespace Whistleblower\Laravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Whistleblower\DeadlineChecker;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Laravel\Console\ApplyRetentionCommand;
use Whistleblower\Laravel\Console\CheckDeadlinesCommand;
use Whistleblower\Laravel\Notifications\DeadlineAlert;
use Whistleblower\Laravel\Store\EloquentReportStore;
use Whistleblower\Port\ReportStore;
use Whistleblower\Retention;
use Whistleblower\Whistleblower;

final class WhistleblowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/whistleblower.php', 'whistleblower');

        $this->app->singleton(ReportStore::class, EloquentReportStore::class);
        $this->app->singleton(ClockInterface::class, Clock::class);

        $this->app->singleton(Whistleblower::class, fn (Application $app): Whistleblower => new Whistleblower(
            $app->make(ReportStore::class),
            $app->make(ClockInterface::class),
            [
                'acknowledgement_days' => (int) config('whistleblower.deadlines.acknowledgement_days'),
                'resolution_months' => (int) config('whistleblower.deadlines.resolution_months'),
                'extension_months' => (int) config('whistleblower.deadlines.extension_months'),
            ],
        ));

        $this->app->singleton(DeadlineChecker::class, fn (Application $app): DeadlineChecker => new DeadlineChecker(
            $app->make(ReportStore::class),
            $app->make(ClockInterface::class),
            [
                'warn_acknowledgement_days' => (int) config('whistleblower.deadlines.warn_acknowledgement_days'),
                'warn_resolution_days' => (int) config('whistleblower.deadlines.warn_resolution_days'),
            ],
        ));

        $this->app->singleton(Retention::class, function (Application $app): Retention {
            $closed = config('whistleblower.retention.closed_content_days');

            return new Retention(
                $app->make(ReportStore::class),
                $app->make(Whistleblower::class),
                $app->make(ClockInterface::class),
                [
                    'uninvestigated_days' => (int) config('whistleblower.retention.uninvestigated_days'),
                    'closed_content_days' => is_numeric($closed) ? (int) $closed : null,
                    'registry_years' => (int) config('whistleblower.retention.registry_years'),
                ],
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'whistleblower');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckDeadlinesCommand::class, ApplyRetentionCommand::class]);
            $this->publishes([__DIR__.'/../../config/whistleblower.php' => config_path('whistleblower.php')], 'whistleblower-config');
            $this->publishes([__DIR__.'/../../lang' => $this->app->langPath('vendor/whistleblower')], 'whistleblower-lang');
        }

        Event::listen(DeadlineReached::class, function (DeadlineReached $event): void {
            $emails = array_filter((array) config('whistleblower.notify'));
            if ($emails !== []) {
                Notification::route('mail', $emails)->notify(new DeadlineAlert($event));
            }
        });

        // Never key the limiter on anything stored with a report.
        RateLimiter::for('whistleblower', fn (Request $request): Limit => Limit::perMinute((int) config('whistleblower.routes.throttle_per_minute'))->by((string) $request->ip()));

        if (config('whistleblower.routes.enabled')) {
            Route::group([], __DIR__.'/../../routes/whistleblower.php');
        }
    }
}
