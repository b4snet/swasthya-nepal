<?php

namespace Database\Factories;

use App\Models\StudyEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudyEventFactory extends Factory
{
    protected $model = StudyEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'study_id' => $this->faker->uuid(),
            'event_type' => $this->faker->randomElement([
                'ordered', 'scheduled', 'rescheduled', 'arrived', 'in_progress',
                'acquired', 'performed', 'report_drafted', 'report_verified',
                'report_amended', 'cancelled', 'rejected', 'released_to_clinician',
            ]),
            'event_description' => $this->faker->sentence(),
            'actor_staff_id' => $this->faker->uuid(),
            'metadata' => ['key' => $this->faker->word()],
        ];
    }
}