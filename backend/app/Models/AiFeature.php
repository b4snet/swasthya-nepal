<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AiFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The AI registry entry (DATABASE.md §3.57, AI_RULES.md §19): one row per
 * AI function per facility. A function is not an AI feature until its entry
 * is complete — tier, owner, pinned model id/version, purpose and non-goals,
 * min inputs, output schema, confidence threshold, fallback mode, review
 * cadence, audit class, and evaluation evidence. `enabled` is the per-feature
 * KILL SWITCH (false by default); transmission to a model is gated by
 * `model_approved` + `evaluation_ref` (AI_RULES.md §12, §14, §17).
 */
class AiFeature extends Model
{
    /** @use HasFactory<AiFeatureFactory> */
    use HasFactory, HasUuid;

    public const FUNCTION_DOCUMENTATION_DRAFT = 'documentation_draft';

    public const FUNCTION_SUMMARIZATION = 'summarization';

    public const FUNCTION_FORECAST = 'forecast';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'function',
        'name',
        'tier',
        'owner_staff_id',
        'model_id',
        'model_version',
        'purpose',
        'non_goals',
        'min_inputs',
        'output_schema',
        'confidence_threshold',
        'fallback_mode',
        'enabled',
        'model_approved',
        'evaluation_ref',
        'review_cadence',
        'audit_class',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'min_inputs' => 'array',
            'output_schema' => 'array',
            'confidence_threshold' => 'float',
            'enabled' => 'boolean',
            'model_approved' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
