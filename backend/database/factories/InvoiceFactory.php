<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

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
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'status' => Invoice::STATUS_ISSUED,
            'total_minor' => 0,
            'total_tax_minor' => 0,
            'paid_minor' => 0,
            'issued_at' => now(),
            'lock_version' => 0,
        ];
    }
}
