<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepositAllocation>
 */
class DepositAllocationFactory extends Factory
{
    protected $model = DepositAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $depositId = fn (array $attributes): string => $attributes['deposit_id']
            ?? Deposit::factory()->create()->getKey();

        return [
            'tenant_id' => fn (array $attributes): string => Deposit::query()->findOrFail($depositId($attributes))->tenant_id,
            'facility_id' => fn (array $attributes): string => Deposit::query()->findOrFail($depositId($attributes))->facility_id,
            'deposit_id' => $depositId,
            'invoice_id' => fn (array $attributes): string => Invoice::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'amount_minor' => 1000,
            'allocated_at' => now(),
        ];
    }
}
