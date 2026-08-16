<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A position within a department (PRODUCT_REQUIREMENTS §6.17, DATABASE.md
 * §3.45): the job catalog an employee can hold (e.g., "Senior Staff Nurse").
 * Facility-scoped — a position belongs to one department of one facility.
 *
 * Soft-deletable (active-scope partial unique on code); a position with
 * rostered staff cannot be deleted (the composite FK is RESTRICT).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'code',
        'name',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
