<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Settlement;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Settlement>
 */
class SettlementFactory extends Factory
{
    protected $model = Settlement::class;

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
            'cashier_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'settlement_date' => fn (): string => now()->toDateString(),
            'expected_minor' => 0,
            'status' => Settlement::STATUS_OPEN,
            'lock_version' => 0,
        ];
    }
}
