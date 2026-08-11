<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientTimelineEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientTimelineEntry>
 */
class PatientTimelineEntryFactory extends Factory
{
    protected $model = PatientTimelineEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            'occurred_at' => now(),
            'event_type' => 'patient.registered',
            'summary' => [],
        ];
    }
}
