<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IcuAdmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ICU admission with bed assignment and a policy-defined observation
 * schedule (DATABASE.md §3.49, PRODUCT_REQUIREMENTS §6.11). MISSED
 * observations escalate by design (ROADMAP Phase 16) — next_observation_due_at
 * is the schedule the audit trail must prove was met. Tenant+facility
 * scoped, RLS on + FORCED.
 */
class IcuAdmission extends Model
{
    /** @use HasFactory<IcuAdmissionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_DISCHARGED = 'discharged';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'admission_id',
        'icu_bed_id',
        'source',
        'acuity',
        'observation_interval_minutes',
        'next_observation_due_at',
        'status',
        'admitted_at',
        'admitted_by_staff_id',
        'discharged_at',
        'discharged_by_staff_id',
        'transfer_handover_notes',
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
            'observation_interval_minutes' => 'integer',
            'next_observation_due_at' => 'datetime',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
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
     * @return BelongsTo<IcuBed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(IcuBed::class, 'icu_bed_id');
    }

    /**
     * @return HasMany<IcuObservationSet, $this>
     */
    public function observationSets(): HasMany
    {
        return $this->hasMany(IcuObservationSet::class, 'icu_admission_id');
    }
}
