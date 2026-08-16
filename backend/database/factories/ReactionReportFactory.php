<?php

namespace Database\Factories;

use App\Models\ReactionReport;
use App\Models\Staff;
use App\Models\Transfusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReactionReport>
 */
class ReactionReportFactory extends Factory
{
    protected $model = ReactionReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $transfusion = Transfusion::factory()->create();
        $staff = Staff::factory()->create([
            'tenant_id' => $transfusion->tenant_id,
            'facility_id' => $transfusion->facility_id,
        ]);

        return [
            'tenant_id' => $transfusion->tenant_id,
            'facility_id' => $transfusion->facility_id,
            'transfusion_id' => $transfusion->getKey(),
            'occurred_at' => now()->toIso8601String(),
            'severity' => 'mild',
            'symptoms' => ['fever'],
            'status' => ReactionReport::STATUS_REPORTED,
            'reported_by_staff_id' => $staff->getKey(),
            'lock_version' => 0,
        ];
    }
}
