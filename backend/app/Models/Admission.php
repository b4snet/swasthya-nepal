<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AdmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An inpatient stay (DATABASE.md §3.23, PRODUCT_REQUIREMENTS §6.5):
 * admission from an open encounter with a live bed assignment, discharge
 * that releases the bed, and the structured discharge summary (a signed
 * clinical note of type 'discharge') referenced by discharge_summary_id.
 *
 * Tenant-scoped with tenant-safe composite FKs. One open admission per
 * patient and per encounter (partial uniques); bed occupancy is DB-enforced
 * (beds.current_admission_id + uq_beds_tenant_current_admission).
 * lock_version guards CAS transitions (admitted → discharged).
 */
class Admission extends Model
{
    /** @use HasFactory<AdmissionFactory> */
    use HasFactory, HasUuid;

    public const TYPE_EMERGENCY = 'emergency';

    public const TYPE_PLANNED = 'planned';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_IN_WARD = 'in_ward';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_DISCHARGED = 'discharged';

    public const STATUS_CANCELLED = 'cancelled';

    public const DISCHARGE_HOME = 'home';

    public const DISCHARGE_REFERRAL = 'referral';

    public const DISCHARGE_TRANSFER_OUT = 'transfer_out';

    public const DISCHARGE_AGAINST_ADVICE = 'against_advice';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'admission_number',
        'admission_type',
        'admitting_diagnosis',
        'admitted_at',
        'status',
        'discharged_at',
        'discharge_type',
        'discharge_summary_id',
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<ClinicalNote, $this>
     */
    public function dischargeSummary(): BelongsTo
    {
        return $this->belongsTo(ClinicalNote::class, 'discharge_summary_id');
    }

    /**
     * The bed currently occupied by this admission (beds.current_admission_id).
     *
     * @return HasOne<Bed, $this>
     */
    public function bed(): HasOne
    {
        return $this->hasOne(Bed::class, 'current_admission_id');
    }

    /**
     * The audited transfer timeline (DATABASE.md §3.23, slice 13).
     *
     * @return HasMany<TransferEvent, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(TransferEvent::class, 'admission_id');
    }

    /**
     * @return HasMany<NursingNote, $this>
     */
    public function nursingNotes(): HasMany
    {
        return $this->hasMany(NursingNote::class, 'admission_id');
    }

    /**
     * @return HasMany<MarEntry, $this>
     */
    public function marEntries(): HasMany
    {
        return $this->hasMany(MarEntry::class, 'admission_id');
    }

    /**
     * @return HasMany<VitalObservation, $this>
     */
    public function vitalObservations(): HasMany
    {
        return $this->hasMany(VitalObservation::class, 'admission_id');
    }
}
