<?php

namespace Database\Factories;

use App\Models\BillingAdjustment;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingAdjustment>
 */
class BillingAdjustmentFactory extends Factory
{
    protected $model = BillingAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Organization::factory(),
            'facility_id' => Facility::factory(),
            'invoice_id' => Invoice::factory(),
            'patient_id' => Patient::factory(),
            'adjustment_number' => 'ADJ-'.$this->faker->year().'-'.$this->faker->numerify('#####'),
            'type' => $this->faker->randomElement([BillingAdjustment::TYPE_CREDIT, BillingAdjustment::TYPE_DEBIT]),
            'amount_minor' => $this->faker->numberBetween(100, 100000),
            'currency' => 'NPR',
            'reason_code' => $this->faker->randomElement([
                BillingAdjustment::REASON_OVERCHARGE,
                BillingAdjustment::REASON_DUPLICATE,
                BillingAdjustment::REASON_DISCOUNT,
                BillingAdjustment::REASON_CORRECTION,
            ]),
            'status' => BillingAdjustment::STATUS_PENDING,
            'lock_version' => 0,
        ];
    }
}
