<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Organization::factory(),
            'facility_id' => Facility::factory(),
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'patient_id' => Patient::factory(),
            'receipt_number' => 'RCP-'.$this->faker->year().'-'.$this->faker->numerify('#####'),
            'status' => Receipt::STATUS_ISSUED,
            'amount_minor' => $this->faker->numberBetween(500, 500000),
            'currency' => 'NPR',
            'method' => $this->faker->randomElement(['cash', 'card', 'bank_transfer', 'digital_wallet']),
            'issued_by' => $this->faker->uuid(),
        ];
    }
}
