<?php

namespace AppModels;

use IlluminateDatabaseEloquentConcernsHasUuids;
use IlluminateDatabaseEloquentModel;
use IlluminateDatabaseEloquentRelationsBelongsTo;

/**
 * Versioned care plan shared across specialties. Each plan belongs to a
 * SpecialtyProfile and tracks goals, interventions, responsible staff,
 * milestones, and review dates.
 */
class CarePlan extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'goals' => 'array',
        'interventions' => 'array',
        'milestones' => 'array',
        'start_date' => 'date',
        'target_end_date' => 'date',
        'review_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_DISCONTINUED = 'discontinued';

    public function specialtyProfile(): BelongsTo
    {
        return $this->belongsTo(SpecialtyProfile::class, 'specialty_profile_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
