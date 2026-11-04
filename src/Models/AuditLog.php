<?php

declare(strict_types=1);

namespace Whistleblower\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $report_id
 * @property string $actor_type "reporter", "manager" or "system"
 * @property string|null $actor_id
 * @property string $action
 * @property array<string, scalar|null>|null $context
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whistleblower_audit_logs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
