<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Radiotherapy treatment plan (Phase 15).
 *
 * Represents an RT plan with technique (VMAT, IMRT, 3D-CRT, etc.),
 * dose/fractionation, and approval workflow.
 */
class RtTreatmentPlan extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_TREATMENT = 'in_treatment';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_course_id',
        'plan_name', 'technique', 'energy',
        'fraction_dose_cgy', 'num_fractions', 'total_dose_cgy',
        'status', 'planned_by_staff_id',
        'approved_by_physicist_id', 'physicist_approved_at',
        'approved_by_ro_id', 'ro_approved_at',
        'plan_note', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'fraction_dose_cgy' => 'integer',
            'num_fractions' => 'integer',
            'total_dose_cgy' => 'decimal:2',
            'physicist_approved_at' => 'datetime',
            'ro_approved_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(RtTreatmentCourse::class, 'rt_course_id');
    }

    public function fractions(): HasMany
    {
        return $this->hasMany(RtFraction::class, 'rt_plan_id');
    }

    public function structures(): HasMany
    {
        return $this->hasMany(RtStructure::class, 'rt_plan_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RtPlanApproval::class, 'rt_plan_id');
    }

    /**
     * Check if the plan requires secondary check (SRS/SBRT/VMAT/IMRT).
     */
    public function requiresSecondaryCheck(): bool
    {
        return in_array($this->technique, ['VMAT', 'IMRT', 'SRS', 'SBRT'], true);
    }

    /**
     * Check if plan is fully approved (physicist + RO).
     */
    public function isFullyApproved(): bool
    {
        return $this->physicist_approved_at !== null && $this->ro_approved_at !== null;
    }
}
