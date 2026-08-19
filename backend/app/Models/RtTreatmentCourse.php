<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RtTreatmentCourse extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISCONTINUED = 'discontinued';

    protected $fillable = [
        'tenant_id', 'facility_id', 'oncology_profile_id', 'treatment_plan_id',
        'intent', 'status', 'total_fractions', 'completed_fractions',
        'total_dose_cgy', 'started_at', 'completed_at', 'discontinuation_reason',
        'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'total_fractions' => 'integer',
            'completed_fractions' => 'integer',
            'total_dose_cgy' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OncologyProfile::class, 'oncology_profile_id');
    }

    public function rtPlans(): HasMany
    {
        return $this->hasMany(RtTreatmentPlan::class, 'rt_course_id');
    }

    public function fractions(): HasMany
    {
        return $this->hasManyThrough(RtFraction::class, RtTreatmentPlan::class, 'rt_course_id', 'rt_plan_id');
    }
}
