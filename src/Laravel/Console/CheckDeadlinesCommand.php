<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Whistleblower\DeadlineChecker;

final class CheckDeadlinesCommand extends Command
{
    protected $signature = 'whistleblower:check-deadlines';

    protected $description = 'Dispatch an event for every open report whose legal deadline is near or past. Schedule it daily.';

    public function handle(DeadlineChecker $checker, Dispatcher $events): int
    {
        $found = $checker->check();
        foreach ($found as $event) {
            $events->dispatch($event);
        }

        $this->info(count($found).' deadline alert(s) dispatched.');

        return self::SUCCESS;
    }
}
