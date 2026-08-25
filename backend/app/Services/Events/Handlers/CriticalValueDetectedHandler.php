<?php

namespace App\Services\Events\Handlers;

use App\Models\DomainEvent;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Handles critical_value.detected events (Phase 33).
 *
 * Records the detection event for audit and observability.
 * The actual escalation is handled by the AutoEscalateCriticalValues
 * artisan command (Phase 26).
 */
final class CriticalValueDetectedHandler implements EventHandlerInterface
{
    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload ?? [];

        Log::info('Critical value detected — event recorded', [
            'event_id' => $event->getKey(),
            'critical_value_event_id' => $payload['critical_value_event_id'] ?? null,
            'patient_id' => $payload['patient_id'] ?? null,
            'severity' => $payload['severity'] ?? null,
        ]);
    }
}
