<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetTransfer>
 */
class AssetTransferFactory extends Factory
{
    protected $model = AssetTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => fn (): string => Asset::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Asset::query()->findOrFail($attributes['asset_id'])->facility_id,
            'to_location_id' => fn (array $attributes): string => Location::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'transferred_at' => fn (): string => now()->toIso8601String(),
        ];
    }
}
