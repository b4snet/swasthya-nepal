<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TriageAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One triage assessment on an ER encounter (DATABASE.md §3.17a,
 * PRODUCT_REQUIREMENTS §6.6). Exactly one ACTIVE assignment per encounter
 * (partial unique — the DB backstop); a reassessment supersedes the active
 * row via CAS and becomes the new active. The level/color are snapshotted
 * from the scale at assessment time, so later catalog edits never rewrite
 * triage history. An OVERRIDE (clinical authority) carries a reason; both
 * triage time/category and reassessments are audited (medico-legal).
 */
class TriageAssignment extends Model
{
    /** @use HasFactory<TriageAssignmentFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'encounter_id',
        'patient_id',
        'triage_scale_id',
        'level',
        'color',
        'assessed_by_staff_id',
        'assessed_at',
        'is_override',
        'override_reason',
        'status',
        'lock_version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'assessed_at' => 'datetime',
            'is_override' => 'boolean',
            'lock_version' => 'integer',
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
     * @return BelongsTo<TriageScale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(TriageScale::class, 'triage_scale_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assessed_by_staff_id');
    }
}
