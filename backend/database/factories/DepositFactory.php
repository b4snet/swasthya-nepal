<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Deposit>
 */
class DepositFactory extends Factory
{
    protected $model = Deposit::class;

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
            'amount_minor' => 5000,
            'remaining_minor' => fn (array $attributes): int => $attributes['amount_minor'],
            'status' => Deposit::STATUS_ACTIVE,
            'idempotency_key' => 'dep-'.(string) Str::uuid(),
            'collected_at' => now(),
            'lock_version' => 0,
        ];
    }
}
