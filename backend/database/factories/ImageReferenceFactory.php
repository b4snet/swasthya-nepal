<?php

namespace Database\Factories;

use App\Models\ImageReference;
use App\Models\Study;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImageReference>
 */
class ImageReferenceFactory extends Factory
{
    protected $model = ImageReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Study::query()->findOrFail($attributes['study_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Study::query()->findOrFail($attributes['study_id'])->facility_id,
            'study_id' => fn (): string => Study::factory()->create()->getKey(),
            'reference_type' => ImageReference::TYPE_STUDY_UID,
            'reference_value' => '1.2.826.0.1.3680043.8.498.'.strtoupper(Str::random(10)),
            'description' => null,
        ];
    }
}
