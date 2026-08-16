<?php

namespace Database\Factories;

use App\Models\ReportRun;
use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportRun>
 */
class ReportRunFactory extends Factory
{
    protected $model = ReportRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_id' => fn (): string => ReportTemplate::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => ReportTemplate::query()->findOrFail($attributes['template_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => ReportTemplate::query()->findOrFail($attributes['template_id'])->facility_id,
            'schedule_id' => null,
            'requested_by_staff_id' => null,
            'status' => ReportRun::STATUS_QUEUED,
            'run_at' => now()->toIso8601String(),
            'completed_at' => null,
            'row_count' => 0,
            'error_message' => null,
            'is_export' => false,
            'export_format' => null,
            'output_checksum' => null,
            'lock_version' => 0,
        ];
    }
}
