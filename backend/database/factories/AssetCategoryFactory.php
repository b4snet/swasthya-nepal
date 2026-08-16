<?php

namespace Database\Factories;

use App\Models\AssetCategory;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => 'AST-'.strtoupper(Str::random(8)),
            'name' => fake()->randomElement(['Imaging', 'Monitoring', 'Surgical', 'General']),
            'status' => AssetCategory::STATUS_ACTIVE,
        ];
    }
}
