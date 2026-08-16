<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ErEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-stamped, append-only ER event (DATABASE.md §3.17a,
 * PRODUCT_REQUIREMENTS §6.6): the medico-legal log of what happened to the
 * patient in the emergency department and when (triage, reassessment, doctor
 * seen, treatments, disposition, transfers out). Immutable — there is no
 * UPDATE or DELETE path. Event notes are clinical context and never reach
 * audit payloads.
 */
class ErEvent extends Model
{
    /** @use HasFactory<ErEventFactory> */
    use HasFactory, HasUuid;

    public const TYPE_ARRIVED = 'arrived';

    public const TYPE_REGISTERED = 'registered';

    public const TYPE_TRIAGED = 'triaged';

    public const TYPE_REASSESSED = 'reassessed';

    public const TYPE_SEEN_BY_DOCTOR = 'seen_by_doctor';

    public const TYPE_TREATMENT_STARTED = 'treatment_started';

    public const TYPE_LAB_ORDERED = 'lab_ordered';

    public const TYPE_MEDICATION_ADMINISTERED = 'medication_administered';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_OBSERVATION_STARTED = 'observation_started';

    public const TYPE_DISPOSITION = 'disposition';

    public const TYPE_TRANSFERRED_OUT = 'transferred_out';

    public const TYPE_DISCHARGED = 'discharged';

    public const TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'encounter_id',
        'patient_id',
        'event_type',
        'notes',
        'occurred_at',
        'actor_staff_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id');
    }
}
