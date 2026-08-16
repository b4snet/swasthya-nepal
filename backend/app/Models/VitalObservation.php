<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\VitalObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vital-signs observation recorded against an admission (DATABASE.md
 * §3.27, PRODUCT_REQUIREMENTS §6.5): type (bp/pulse/temp/spo2/weight/score)
 * with a typed JSON value and the measurement time. High-volume table —
 * BRIN-indexed on measured_at.
 *
 * is_abnormal is the later-phase CDSS-derived abnormal flag (nullable now);
 * the value is clinical PHI and never reaches audit payloads.
 */
class VitalObservation extends Model
{
    /** @use HasFactory<VitalObservationFactory> */
    use HasFactory, HasUuid;

    public const TYPE_BP = 'bp';

    public const TYPE_PULSE = 'pulse';

    public const TYPE_TEMP = 'temp';

    public const TYPE_SPO2 = 'spo2';

    public const TYPE_WEIGHT = 'weight';

    public const TYPE_SCORE = 'score';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'admission_id',
        'encounter_id',
        'patient_id',
        'type',
        'value',
        'measured_at',
        'measured_by',
        'is_abnormal',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'measured_at' => 'datetime',
            'is_abnormal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

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
    public function measuredBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'measured_by');
    }
}
