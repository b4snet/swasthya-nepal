<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\IotReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IotReading>
 */
class IotReadingFactory extends Factory
{
    protected $model = IotReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => fn (): string => Asset::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->facility_id,
            'reading_type' => IotReading::TYPE_LOCATION,
            'reading_value' => ['zone' => 'ICU'],
            'read_at' => fn (): string => now()->toIso8601String(),
            'source' => IotReading::SOURCE_MANUAL,
        ];
    }
}
