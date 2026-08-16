<?php

namespace Database\Factories;

use App\Models\IcuObservationSet;
use App\Models\WarningScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarningScore>
 */
class WarningScoreFactory extends Factory
{
    protected $model = WarningScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Anchor first, then derive — closures receive the RESOLVED
            // attributes in definition order.
            'observation_set_id' => fn (): string => IcuObservationSet::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => IcuObservationSet::query()->findOrFail($attributes['observation_set_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => IcuObservationSet::query()->findOrFail($attributes['observation_set_id'])->facility_id,
            'icu_admission_id' => fn (array $attributes): string => IcuObservationSet::query()->findOrFail($attributes['observation_set_id'])->icu_admission_id,
            'score_total' => 0,
            'severity' => WarningScore::SEVERITY_LOW,
            'breakdown' => [],
            'scale_version' => 'news-1',
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
