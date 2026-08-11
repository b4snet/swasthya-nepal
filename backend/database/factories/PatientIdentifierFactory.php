<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientIdentifier>
 */
class PatientIdentifierFactory extends Factory
{
    protected $model = PatientIdentifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            // The value mutator encrypts + hashes in one step.
            'value' => fake()->numerify('##########'),
            'type' => 'national_id',
            'issuing_country' => 'NP',
            'is_verified' => false,
            'status' => PatientIdentifier::STATUS_ACTIVE,
        ];
    }
}
