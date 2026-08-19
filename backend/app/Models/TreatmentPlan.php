<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Treatment plan (Phase 15).
 *
 * Represents a chemotherapy or combined treatment protocol.
 * Tracks line of therapy, planned/completed cycles, and approval status.
 */
class TreatmentPlan extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISCONTINUED = 'discontinued';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'tenant_id', 'facility_id', 'oncology_profile_id', 'encounter_id',
        'plan_type', 'protocol_code', 'protocol_name', 'intent',
        'status', 'line_of_therapy', 'planned_cycles', 'completed_cycles',
        'discontinuation_reason', 'started_at', 'completed_at',
        'created_by_staff_id', 'approved_by_staff_id', 'approved_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'planned_cycles' => 'integer',
            'completed_cycles' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function oncologyProfile(): BelongsTo
    {
        return $this->belongsTo(OncologyProfile::class, 'oncology_profile_id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(TreatmentCycle::class, 'treatment_plan_id');
    }

    public function medications(): HasMany
    {
        return $this->hasMany(TreatmentMedication::class, 'treatment_plan_id');
    }
}
