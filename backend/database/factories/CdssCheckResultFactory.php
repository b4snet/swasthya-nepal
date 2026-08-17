<?php

namespace Database\Factories;

use App\Models\CdssCheckResult;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdssCheckResult>
 */
class CdssCheckResultFactory extends Factory
{
    protected $model = CdssCheckResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->facility_id,
            'patient_id' => PatientFactory::new(),
            'alert_type' => 'allergy',
            'rule_code' => 'ALL-PEN',
            'rule_version' => 1,
            'severity' => 'major',
            'message' => 'Patient has an active penicillin allergy.',
            'triggering_facts' => ['allergen_class' => 'penicillin'],
            'status' => CdssCheckResult::STATUS_OPEN,
            'override_reason' => null,
            'overridden_by' => null,
            'overridden_at' => null,
            'lock_version' => 0,
            'created_by' => null,
        ];
    }
}
