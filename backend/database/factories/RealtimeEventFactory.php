<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\RealtimeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealtimeEvent>
 */
class RealtimeEventFactory extends Factory
{
    protected $model = RealtimeEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement([
            RealtimeEvent::TYPE_APPOINTMENT_CHECK_IN,
            RealtimeEvent::TYPE_QUEUE_UPDATE,
            RealtimeEvent::TYPE_LAB_CRITICAL_VALUE,
            RealtimeEvent::TYPE_LAB_RESULT_READY,
            RealtimeEvent::TYPE_BILLING_INVOICE_ISSUED,
            RealtimeEvent::TYPE_PHARMACY_DISPENSED,
            RealtimeEvent::TYPE_ICU_CRITICAL_ALERT,
            RealtimeEvent::TYPE_SYSTEM_ALERT,
        ]);

        $categoryMap = [
            RealtimeEvent::TYPE_APPOINTMENT_CHECK_IN => RealtimeEvent::CAT_APPOINTMENT,
            RealtimeEvent::TYPE_QUEUE_UPDATE => RealtimeEvent::CAT_APPOINTMENT,
            RealtimeEvent::TYPE_LAB_CRITICAL_VALUE => RealtimeEvent::CAT_CLINICAL,
            RealtimeEvent::TYPE_LAB_RESULT_READY => RealtimeEvent::CAT_CLINICAL,
            RealtimeEvent::TYPE_BILLING_INVOICE_ISSUED => RealtimeEvent::CAT_BILLING,
            RealtimeEvent::TYPE_PHARMACY_DISPENSED => RealtimeEvent::CAT_PHARMACY,
            RealtimeEvent::TYPE_ICU_CRITICAL_ALERT => RealtimeEvent::CAT_CLINICAL,
            RealtimeEvent::TYPE_SYSTEM_ALERT => RealtimeEvent::CAT_SYSTEM,
        ];

        return [
            'tenant_id' => Organization::factory(),
            'facility_id' => Facility::factory(),
            'event_type' => $type,
            'category' => $categoryMap[$type] ?? RealtimeEvent::CAT_SYSTEM,
            'severity' => $this->faker->randomElement([
                RealtimeEvent::SEV_INFO,
                RealtimeEvent::SEV_WARNING,
                RealtimeEvent::SEV_URGENT,
                RealtimeEvent::SEV_CRITICAL,
            ]),
            'priority' => $this->faker->randomElement(['low', 'normal', 'high', 'urgent']),
            'title' => $this->faker->sentence(4),
            'message' => $this->faker->optional(0.7)->sentence(10),
            'channel' => $this->faker->randomElement([
                RealtimeEvent::CH_OPERATIONS,
                RealtimeEvent::CH_CLINICAL,
                RealtimeEvent::CH_FINANCE,
            ]),
            'status' => RealtimeEvent::STATUS_ACTIVE,
            'expires_at' => now()->addHours(24),
        ];
    }
}
