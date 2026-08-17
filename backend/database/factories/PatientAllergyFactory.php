<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAllergy>
 */
class PatientAllergyFactory extends Factory
{
    protected $model = PatientAllergy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->facility_id,
            'patient_id' => PatientFactory::new(),
            'allergen' => 'Penicillin',
            'allergen_class' => 'penicillin',
            'severity' => PatientAllergy::SEVERITY_MODERATE,
            'reaction' => 'Rash',
            'status' => PatientAllergy::STATUS_ACTIVE,
            'recorded_by' => null,
            'lock_version' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => PatientAllergy::STATUS_ACTIVE]);
    }
}
