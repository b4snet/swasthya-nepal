<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationEvent>
 */
class IntegrationEventFactory extends Factory
{
    protected $model = IntegrationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Integration::query()
                ->findOrFail($attributes['integration_id'])->tenant_id,
            'integration_id' => fn (): string => Integration::factory()->create()->getKey(),
            'direction' => IntegrationEvent::DIRECTION_OUTBOUND,
            'message_type' => 'fhir.patient.export',
            'correlation_id' => (string) Str::uuid(),
            'consent_basis' => null,
            'payload' => ['resource' => 'Patient', 'ids' => 0],
            'status' => IntegrationEvent::STATUS_QUEUED,
            'attempts' => 0,
            'error' => null,
            'mapping_version' => '1',
            'occurred_at' => now(),
        ];
    }
}
