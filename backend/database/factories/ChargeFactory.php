<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'patient_id' => fn (array $attributes): string => Patient::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'source_type' => Charge::SOURCE_MANUAL,
            'description' => fake()->sentence(4),
            'amount_minor' => fake()->numberBetween(1000, 200000),
            'currency' => 'NPR',
            'tax_rate_bps' => 0,
            'status' => Charge::STATUS_POSTED,
            'charged_at' => now(),
        ];
    }
}
