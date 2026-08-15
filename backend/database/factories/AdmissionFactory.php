<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admission>
 */
class AdmissionFactory extends Factory
{
    protected $model = Admission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facilityId = fn (array $attributes): string => $attributes['facility_id']
            ?? Facility::factory()->create()->getKey();

        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($facilityId($attributes))->tenant_id,
            'facility_id' => $facilityId,
            'patient_id' => fn (array $attributes): string => Patient::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'encounter_id' => fn (array $attributes): string => Encounter::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
                'patient_id' => $attributes['patient_id'],
            ])->getKey(),
            'admission_number' => 'ADM-'.now()->format('Ymd').'-'.random_int(10000, 99999),
            'admission_type' => Admission::TYPE_EMERGENCY,
            'admitting_diagnosis' => fake()->sentence(4),
            'admitted_at' => now(),
            'status' => Admission::STATUS_ADMITTED,
            'lock_version' => 0,
        ];
    }
}
