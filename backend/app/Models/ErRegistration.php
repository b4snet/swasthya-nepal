<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ErRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal-data ER registration (DATABASE.md §3.17a, PRODUCT_REQUIREMENTS
 * §6.6): speed over completeness — the patient record is created with the
 * facts at hand (possibly unidentified: documented placeholder name,
 * 'unknown' sex, estimated age) and completed later. Identity of an
 * unidentified patient is later resolved through the existing patient-merge
 * flow (the controlled link).
 *
 * The registration links the created patient to the ER encounter it opened
 * and records the source facts (presenting complaint, estimated age,
 * is_unidentified). Complaint text is clinical PHI and never reaches audit
 * payloads.
 */
class ErRegistration extends Model
{
    /** @use HasFactory<ErRegistrationFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'registered_by',
        'registered_at',
        'presenting_complaint',
        'estimated_age',
        'is_unidentified',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'estimated_age' => 'integer',
            'is_unidentified' => 'boolean',
            'completed_at' => 'datetime',
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
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'registered_by');
    }
}
