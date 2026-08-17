<?php

namespace Database\Factories;

use App\Models\CdssRule;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdssRule>
 */
class CdssRuleFactory extends Factory
{
    protected $model = CdssRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'facility_id' => Facility::factory(),
            'rule_type' => CdssRule::TYPE_INTERACTION,
            'code' => fake()->unique()->bothify('INT-####'),
            'name' => fake()->sentence(3),
            'severity' => CdssRule::SEVERITY_MAJOR,
            'spec' => [
                'medication_a_id' => null,
                'medication_b_id' => null,
                'class_a' => 'beta_blocker',
                'class_b' => 'calcium_channel_blocker',
                'mechanism' => 'Additive hypotension risk',
                'action' => 'Monitor blood pressure; consider alternative therapy.',
            ],
            'version' => 1,
            'status' => CdssRule::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => null,
        ];
    }

    public function interaction(): static
    {
        return $this->state(fn (): array => ['rule_type' => CdssRule::TYPE_INTERACTION]);
    }

    public function allergen(): static
    {
        return $this->state(fn (): array => [
            'rule_type' => CdssRule::TYPE_ALLERGEN,
            'severity' => CdssRule::SEVERITY_MAJOR,
            'spec' => [
                'medication_id' => null,
                'allergen_class' => 'penicillin',
                'action' => 'Do not prescribe unless explicitly indicated and reviewed.',
            ],
        ]);
    }

    public function dose(): static
    {
        return $this->state(fn (): array => [
            'rule_type' => CdssRule::TYPE_DOSE,
            'severity' => CdssRule::SEVERITY_MAJOR,
            'spec' => [
                'medication_id' => null,
                'max_daily_mg' => 4000,
                'min_daily_mg' => 0,
                'unit' => 'mg',
                'action' => 'Verify dose against renal function and weight.',
            ],
        ]);
    }

    public function pathway(): static
    {
        return $this->state(fn (): array => [
            'rule_type' => CdssRule::TYPE_PATHWAY,
            'severity' => null,
            'spec' => [
                'condition' => ['diagnosis_code' => 'I10'],
                'suggestion' => 'Consider the hypertension pathway: baseline labs and BP log.',
            ],
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => CdssRule::STATUS_ACTIVE]);
    }
}
