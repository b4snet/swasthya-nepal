<?php

namespace Database\Factories;

use App\Models\RpmAlert;
use App\Models\RpmDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RpmAlert>
 */
class RpmAlertFactory extends Factory
{
    protected $model = RpmAlert::class;

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
            'reading_id' => null, // alerts in tests are bound to the device, not a chained reading
            'alert_type' => RpmAlert::TYPE_HIGH,
            'parameter' => 'value',
            'threshold_value' => ['high' => 100],
            'observed_value' => ['value' => 120],
            'severity' => RpmAlert::SEVERITY_MEDIUM,
            'status' => RpmAlert::STATUS_OPEN,
            'acknowledged_by' => null,
            'acknowledged_at' => null,
            'acknowledged_note' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'created_by' => null,
            'lock_version' => 0,
        ];
    }

    public function withDevice(RpmDevice $device): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $device->tenant_id,
            'facility_id' => $device->facility_id,
            'patient_id' => $device->patient_id,
            'device_id' => $device->getKey(),
        ]);
    }
}
