<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Invoice::query()->findOrFail($attributes['invoice_id'])->tenant_id,
            'invoice_id' => fn (): string => Invoice::factory()->create()->getKey(),
            'charge_id' => fn (array $attributes): string => Charge::factory()->create([
                'tenant_id' => Invoice::query()->findOrFail($attributes['invoice_id'])->tenant_id,
                'facility_id' => Invoice::query()->findOrFail($attributes['invoice_id'])->facility_id,
                'patient_id' => Invoice::query()->findOrFail($attributes['invoice_id'])->patient_id,
            ])->getKey(),
            'description' => fake()->sentence(4),
            'amount_minor' => fake()->numberBetween(1000, 100000),
            'tax_minor' => 0,
            'line_no' => 1,
        ];
    }
}
