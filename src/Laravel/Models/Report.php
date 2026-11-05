<?php

declare(strict_types=1);

namespace Whistleblower\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Whistleblower\Enums\ReportStatus;

/**
 * @property string $id
 * @property string $tracking_code_hash
 * @property ReportStatus $status
 * @property string|null $category
 * @property string|null $subject
 * @property string|null $description
 * @property bool $anonymous
 * @property string|null $reporter_name
 * @property string|null $reporter_email
 * @property string|null $resolution
 * @property Carbon $received_at
 * @property Carbon $acknowledgement_due_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon $resolution_due_at
 * @property Carbon|null $extended_at
 * @property Carbon|null $investigation_started_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $anonymized_at
 */
class Report extends Model
{
    use HasUlids;

    protected $table = 'whistleblower_reports';

    /** @var list<string> */
    protected $hidden = ['tracking_code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'subject' => 'encrypted',
            'description' => 'encrypted',
            'reporter_name' => 'encrypted',
            'reporter_email' => 'encrypted',
            'resolution' => 'encrypted',
            'anonymous' => 'boolean',
            'received_at' => 'datetime',
            'acknowledgement_due_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'extended_at' => 'datetime',
            'investigation_started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'archived_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'report_id')->orderBy('id');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'report_id')->orderBy('id');
    }
}
