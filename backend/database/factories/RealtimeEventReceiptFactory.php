<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RealtimeEvent;
use App\Models\RealtimeEventReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealtimeEventReceipt>
 */
class RealtimeEventReceiptFactory extends Factory
{
    protected $model = RealtimeEventReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Organization::factory(),
            'event_id' => RealtimeEvent::factory(),
            'user_id' => User::factory(),
            'status' => RealtimeEventReceipt::STATUS_DELIVERED,
            'delivered_at' => now(),
        ];
    }
}
