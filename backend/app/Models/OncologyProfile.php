<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Oncology patient profile (Phase 15).
 *
 * Summarizes a patient's cancer diagnosis, staging, and treatment status.
 * One profile per patient per tenant.
 */
class OncologyProfile extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_IN_REMISSION = 'in_remission';

    public const STATUS_DECEASED = 'deceased';

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id',
        'primary_diagnosis', 'cancer_site', 'histology', 'grade',
        'tnm_staging', 'overall_stage', 'performance_status',
        'status', 'diagnosed_at', 'treating_physician_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'diagnosed_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(OncologyDiagnosis::class, 'oncology_profile_id');
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class, 'oncology_profile_id');
    }

    public function rtCourses(): HasMany
    {
        return $this->hasMany(RtTreatmentCourse::class, 'oncology_profile_id');
    }

    public function oncologyEncounters(): HasMany
    {
        return $this->hasMany(OncologyEncounter::class, 'oncology_profile_id');
    }

    public function multidisciplinaryReviews(): HasMany
    {
        return $this->hasMany(MultidisciplinaryReview::class, 'oncology_profile_id');
    }
}
