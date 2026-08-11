<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schedule exception (DATABASE.md §3.16): leave, holiday, or a blocked
 * date for a provider. One exception per (staff, date); exceptions cancel
 * derived availability for that date.
 *
 * Tenant-scoped. Date-scoped rows expire naturally — no soft delete.
 */
class ScheduleException extends Model
{
    /** @use HasFactory<ScheduleExceptionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'template_id',
        'exception_date',
        'reason',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exception_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<ScheduleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ScheduleTemplate::class, 'template_id');
    }
}
