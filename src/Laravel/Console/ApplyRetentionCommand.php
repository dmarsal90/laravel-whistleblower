<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Console;

use Illuminate\Console\Command;
use Whistleblower\Retention;

final class ApplyRetentionCommand extends Command
{
    protected $signature = 'whistleblower:apply-retention';

    protected $description = 'Anonymise reports past their retention period and delete expired registry entries. Schedule it daily.';

    public function handle(Retention $retention): int
    {
        $result = $retention->apply();
        $this->info("{$result['anonymised']} report(s) anonymised, {$result['deleted']} registry entr(y/ies) deleted.");

        return self::SUCCESS;
    }
}
