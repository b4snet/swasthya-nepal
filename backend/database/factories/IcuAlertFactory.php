<?php

namespace Database\Factories;

use App\Models\IcuAdmission;
use App\Models\IcuAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IcuAlert>
 */
class IcuAlertFactory extends Factory
{
    protected $model = IcuAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $admission = IcuAdmission::factory()->create();

        return [
            'tenant_id' => $admission->tenant_id,
            'facility_id' => $admission->facility_id,
            'icu_admission_id' => $admission->getKey(),
            'alert_type' => IcuAlert::TYPE_SCORE_ESCALATION,
            'severity' => 'medium',
            'message' => 'Early-warning score escalated.',
            'status' => IcuAlert::STATUS_OPEN,
        ];
    }
}
