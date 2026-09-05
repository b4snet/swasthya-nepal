<?php

namespace Database\Factories;

use App\Models\StaffTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffTransferFactory extends Factory
{
    protected $model = StaffTransfer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'staff_id' => $this->faker->uuid(),
            'from_department_id' => $this->faker->uuid(),
            'to_department_id' => $this->faker->uuid(),
            'reason' => $this->faker->sentence(),
            'authorized_by_staff_id' => $this->faker->uuid(),
            'effective_at' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
        ];
    }
}
