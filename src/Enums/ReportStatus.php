<?php

declare(strict_types=1);

namespace Whistleblower\Enums;

enum ReportStatus: string
{
    case Received = 'received';
    case Acknowledged = 'acknowledged';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Archived = 'archived';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Archived], true);
    }
}
