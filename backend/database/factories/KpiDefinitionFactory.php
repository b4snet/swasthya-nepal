<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\KpiDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KpiDefinition>
 */
class KpiDefinitionFactory extends Factory
{
    protected $model = KpiDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => fn (): string => 'kpi-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Patient registrations',
            'domain' => KpiDefinition::DOMAIN_OPERATIONAL,
            'source_table' => 'patients',
            'date_column' => 'created_at',
            'filter' => [],
            'aggregation' => KpiDefinition::AGGREGATION_COUNT,
            'sum_column' => null,
            'unit' => null,
            'version' => 1,
            'status' => KpiDefinition::STATUS_ACTIVE,
            'lock_version' => 0,
        ];
    }
}
