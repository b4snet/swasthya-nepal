<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operational sub-division of a facility (TENANCY.md V2 §4, DATABASE.md
 * §3.x): a wing, a satellite counter, a dedicated unit. Part of the
 * PLATFORM → ORGANIZATION → FACILITY → BRANCH hierarchy.
 *
 * Branch is OPTIONAL and a grouping, not a hard authorization boundary: the
 * catalog resources (departments, locations, wards, rooms, beds) may be
 * assigned to a branch (branch_id nullable) but never need to be. Tenant and
 * facility ownership are enforced by the composite FK (DATABASE.md §0.9), so
 * a branch can never reference another tenant's facility.
 *
 * Soft-deletable with an active-scope partial unique on (tenant, facility,
 * code) so a closed branch's code can be reused deliberately.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'name',
        'code',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
