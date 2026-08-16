<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn (): string => AssetCategory::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => AssetCategory::query()->findOrFail($attributes['category_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => AssetCategory::query()->findOrFail($attributes['category_id'])->facility_id,
            'name' => fake()->randomElement(['Ventilator', 'MRI Scanner', 'ECG Machine', 'Defibrillator']),
            'serial_number' => 'SN-'.strtoupper(Str::random(10)),
            'purchase_value_minor' => 100000,
            'purchase_date' => fn (): string => now()->subYear()->toDateString(),
            'warranty_until' => fn (): string => now()->addYear()->toDateString(),
            'lifecycle_status' => Asset::LIFECYCLE_PROCURED,
            'status' => Asset::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
