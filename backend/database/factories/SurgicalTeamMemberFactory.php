<?php

namespace Database\Factories;

use App\Models\Procedure;
use App\Models\Staff;
use App\Models\SurgicalTeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurgicalTeamMember>
 */
class SurgicalTeamMemberFactory extends Factory
{
    protected $model = SurgicalTeamMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $procedure = Procedure::factory()->create();
        $staff = Staff::factory()->create([
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
        ]);

        return [
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
            'procedure_id' => $procedure->getKey(),
            'staff_id' => $staff->getKey(),
            'role' => SurgicalTeamMember::ROLE_SURGEON,
            'time_in' => now()->toIso8601String(),
        ];
    }
}
