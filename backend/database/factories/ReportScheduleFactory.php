<?php

namespace Database\Factories;

use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => fn (): string => ReportTemplate::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => ReportTemplate::query()->findOrFail($attributes['template_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => ReportTemplate::query()->findOrFail($attributes['template_id'])->facility_id,
            'cron_expression' => '0 6 * * *',
            'enabled' => true,
            'last_run_at' => null,
            'next_run_at' => null,
            'created_by_staff_id' => null,
            'lock_version' => 0,
        ];
    }
}
