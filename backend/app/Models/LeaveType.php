<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A leave type with its annual entitlement (PRODUCT_REQUIREMENTS §6.17,
 * DATABASE.md §3.45): e.g., annual leave with N paid days/year and carryover.
 * Balance tracking is computed from approved leave requests against this
 * entitlement in HrService (never a stored, stale figure).
 *
 * Soft-deletable (active-scope partial unique on code).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'paid_days_per_year',
        'carryover_days',
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
            'paid_days_per_year' => 'integer',
            'carryover_days' => 'integer',
        ];
    }
}
