<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $report_id
 * @property string $author "reporter" or "manager"
 * @property string|null $user_id
 * @property string $body
 * @property Carbon $created_at
 */
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whistleblower_messages';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'datetime'];
    }
}
