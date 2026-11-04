<?php

declare(strict_types=1);

namespace Whistleblower;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Whistleblower\Console\ApplyRetentionCommand;
use Whistleblower\Console\CheckDeadlinesCommand;
use Whistleblower\Events\DeadlineReached;
use Whistleblower\Notifications\DeadlineAlert;

final class WhistleblowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whistleblower.php', 'whistleblower');

        $this->app->singleton(Whistleblower::class, fn ($app): Whistleblower => new Whistleblower([
            'acknowledgement_days' => (int) $app['config']->get('whistleblower.deadlines.acknowledgement_days'),
            'resolution_months' => (int) $app['config']->get('whistleblower.deadlines.resolution_months'),
            'extension_months' => (int) $app['config']->get('whistleblower.deadlines.extension_months'),
        ]));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'whistleblower');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckDeadlinesCommand::class, ApplyRetentionCommand::class]);
            $this->publishes([__DIR__.'/../config/whistleblower.php' => config_path('whistleblower.php')], 'whistleblower-config');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/whistleblower')], 'whistleblower-lang');
        }

        Event::listen(DeadlineReached::class, function (DeadlineReached $event): void {
            $emails = array_filter((array) config('whistleblower.notify'));
            if ($emails !== []) {
                Notification::route('mail', $emails)->notify(new DeadlineAlert($event));
            }
        });

        // Anonymous reporters share IPs rarely, but never key the limiter on anything stored with a report.
        RateLimiter::for('whistleblower', fn (Request $request): Limit => Limit::perMinute((int) config('whistleblower.routes.throttle_per_minute'))->by((string) $request->ip()));

        if (config('whistleblower.routes.enabled')) {
            Route::group([], __DIR__.'/../routes/whistleblower.php');
        }
    }
}
