<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant's payers master (DATABASE.md §3.14 → §3.45): insurers, TPAs,
 * and government schemes that patient insurance policies reference.
 *
 * Tenant-wide (not facility-scoped) — a policy covers a patient at any
 * facility of the tenant.
 */
class Payer extends Model
{
    /** @use HasFactory<PayerFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'payer_type',
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
     * @return HasMany<InsurancePolicy, $this>
     */
    public function policies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'payer_id');
    }
}
