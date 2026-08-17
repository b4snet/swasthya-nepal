<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\RpmDevice;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RpmDevice>
 */
class RpmDeviceFactory extends Factory
{
    protected $model = RpmDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->facility_id,
            'patient_id' => PatientFactory::new(),
            'device_identifier' => fake()->unique()->bothify('DEV-####-????'),
            'model' => fake()->randomElement(['BP-210', 'PulseOx-2', 'Temp-Pro', 'Gluco-1']),
            'manufacturer' => 'Synthetic Devices Inc.',
            'reading_type' => fake()->randomElement(['bp', 'pulse', 'temp', 'spo2', 'glucose', 'weight']),
            'status' => RpmDevice::STATUS_ACTIVE,
            'settings' => [],
            'adapter' => 'simulated',
            'last_seen_at' => null,
            'created_by' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'lock_version' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => RpmDevice::STATUS_ACTIVE]);
    }
}
