<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'patient_id' => fn (array $attributes): string => Patient::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'method' => Payment::METHOD_CASH,
            'amount_minor' => fake()->numberBetween(1000, 200000),
            'currency' => 'NPR',
            'status' => Payment::STATUS_CAPTURED,
            'idempotency_key' => fake()->unique()->uuid(),
            'received_at' => now(),
        ];
    }
}
