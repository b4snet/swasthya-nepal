<?php

namespace Database\Factories;

use App\Models\KpiDefinition;
use App\Models\MetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    protected $model = MetricSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kpi_definition_id' => fn (): string => KpiDefinition::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => KpiDefinition::query()->findOrFail($attributes['kpi_definition_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => KpiDefinition::query()->findOrFail($attributes['kpi_definition_id'])->facility_id,
            'period_start' => now()->startOfDay()->toIso8601String(),
            'period_end' => now()->endOfDay()->toIso8601String(),
            'value' => 0,
            'dimension' => [],
            'row_count' => 0,
            'generated_at' => now()->toIso8601String(),
            'generated_by_staff_id' => null,
            'lock_version' => 0,
        ];
    }
}
