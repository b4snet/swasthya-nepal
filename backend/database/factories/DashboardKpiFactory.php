<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\DashboardKpi;
use App\Models\KpiDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardKpi>
 */
class DashboardKpiFactory extends Factory
{
    protected $model = DashboardKpi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => fn (): string => Dashboard::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Dashboard::query()->findOrFail($attributes['dashboard_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Dashboard::query()->findOrFail($attributes['dashboard_id'])->facility_id,
            'kpi_definition_id' => fn (array $attributes): string => KpiDefinition::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'position' => 1,
            'is_active' => true,
        ];
    }
}
