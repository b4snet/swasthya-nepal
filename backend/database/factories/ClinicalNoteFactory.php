<?php

namespace Database\Factories;

use App\Models\ClinicalNote;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalNote>
 */
class ClinicalNoteFactory extends Factory
{
    protected $model = ClinicalNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->tenant_id,
            'encounter_id' => fn (): string => Encounter::factory()->create()->getKey(),
            'note_type' => ClinicalNote::TYPE_CONSULTATION,
            'author_staff_id' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->provider_staff_id,
            'content' => [
                'complaint' => fake()->sentence(8),
                'history' => fake()->sentence(10),
                'examination' => fake()->sentence(8),
            ],
            'status' => ClinicalNote::STATUS_DRAFT,
            'lock_version' => 0,
        ];
    }
}
