<?php

declare(strict_types=1);

namespace Whistleblower\Exceptions;

use DomainException;
use Whistleblower\Enums\ReportStatus;

final class InvalidTransition extends DomainException
{
    public static function for(string $action, ReportStatus $from): self
    {
        return new self("Cannot {$action} a report that is {$from->value}.");
    }
}
