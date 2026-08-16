<?php

namespace Database\Factories;

use App\Models\FollowUp;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => FollowUp::query()->findOrFail($attributes['follow_up_id'])->tenant_id,
            'patient_id' => fn (array $attributes): string => FollowUp::query()->findOrFail($attributes['follow_up_id'])->patient_id,
            'follow_up_id' => fn (): string => FollowUp::factory()->create()->getKey(),
            'type' => Notification::TYPE_APPOINTMENT_REMINDER,
            'channel' => Notification::CHANNEL_IN_APP,
            'payload' => ['followUpId' => null, 'plannedAt' => null],
            'status' => Notification::STATUS_SENT,
            'sensitive' => true,
        ];
    }
}
