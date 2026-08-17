<?php

namespace Database\Factories;

use App\Models\AiDraft;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDraft>
 */
class AiDraftFactory extends Factory
{
    protected $model = AiDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->facility_id,
            'patient_id' => PatientFactory::new(),
            'encounter_id' => null,
            'function' => 'documentation_draft',
            'tier' => 2,
            'model_id' => 'note-draft-v3',
            'model_version' => '2026-07-15',
            'source_refs' => [['type' => 'encounter', 'id' => 'enc-ref']],
            'output' => 'Draft note text grounded in the signed record sections.',
            'confidence' => null,
            'status' => AiDraft::STATUS_DRAFT,
            'signer_staff_id' => null,
            'signed_at' => null,
            'correlation_id' => null,
            'lock_version' => 0,
            'created_by' => null,
        ];
    }
}
