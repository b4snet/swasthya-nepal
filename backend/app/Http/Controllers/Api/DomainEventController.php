<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DomainEvent;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domain event observation API (Phase 33).
 *
 * Provides read-only visibility into the event outbox for
 * authorized operators. No PHI is exposed in event payloads
 * through this endpoint.
 */
final class DomainEventController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /domain-events — list events with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $query = DomainEvent::query()
            ->where('tenant_id', $context->tenantId());

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by event type
        if ($type = $request->input('type')) {
            $query->where('event_type', $type);
        }

        // Filter by aggregate type
        if ($aggregateType = $request->input('aggregateType')) {
            $query->where('aggregate_type', $aggregateType);
        }

        // Dead letter filter
        if ($request->boolean('deadLetter')) {
            $query->where('status', DomainEvent::STATUS_DEAD);
        }

        $perPage = min(100, max(1, (int) $request->input('perPage', 25)));
        $events = $query->orderByDesc('created_at')->paginate($perPage);

        return Envelope::success(data: $events, request: $request);
    }

    /**
     * GET /domain-events/{event} — show a single event.
     */
    public function show(DomainEvent $event): JsonResponse
    {
        return Envelope::success(data: [
            'id' => $event->getKey(),
            'eventType' => $event->event_type,
            'aggregateType' => $event->aggregate_type,
            'aggregateId' => $event->aggregate_id,
            'status' => $event->status,
            'attemptCount' => $event->attempt_count,
            'maxAttempts' => $event->max_attempts,
            'nextAttemptAt' => $event->next_attempt_at?->toIso8601String(),
            'processedAt' => $event->processed_at?->toIso8601String(),
            'lastError' => $event->last_error,
            'createdAt' => $event->created_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /domain-events/{event}/retry — manually retry a failed event.
     */
    public function retry(DomainEvent $event): JsonResponse
    {
        if (! $event->isRetryable()) {
            return Envelope::error('Event is not retryable.', 422);
        }

        $event->update([
            'status' => DomainEvent::STATUS_PENDING,
            'next_attempt_at' => now(),
        ]);

        $this->audit->record(
            'domain_event.retried',
            'domain_event',
            $event->getKey(),
            ['event_type' => $event->event_type],
        );

        return Envelope::success(data: ['status' => 'queued'], request: request());
    }

    /**
     * DELETE /domain-events/{event} — discard a dead-letter event.
     */
    public function discard(DomainEvent $event): JsonResponse
    {
        if (! $event->isDead()) {
            return Envelope::error('Only dead-letter events can be discarded.', 422);
        }

        $event->delete();

        $this->audit->record(
            'domain_event.discarded',
            'domain_event',
            $event->getKey(),
            ['event_type' => $event->event_type],
        );

        return Envelope::success(data: ['discarded' => true], request: request());
    }
}
