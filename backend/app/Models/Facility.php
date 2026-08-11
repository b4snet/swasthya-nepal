<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hospital/clinic owned by one organization (DATABASE.md §3.2).
 *
 * Tenant-scoped (tenant_id NOT NULL). Facility is the common operating
 * context for staff workflows; facility/branch scoping is enforced by the
 * policy layer on top of the tenant hard boundary (TENANCY.md §6.1).
 */
class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'status',
        'timezone',
        'address',
        'settings',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }
}
