<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => fn (): string => 'rpt-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Registrations report',
            'category' => ReportTemplate::CATEGORY_OPERATIONAL,
            'scope' => ReportTemplate::SCOPE_FACILITY,
            'parameter_schema' => [],
            'query' => ['source_table' => 'patients', 'filter' => [], 'date_column' => 'created_at', 'period' => 'last_7_days'],
            'is_active' => true,
            'lock_version' => 0,
        ];
    }
}
