<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MedicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant's medicine catalog / formulary (DATABASE.md §3.22) — the
 * reference for prescribing. Prices are integer minor units, never floats.
 *
 * Tenant-scoped with facility ownership. Soft-deletable: retired medicines
 * stay referenced by prescription history.
 */
class Medication extends Model
{
    /** @use HasFactory<MedicationFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'generic_name',
        'brand_name',
        'strength',
        'form',
        'unit',
        'price_minor',
        'currency',
        'is_controlled',
        'status',
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
            'price_minor' => 'integer',
            'is_controlled' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
