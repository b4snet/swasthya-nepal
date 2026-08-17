<?php

namespace Database\Factories;

use App\Models\RpmDevice;
use App\Models\RpmReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RpmReading>
 */
class RpmReadingFactory extends Factory
{
    protected $model = RpmReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => RpmDevice::query()->findOrFail($attributes['device_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => RpmDevice::query()->findOrFail($attributes['device_id'])->facility_id,
            'patient_id' => fn (array $attributes): string => RpmDevice::query()->findOrFail($attributes['device_id'])->patient_id,
            'device_id' => RpmDeviceFactory::new(),
            'reading_type' => fn (array $attributes): string => RpmDevice::query()->findOrFail($attributes['device_id'])->reading_type,
            'value' => ['value' => 80],
            'units' => null,
            'measured_at' => now()->subMinutes(5),
            'source' => 'device',
            'validation_status' => RpmReading::VALIDATED,
            'validation_reason' => null,
            'provenance' => ['adapter' => 'simulated', 'firmware' => '1.0'],
            'ingestion_id' => null,
            'created_by' => null,
        ];
    }
}
