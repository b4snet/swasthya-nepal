<?php

namespace AppModels;

use IlluminateDatabaseEloquentConcernsHasUuids;
use IlluminateDatabaseEloquentModel;
use IlluminateDatabaseEloquentRelationsBelongsTo;

/**
 * Specialty-specific assessment linked to a SpecialtyProfile. Uses the
 * shared form/template engine — assessment_type references a FormTemplate
 * and responses stores the structured field data.
 */
class SpecialtyAssessment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'responses' => 'array',
        'assessed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FINALIZED = 'finalized';

    public function specialtyProfile(): BelongsTo
    {
        return $this->belongsTo(SpecialtyProfile::class, 'specialty_profile_id');
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }
}
