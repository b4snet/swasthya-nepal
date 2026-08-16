<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChecklistTemplate>
 */
class ChecklistTemplateFactory extends Factory
{
    protected $model = ChecklistTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facility = Facility::factory()->create();

        return [
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'code' => 'CL-'.strtoupper(Str::random(6)),
            'name' => 'Surgical Safety Checklist',
            'category' => ChecklistTemplate::CATEGORY_TIME_OUT,
            'steps' => [
                ['key' => 'id_verified', 'label' => 'Patient identity confirmed'],
                ['key' => 'site_marked', 'label' => 'Surgical site marked'],
                ['key' => 'procedure_confirmed', 'label' => 'Procedure confirmed'],
            ],
            'status' => ChecklistTemplate::STATUS_ACTIVE,
        ];
    }
}
