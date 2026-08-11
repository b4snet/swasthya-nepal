<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientContact>
 */
class PatientContactFactory extends Factory
{
    protected $model = PatientContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            'type' => PatientContact::TYPE_PHONE,
            'value' => fake()->numerify('+977-98########'),
            'is_primary' => false,
            'status' => PatientContact::STATUS_ACTIVE,
        ];
    }
}
