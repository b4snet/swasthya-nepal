<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vital observations — structured clinical measurements captured during
 * encounters (OPD) or admissions (IPD). Supports longitudinal history:
 * every measurement is attributable to patient, encounter/admission,
 * performer, and timestamp.
 */
class VitalObservation extends Model
{
    use HasUuids;

    public const TYPE_BP = 'bp';
    public const TYPE_PULSE = 'pulse';
    public const TYPE_TEMP = 'temp';
    public const TYPE_SPO2 = 'spo2';
    public const TYPE_WEIGHT = 'weight';
    public const TYPE_SCORE = 'score';

    protected $table = 'nursing_vitals';

    protected $guarded = ['id'];

    protected $casts = [
        'value' => 'array',
        'observed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by');
    }
}
