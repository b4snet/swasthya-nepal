<?php

namespace Database\Factories;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Procedure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    protected $model = ChecklistItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $procedure = Procedure::factory()->create();
        $template = ChecklistTemplate::factory()->create([
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
        ]);

        return [
            'tenant_id' => $procedure->tenant_id,
            'facility_id' => $procedure->facility_id,
            'procedure_id' => $procedure->getKey(),
            'checklist_template_id' => $template->getKey(),
            'step_key' => 'id_verified',
            'step_label' => 'Patient identity confirmed',
            'sequence' => 1,
            'category' => $template->category,
            'created_by' => null,
        ];
    }
}
