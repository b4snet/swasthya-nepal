<?php

namespace Database\Factories;

use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\Study;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RadiologyReport>
 */
class RadiologyReportFactory extends Factory
{
    protected $model = RadiologyReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Study::query()->findOrFail($attributes['study_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Study::query()->findOrFail($attributes['study_id'])->facility_id,
            'study_id' => fn (): string => Study::factory()->create()->getKey(),
            'report_type' => RadiologyReport::TYPE_FINAL,
            'status' => RadiologyReport::STATUS_FINAL,
            'content' => fake()->sentence(),
            'impression' => fake()->sentence(),
            'critical_findings' => null,
            'reported_by_staff_id' => fn (): string => Staff::factory()->create()->getKey(),
            'reported_at' => fn (): string => now()->toDateTimeString(),
            'verified_by_staff_id' => null,
            'verified_at' => null,
            'parent_report_id' => null,
            'lock_version' => 0,
        ];
    }
}
