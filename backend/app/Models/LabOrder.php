<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LabOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laboratory/radiology order (DATABASE.md §3.26, PRODUCT_REQUIREMENTS
 * §6.8). Created by the encounter provider; one status state machine:
 *
 *   ordered → collected → processing → results_entered → verified → reported
 *
 * `reported` is immutable (a correction is a new audited version — later
 * phase). Transitions are compare-and-swap on (status, lock_version) so two
 * concurrent actors can never double-advance the order.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LabOrder extends Model
{
    /** @use HasFactory<LabOrderFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RESULTS_ENTERED = 'results_entered';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_CORRECTING = 'correcting';

    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_STAT = 'stat';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'ordered_by_staff_id',
        'priority',
        'status',
        'clinical_indication',
        'ordered_at',
        'collected_by_staff_id',
        'collected_at',
        'processing_at',
        'verified_by_staff_id',
        'verified_at',
        'reported_by_staff_id',
        'reported_at',
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
            'ordered_at' => 'datetime',
            'collected_at' => 'datetime',
            'processing_at' => 'datetime',
            'verified_at' => 'datetime',
            'reported_at' => 'datetime',
            'correcting_at' => 'datetime',
            'lock_version' => 'integer',
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
     * @return BelongsTo<Staff, $this>
     */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'ordered_by_staff_id');
    }

    /**
     * @return HasMany<LabOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class, 'lab_order_id');
    }

    /**
     * @return HasMany<Specimen, $this>
     */
    public function specimens(): HasMany
    {
        return $this->hasMany(Specimen::class, 'lab_order_id');
    }
}
