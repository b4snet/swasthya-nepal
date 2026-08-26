<?php

namespace App\Services\Events;

use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Domain event dispatcher — outbox pattern (Phase 33).
 *
 * Events are persisted INSIDE the same transaction as the business
 * state change. This guarantees:
 *   - No lost events (transactional publish)
 *   - No false events (committed state = event)
 *
 * Usage:
 *   EventDispatcher::dispatch(
 *       eventType: 'notification.created',
 *       aggregateType: 'notification',
 *       aggregateId: $notification->id,
 *       payload: [...],
 *       causerId: $user->id,
 *       facilityId: $facilityId,
 *       tenantId: $tenantId,
 *   );
 *
 * OR inside a DB transaction:
 *   DB::transaction(function () use (...) {
 *       $record = Model::create([...]);
 *       EventDispatcher::dispatch([...]);
 *   });
 */
final class EventDispatcher
{
    /**
     * Dispatch a domain event into the outbox.
     *
     * Must be called INSIDE the same transaction as the business state change.
     * If no transaction is active, the event is persisted immediately.
     */
    public static function dispatch(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        ?array $payload = null,
        ?string $causerId = null,
        ?string $facilityId = null,
        ?string $tenantId = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
    ): DomainEvent {
        return DomainEvent::create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'causer_type' => $causerId ? self::class : null,
            'causer_id' => $causerId,
            'facility_id' => $facilityId,
            'tenant_id' => $tenantId,
            'correlation_id' => $correlationId ?? Str::uuid()->toString(),
            'idempotency_key' => $idempotencyKey,
            'status' => DomainEvent::STATUS_PENDING,
        ]);
    }

    /**
     * Dispatch multiple events atomically (within a transaction).
     */
    public static function dispatchMany(array $events): array
    {
        return array_map(fn (array $event) => self::dispatch(...$event), $events);
    }
}
