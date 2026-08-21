<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RealtimeEvent;
use App\Services\RealtimeService;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 86 — Realtime Operations Center: SSE streaming endpoint,
 * polling fallback, and receipt management (read/ack/dismiss).
 */
final class RealtimeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RealtimeService $realtime,
    ) {}

    /**
     * GET /realtime/events — list events for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        if ($userId === null) {
            return Envelope::success(data: ['events' => [], 'total' => 0, 'unreadCount' => 0], request: $request);
        }

        $data = $this->realtime->getUserEvents(
            $userId,
            $request->query('facilityId'),
            $request->query('category'),
            $request->query('severity'),
            $request->query('eventStatus'),
            (int) $request->query('limit', 50),
            (int) $request->query('offset', 0),
        );

        return Envelope::success(data: $data, request: $request);
    }

    /**
     * GET /realtime/unread-count — unread count for the current user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        if ($userId === null) {
            return Envelope::success(data: ['count' => 0], request: $request);
        }

        $count = $this->realtime->unreadCount(
            $userId,
            $request->query('facilityId'),
        );

        return Envelope::success(data: ['count' => $count], request: $request);
    }

    /**
     * GET /realtime/severity-counts — unread counts by severity.
     */
    public function severityCounts(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        if ($userId === null) {
            return Envelope::success(data: ['info' => 0, 'warning' => 0, 'urgent' => 0, 'critical' => 0], request: $request);
        }

        $counts = $this->realtime->severityCounts(
            $userId,
            $request->query('facilityId'),
        );

        return Envelope::success(data: $counts, request: $request);
    }

    /**
     * POST /realtime/events/mark-read — mark specific events as read.
     */
    public function markRead(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        $validated = $request->validate([
            'eventIds' => ['required', 'array'],
            'eventIds.*' => ['required', 'string'],
        ]);

        $count = $this->realtime->markRead($userId, $validated['eventIds']);

        return Envelope::success(data: ['markedCount' => $count], request: $request);
    }

    /**
     * POST /realtime/events/mark-all-read — mark all events as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        $count = $this->realtime->markAllRead(
            $userId,
            $request->query('facilityId'),
        );

        return Envelope::success(data: ['markedCount' => $count], request: $request);
    }

    /**
     * POST /realtime/events/{eventId}/acknowledge — acknowledge an event.
     */
    public function acknowledge(Request $request, string $eventId): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $receipt = $this->realtime->acknowledge(
            $userId,
            $eventId,
            $validated['note'] ?? null,
        );

        $this->audit->record(
            'realtime.event.acknowledged',
            'realtime_event_receipts',
            $receipt->getKey(),
            ['eventId' => $eventId],
            $request,
        );

        return Envelope::success(data: $receipt->present(), request: $request);
    }

    /**
     * POST /realtime/events/{eventId}/dismiss — dismiss an event.
     */
    public function dismiss(Request $request, string $eventId): JsonResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();

        $receipt = $this->realtime->dismiss($userId, $eventId);

        return Envelope::success(data: $receipt->present(), request: $request);
    }

    /**
     * GET /realtime/stream — SSE endpoint for realtime event streaming.
     *
     * Falls back gracefully when Redis is unavailable.
     */
    public function stream(Request $request): StreamedResponse
    {
        $context = TenantContext::current();
        $userId = $context->user?->getKey();
        $tenantId = (string) $context->tenantId();
        $facilityId = $context->facilityId() ? (string) $context->facilityId() : null;

        $channel = "realtime:{$tenantId}";
        if ($facilityId !== null) {
            $channel .= ":{$facilityId}";
        }

        return response()->stream(function () use ($userId, $channel): void {
            // Send initial connection event
            echo "event: connected\ndata: ".json_encode(['userId' => $userId], JSON_THROW_ON_ERROR)."\n\n";
            ob_flush();
            flush();

            // Set up Redis subscription
            try {
                $redis = Redis::connection()->client();
                $pubsub = $redis->pubSub();
                $pubsub->subscribe([$channel]);

                // Listen for messages with timeout
                $start = time();
                $timeout = 300; // 5 minutes max, client reconnects

                while ((time() - $start) < $timeout) {
                    $message = $pubsub->readMessage(1.0);

                    if ($message !== null && $message->channel === $channel) {
                        $data = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);

                        // Filter to only events targeting this user
                        $targetUserIds = $data['targetUserIds'] ?? [];
                        if (in_array($userId, $targetUserIds, true)) {
                            echo "event: notification\ndata: ".json_encode($data['event'], JSON_THROW_ON_ERROR)."\n\n";
                            ob_flush();
                            flush();
                        }
                    }

                    // Send heartbeat every 30 seconds
                    if ((time() - $start) % 30 === 0) {
                        echo "event: heartbeat\ndata: ".json_encode(['ts' => now()->toIso8601String()], JSON_THROW_ON_ERROR)."\n\n";
                        ob_flush();
                        flush();
                    }
                }

                $pubsub->unsubscribe([$channel]);
            } catch (\Throwable) {
                // Redis unavailable — send heartbeat fallback
                $start = time();
                $timeout = 300;

                while ((time() - $start) < $timeout) {
                    echo "event: heartbeat\ndata: ".json_encode([
                        'ts' => now()->toIso8601String(),
                        'mode' => 'poll',
                    ], JSON_THROW_ON_ERROR)."\n\n";
                    ob_flush();
                    flush();
                    sleep(5);
                }
            }

            echo "event: disconnected\ndata: {}\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /realtime/events — dispatch a new realtime event (admin/system only).
     */
    public function store(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $validated = $request->validate([
            'eventType' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'in:appointment,clinical,pharmacy,billing,admin,system'],
            'severity' => ['nullable', 'string', 'in:info,warning,urgent,critical'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'actionUrl' => ['nullable', 'string', 'max:500'],
            'channel' => ['nullable', 'string', 'in:operations,clinical,finance,admin,emergency'],
            'targetRoles' => ['nullable', 'array'],
            'targetUsers' => ['nullable', 'array'],
            'broadcast' => ['nullable', 'boolean'],
            'acknowledgementRequired' => ['nullable', 'boolean'],
            'expiresInHours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $facilityId = $request->input('facilityId') ?? $context->facilityId();

        $event = $this->realtime->dispatch([
            'tenantId' => (string) $context->tenantId(),
            'facilityId' => $facilityId ? (string) $facilityId : null,
            'eventType' => $validated['eventType'],
            'category' => $validated['category'],
            'severity' => $validated['severity'] ?? RealtimeEvent::SEV_INFO,
            'priority' => $validated['priority'] ?? 'normal',
            'title' => $validated['title'],
            'message' => $validated['message'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'actionUrl' => $validated['actionUrl'] ?? null,
            'channel' => $validated['channel'] ?? RealtimeEvent::CH_OPERATIONS,
            'targetRoles' => $validated['targetRoles'] ?? null,
            'targetUsers' => $validated['targetUsers'] ?? null,
            'broadcast' => $validated['broadcast'] ?? false,
            'acknowledgementRequired' => $validated['acknowledgementRequired'] ?? false,
            'expiresAt' => isset($validated['expiresInHours'])
                ? now()->addHours($validated['expiresInHours'])
                : now()->addHours(24),
        ]);

        $this->audit->record(
            'realtime.event.dispatched',
            'realtime_events',
            $event->getKey(),
            ['eventType' => $event->event_type, 'severity' => $event->severity],
            $request,
        );

        return Envelope::success(data: $event->present(), status: 201, request: $request);
    }
}
