<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureBillingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureBillingItem>
 */
class ProcedureBillingItemFactory extends Factory
{
    protected $model = ProcedureBillingItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Organization::factory(),
            'facility_id' => Facility::factory(),
            'procedure_id' => Procedure::factory(),
            'patient_id' => Patient::factory(),
            'item_code' => $this->faker->bothify('PROC-###'),
            'description' => $this->faker->sentence(3),
            'amount_minor' => $this->faker->numberBetween(1000, 500000),
            'currency' => 'NPR',
            'quantity' => 1,
            'tax_rate_bps' => 0,
            'status' => ProcedureBillingItem::STATUS_PENDING,
        ];
    }
}
