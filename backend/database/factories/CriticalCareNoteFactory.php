<?php

namespace Database\Factories;

use App\Models\CriticalCareNote;
use App\Models\IcuAdmission;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CriticalCareNote>
 */
class CriticalCareNoteFactory extends Factory
{
    protected $model = CriticalCareNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Anchor first, then derive — closures receive the RESOLVED
            // attributes in definition order.
            'icu_admission_id' => fn (): string => IcuAdmission::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => IcuAdmission::query()->findOrFail($attributes['icu_admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => IcuAdmission::query()->findOrFail($attributes['icu_admission_id'])->facility_id,
            'note_type' => CriticalCareNote::TYPE_DAILY_GOAL,
            'content' => 'Daily goals: maintain SpO2 > 94%.',
            'authored_at' => now()->toIso8601String(),
            'authored_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
        ];
    }
}
