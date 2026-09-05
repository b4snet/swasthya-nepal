<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patient-level longitudinal problem list — persistent conditions that
 * persist across encounters. Distinct from encounter-scoped Diagnosis:
 * a Diagnosis belongs to a single encounter; a Problem belongs to the
 * patient and tracks the long-term condition.
 *
 * Problems are created from encounter diagnoses (or directly) and
 * represent the authoritative record of a patient's active/resolved
 * conditions. Never soft-deleted — status is the lifecycle.
 */
class Problem extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_RULED_OUT = 'ruled_out';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'code',
        'coding_system',
        'description',
        'clinical_description',
        'status',
        'onset_date',
        'resolved_date',
        'resolved_reason',
        'encounter_id',
        'recorded_by',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'onset_date' => 'date',
            'resolved_date' => 'date',
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
}
