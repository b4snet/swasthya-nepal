<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The master patient record (DATABASE.md §3.11) — the platform's most
 * safety-critical entity. Registered once, identified reliably, referenced
 * by every module.
 *
 * Tenant-scoped (tenant_id NOT NULL, facility_id = registering facility).
 * Never hard-deleted: status (active → merged/archived) is the lifecycle;
 * a merged record points at its survivor. `lock_version` is the
 * optimistic-locking counter for concurrent edits.
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MERGED = 'merged';

    public const STATUS_ARCHIVED = 'archived';

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    public const SEX_OTHER = 'other';

    public const SEX_UNKNOWN = 'unknown';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'mrn',
        'user_id',
        'full_name',
        'date_of_birth',
        'sex',
        'blood_group',
        'status',
        'merge_into_patient_id',
        'consent_summary',
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
            'date_of_birth' => 'date',
            'consent_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return HasMany<PatientIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class, 'patient_id');
    }

    /**
     * @return HasMany<PatientContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class, 'patient_id');
    }

    /**
     * @return HasMany<InsurancePolicy, $this>
     */
    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class, 'patient_id');
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class, 'patient_id');
    }

    /**
     * @return HasMany<PatientDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class, 'patient_id');
    }

    /**
     * @return HasMany<PatientTimelineEntry, $this>
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(PatientTimelineEntry::class, 'patient_id');
    }
}
