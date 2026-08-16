<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => fn (): string => Staff::factory()->create()->getKey(),
            'leave_type_id' => fn (array $attributes): string => LeaveType::factory()->create([
                'tenant_id' => Staff::query()->findOrFail($attributes['staff_id'])->tenant_id,
                'facility_id' => Staff::query()->findOrFail($attributes['staff_id'])->facility_id,
            ])->getKey(),
            'tenant_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Staff::query()->findOrFail($attributes['staff_id'])->facility_id,
            'starts_on' => fn (): string => now()->addDays(7)->toDateString(),
            'ends_on' => fn (array $attributes): string => now()->addDays(9)->toDateString(),
            'days_requested' => 3,
            'status' => LeaveRequest::STATUS_PENDING,
            'lock_version' => 0,
        ];
    }
}
