<?php

namespace Database\Factories;

use App\Models\Consent;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            'consent_type' => Consent::TYPE_TREATMENT,
            'version' => 1,
            'status' => Consent::STATUS_ACTIVE,
            'scope' => [],
            'given_at' => now(),
        ];
    }
}
