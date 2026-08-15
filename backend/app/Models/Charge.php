<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A charge (DATABASE.md §3.33): what was charged and from which source.
 * Amounts are integer minor units, never floats. Posted charges are
 * immutable — corrections are reversing entries, never UPDATEs; void is a
 * status with reason and approver.
 */
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use HasFactory, HasUuid;

    public const SOURCE_ENCOUNTER = 'encounter';

    public const SOURCE_PRESCRIPTION = 'prescription';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'source_type',
        'encounter_id',
        'prescription_id',
        'description',
        'amount_minor',
        'currency',
        'tax_rate_bps',
        'status',
        'voided_by',
        'void_reason',
        'charged_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'charged_at' => 'datetime',
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    /**
     * @return HasMany<RefundRequest, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'charge_id');
    }
}
