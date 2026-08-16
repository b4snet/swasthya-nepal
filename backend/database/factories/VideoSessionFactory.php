<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Teleconsult;
use App\Models\VideoSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoSession>
 */
class VideoSessionFactory extends Factory
{
    protected $model = VideoSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Teleconsult::query()->findOrFail($attributes['teleconsult_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Teleconsult::query()->findOrFail($attributes['teleconsult_id'])->facility_id,
            'teleconsult_id' => fn (): string => Teleconsult::factory()->create()->getKey(),
            'status' => VideoSession::STATUS_ACTIVE,
            'started_at' => now(),
            'ended_at' => null,
            'provider_session_ref' => null,
            'participant_type' => VideoSession::PARTICIPANT_PROVIDER,
            'recording_requested' => false,
            'recording_consent_verified' => false,
            'recording_started_at' => null,
            'recording_ended_at' => null,
            'recording_storage_ref' => null,
            'failure_reason' => null,
            'created_by_staff_id' => fn (array $attributes): string => Staff::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'lock_version' => 0,
        ];
    }
}
