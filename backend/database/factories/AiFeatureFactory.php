<?php

namespace Database\Factories;

use App\Models\AiFeature;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiFeature>
 */
class AiFeatureFactory extends Factory
{
    protected $model = AiFeature::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => FacilityFactory::new(),
            'function' => AiFeature::FUNCTION_DOCUMENTATION_DRAFT,
            'name' => 'Clinical note draft',
            'tier' => 2,
            'owner_staff_id' => null,
            'model_id' => 'note-draft-v3',
            'model_version' => '2026-07-15',
            'purpose' => 'Draft an encounter note from the signed record sections.',
            'non_goals' => 'No ordering, no dosing, no diagnosis.',
            'min_inputs' => ['encounter_id', 'signed_sections'],
            'output_schema' => ['draft' => 'text'],
            'confidence_threshold' => null,
            'fallback_mode' => 'manual entry works fully; UI shows AI unavailable',
            'enabled' => false,
            'model_approved' => false,
            'evaluation_ref' => null,
            'review_cadence' => 'quarterly',
            'audit_class' => 'ai.draft',
            'status' => AiFeature::STATUS_REGISTERED,
            'lock_version' => 0,
            'created_by' => null,
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (): array => ['status' => AiFeature::STATUS_REGISTERED]);
    }

    /**
     * A fully governed, activatable feature (registry complete + evidence).
     */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'model_approved' => true,
            'evaluation_ref' => 'docs/evaluation/note-draft-v3-2026-07.md',
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }
}
