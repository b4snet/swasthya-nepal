<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DepositFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An advance payment held on a patient account (DATABASE.md §3.33,
 * PRODUCT_REQUIREMENTS §6.13): money collected up front, held, and later
 * allocated against invoices — exactly, and never more than the remaining
 * balance. The lifecycle is `active → exhausted | refunded`; every
 * allocation is CAS-guarded on `(status, remaining_minor, lock_version)`
 * so concurrent allocations serialize and over-allocation is impossible.
 *
 * Integer minor units; collection is idempotent per idempotency key
 * (retries never double-hold). Tenant+facility scoped, RLS on + FORCED.
 */
class Deposit extends Model
{
    /** @use HasFactory<DepositFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'amount_minor',
        'remaining_minor',
        'status',
        'idempotency_key',
        'collected_by',
        'collected_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'remaining_minor' => 'integer',
            'lock_version' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return HasMany<DepositAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(DepositAllocation::class, 'deposit_id');
    }
}
