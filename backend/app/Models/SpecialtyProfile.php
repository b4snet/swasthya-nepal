<?php

namespace AppModels;

use IlluminateDatabaseEloquentConcernsHasUuids;
use IlluminateDatabaseEloquentModel;
use IlluminateDatabaseEloquentRelationsBelongsTo;
use IlluminateDatabaseEloquentRelationsHasMany;

/**
 * Generic specialty patient profile. Replaces per-specialty profile models
 * (e.g. OncologyProfile) with a single configurable entity linked to a
 * department. Specialty-specific data lives in SpecialtyAssessment records.
 */
class SpecialtyProfile extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'diagnosed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_INACTIVE = 'inactive';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(SpecialtyAssessment::class, 'specialty_profile_id');
    }

    public function carePlans(): HasMany
    {
        return $this->hasMany(CarePlan::class, 'specialty_profile_id');
    }
}
