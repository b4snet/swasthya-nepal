<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\IcuBed;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IcuBed>
 */
class IcuBedFactory extends Factory
{
    protected $model = IcuBed::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'bed_code' => 'ICU-'.strtoupper(Str::random(4)),
            'status' => IcuBed::STATUS_AVAILABLE,
            'acuity_supported' => 'level_3',
            'lock_version' => 0,
        ];
    }
}
