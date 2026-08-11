<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ScheduleTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Doctor availability template (DATABASE.md §3.16): recurring weekly
 * availability for a provider + service. Availability slots are DERIVED from
 * templates minus exceptions minus bookings — never stored.
 *
 * Tenant-scoped (tenant_id NOT NULL, facility_id NOT NULL). Soft-deletable;
 * deactivated templates stop producing slots for dates after today.
 */
class ScheduleTemplate extends Model
{
    /** @use HasFactory<ScheduleTemplateFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'service_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'slot_minutes',
        'capacity',
        'valid_from',
        'valid_to',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'slot_minutes' => 'integer',
            'capacity' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
