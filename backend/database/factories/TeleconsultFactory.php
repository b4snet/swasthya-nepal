<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Staff;
use App\Models\Teleconsult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teleconsult>
 */
class TeleconsultFactory extends Factory
{
    protected $model = Teleconsult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduledAt = fake()->dateTimeBetween('+1 hour', '+7 days');

        return [
            'tenant_id' => fn (array $attributes): string => Appointment::query()->findOrFail($attributes['appointment_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Appointment::query()->findOrFail($attributes['appointment_id'])->facility_id,
            'appointment_id' => fn (): string => Appointment::factory()->create([
                'appointment_type' => Appointment::TYPE_TELECONSULT,
            ])->getKey(),
            'patient_id' => fn (array $attributes): string => Appointment::query()->findOrFail($attributes['appointment_id'])->patient_id,
            'provider_staff_id' => fn (array $attributes): string => Appointment::query()->findOrFail($attributes['appointment_id'])->provider_staff_id,
            'status' => Teleconsult::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'starts_at' => $scheduledAt,
            'ends_at' => null,
            'fallback_mode' => null,
            'fallback_reason' => null,
            'lock_version' => 0,
        ];
    }

    public function withProvider(Staff $staff): static
    {
        return $this->state(fn (): array => [
            'provider_staff_id' => $staff->getKey(),
        ]);
    }
}
