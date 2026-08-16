<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => fn (): string => Staff::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->facility_id,
            'attendance_date' => fn (): string => now()->toDateString(),
            'clock_in_at' => fn (): string => now()->startOfDay()->addHours(8)->toIso8601String(),
            'clock_out_at' => fn (): string => now()->startOfDay()->addHours(16)->toIso8601String(),
            'status' => AttendanceRecord::STATUS_PRESENT,
            'source' => AttendanceRecord::SOURCE_CLOCK,
            'correction_status' => AttendanceRecord::CORRECTION_NONE,
            'lock_version' => 0,
        ];
    }
}
