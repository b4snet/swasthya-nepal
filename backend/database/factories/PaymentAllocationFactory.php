<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Payment::query()->findOrFail($attributes['payment_id'])->tenant_id,
            'payment_id' => fn (): string => Payment::factory()->create()->getKey(),
            'invoice_id' => fn (array $attributes): string => Invoice::factory()->create([
                'tenant_id' => Payment::query()->findOrFail($attributes['payment_id'])->tenant_id,
                'facility_id' => Payment::query()->findOrFail($attributes['payment_id'])->facility_id,
                'patient_id' => Payment::query()->findOrFail($attributes['payment_id'])->patient_id,
            ])->getKey(),
            'amount_minor' => fake()->numberBetween(1000, 100000),
            'allocated_at' => now(),
        ];
    }
}
