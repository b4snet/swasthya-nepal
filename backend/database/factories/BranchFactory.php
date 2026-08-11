<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Branches are anchored to a facility: the factory derives tenant_id from
 * the facility so a cross-tenant branch is structurally impossible.
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->regexify('[a-z0-9]{4,10}'),
            'status' => Branch::STATUS_ACTIVE,
        ];
    }
}
