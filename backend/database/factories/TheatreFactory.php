<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Theatre;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Theatre>
 */
class TheatreFactory extends Factory
{
    protected $model = Theatre::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'code' => 'OT-'.strtoupper(Str::random(4)),
            'name' => 'Operating Theatre '.Str::upper(Str::random(2)),
            'status' => Theatre::STATUS_ACTIVE,
        ];
    }
}
