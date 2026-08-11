<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hospital services catalog: the facility's clinical/billable offerings
 * (OPD consultation, procedure, investigation…), referenced by doctor
 * schedules (DATABASE.md §3.16 service_id) and appointment booking.
 *
 * Tenant-scoped (tenant_id NOT NULL). Rates are integer minor units, never
 * floats (DATABASE.md §0.4). Soft-deletable with an active-scope partial
 * unique on code.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'name',
        'code',
        'service_type',
        'status',
        'default_duration_minutes',
        'default_charge_minor',
        'currency',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
