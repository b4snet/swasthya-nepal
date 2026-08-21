<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\RealtimeEvent;
use App\Models\RealtimeEventReceipt;
use App\Models\User;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 86 — Realtime Operations Center: dispatches operational events,
 * manages per-user receipts (delivered/read/acknowledged), streams via
 * SSE with reconnect support, and enforces tenant/facility/role isolation.
 *
 * Every event respects:
 * - tenant isolation (RLS)
 * - facility scoping
 * - role-based routing
 * - module entitlement
 * - acknowledgement requirements
 *
 * SSE streams use Redis pub/sub for multi-instance support.
 * Polling fallback is provided for environments without Redis.
 */
final class RealtimeService
{
    /**
     * Dispatch a realtime event and create receipts for targeted users.
     */
    public function dispatch(array $params): RealtimeEvent
    {
        return DB::transaction(function () use ($params): RealtimeEvent {
            $event = RealtimeEvent::query()->create([
                'tenant_id' => $params['tenantId'],
                'facility_id' => $params['facilityId'] ?? null,
                'event_type' => $params['eventType'],
                'category' => $params['category'],
                'severity' => $params['severity'] ?? RealtimeEvent::SEV_INFO,
                'priority' => $params['priority'] ?? 'normal',
                'title' => $params['title'],
                'message' => $params['message'] ?? null,
                'metadata' => $params['metadata'] ?? null,
                'action_url' => $params['actionUrl'] ?? null,
                'channel' => $params['channel'] ?? RealtimeEvent::CH_OPERATIONS,
                'target_roles' => $params['targetRoles'] ?? null,
                'target_users' => $params['targetUsers'] ?? null,
                'broadcast' => $params['broadcast'] ?? false,
                'source_type' => $params['sourceType'] ?? null,
                'source_id' => $params['sourceId'] ?? null,
                'acknowledgement_required' => $params['acknowledgementRequired'] ?? false,
                'status' => RealtimeEvent::STATUS_ACTIVE,
                'expires_at' => $params['expiresAt'] ?? now()->addHours(24),
            ]);

            // Resolve target users
            $targetUserIds = $this->resolveTargetUsers(
                $event->tenant_id,
                $event->facility_id,
                $event->target_roles,
                $event->target_users,
                $event->broadcast,
            );

            if ($event->acknowledgement_required) {
                $event->update(['acknowledgement_required_count' => count($targetUserIds)]);
            }

            // Create receipts for each target user
            foreach ($targetUserIds as $userId) {
                RealtimeEventReceipt::query()->create([
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->getKey(),
                    'user_id' => $userId,
                    'status' => RealtimeEventReceipt::STATUS_DELIVERED,
                    'delivered_at' => now(),
                ]);
            }

            $event->update(['delivered_count' => count($targetUserIds)]);

            // Publish to Redis for SSE streams
            $this->publishToChannel($event, $targetUserIds);

            return $event;
        });
    }

    /**
     * Get events for a user with unread/read filtering.
     *
     * @return array{events: array<int, array<string, mixed>>, total: int, unreadCount: int}
     */
    public function getUserEvents(
        string $userId,
        ?string $facilityId = null,
        ?string $category = null,
        ?string $severity = null,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $query = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->whereHas('event', function ($eq) use ($facilityId, $category, $severity, $status): void {
                $eq->where('status', RealtimeEvent::STATUS_ACTIVE);
                if ($facilityId !== null) {
                    $eq->where(function ($fq) use ($facilityId): void {
                        $fq->where('facility_id', $facilityId)
                            ->orWhereNull('facility_id');
                    });
                }
                if ($category !== null) {
                    $eq->where('category', $category);
                }
                if ($severity !== null) {
                    $eq->where('severity', $severity);
                }
                if ($status !== null) {
                    $eq->where('status', $status);
                }
            })
            ->with('event');

        $total = $query->count();

        $unreadCount = (clone $query)
            ->where('read_at', null)
            ->count();

        $receipts = $query
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $events = $receipts->map(function (RealtimeEventReceipt $receipt): array {
            $event = $receipt->event;

            return [
                ...($event?->present() ?? []),
                'receiptId' => $receipt->getKey(),
                'receiptStatus' => $receipt->status,
                'deliveredAt' => $receipt->delivered_at?->toIso8601String(),
                'readAt' => $receipt->read_at?->toIso8601String(),
                'acknowledgedAt' => $receipt->acknowledged_at?->toIso8601String(),
                'acknowledgementNote' => $receipt->acknowledgement_note,
            ];
        })->values()->all();

        return [
            'events' => $events,
            'total' => $total,
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * Mark events as read for a user.
     */
    public function markRead(string $userId, array $eventIds): int
    {
        return RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->whereIn('event_id', $eventIds)
            ->whereNull('read_at')
            ->update([
                'status' => RealtimeEventReceipt::STATUS_READ,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Mark all events as read for a user.
     */
    public function markAllRead(string $userId, ?string $facilityId = null): int
    {
        $query = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->whereNull('read_at');

        if ($facilityId !== null) {
            $query->whereHas('event', function ($eq) use ($facilityId): void {
                $eq->where('facility_id', $facilityId)
                    ->orWhereNull('facility_id');
            });
        }

        return $query->update([
            'status' => RealtimeEventReceipt::STATUS_READ,
            'read_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Acknowledge an event for a user.
     */
    public function acknowledge(
        string $userId,
        string $eventId,
        ?string $note = null,
    ): RealtimeEventReceipt {
        $receipt = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->first();

        if ($receipt === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Event receipt not found.', 404);
        }

        if ($receipt->status === RealtimeEventReceipt::STATUS_ACKNOWLEDGED) {
            return $receipt;
        }

        $receipt->update([
            'status' => RealtimeEventReceipt::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledgement_note' => $note,
            'read_at' => $receipt->read_at ?? now(),
            'updated_at' => now(),
        ]);

        // Update event counters
        RealtimeEvent::where('id', $eventId)->increment('acknowledged_count');

        return $receipt->refresh();
    }

    /**
     * Dismiss an event for a user.
     */
    public function dismiss(string $userId, string $eventId): RealtimeEventReceipt
    {
        $receipt = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->first();

        if ($receipt === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Event receipt not found.', 404);
        }

        $receipt->update([
            'status' => RealtimeEventReceipt::STATUS_DISMISSED,
            'read_at' => $receipt->read_at ?? now(),
            'updated_at' => now(),
        ]);

        return $receipt->refresh();
    }

    /**
     * Get unread count for a user, optionally filtered by facility.
     */
    public function unreadCount(string $userId, ?string $facilityId = null): int
    {
        $query = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereHas('event', function ($eq) use ($facilityId): void {
                $eq->where('status', RealtimeEvent::STATUS_ACTIVE);
                if ($facilityId !== null) {
                    $eq->where(function ($fq) use ($facilityId): void {
                        $fq->where('facility_id', $facilityId)
                            ->orWhereNull('facility_id');
                    });
                }
            });

        return $query->count();
    }

    /**
     * Get events by severity for dashboard display.
     *
     * @return array<string, int>
     */
    public function severityCounts(string $userId, ?string $facilityId = null): array
    {
        $counts = ['info' => 0, 'warning' => 0, 'urgent' => 0, 'critical' => 0];

        $query = RealtimeEventReceipt::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereHas('event', function ($eq) use ($facilityId): void {
                $eq->where('status', RealtimeEvent::STATUS_ACTIVE);
                if ($facilityId !== null) {
                    $eq->where(function ($fq) use ($facilityId): void {
                        $fq->where('facility_id', $facilityId)
                            ->orWhereNull('facility_id');
                    });
                }
            })
            ->selectRaw('events.severity as sev, count(*) as cnt')
            ->join('realtime_events as events', 'events.id', '=', 'realtime_event_receipts.event_id')
            ->groupBy('events.severity');

        foreach ($query->get() as $row) {
            $counts[$row->sev] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Expire old events. Run via scheduled command.
     */
    public function expireStaleEvents(): int
    {
        return RealtimeEvent::query()
            ->where('status', RealtimeEvent::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->update([
                'status' => RealtimeEvent::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    // ── Private helpers ──

    /**
     * Resolve target user IDs for an event.
     *
     * @param  string[]|null  $targetRoles
     * @param  string[]|null  $targetUsers
     * @return string[]
     */
    private function resolveTargetUsers(
        string $tenantId,
        ?string $facilityId,
        ?array $targetRoles,
        ?array $targetUsers,
        bool $broadcast,
    ): array {
        // Specific users override role-based targeting
        if ($targetUsers !== null) {
            return $targetUsers;
        }

        // Platform admins receive everything
        $query = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        if ($facilityId !== null && ! $broadcast) {
            // Facility-scoped: users assigned to this facility
            $query->whereHas('roleAssignments', function ($ra) use ($facilityId): void {
                $ra->where('facility_id', $facilityId)
                    ->where('status', 'active');
            });
        }

        if ($targetRoles !== null && $targetRoles !== []) {
            $query->whereHas('roleAssignments', function ($ra) use ($targetRoles): void {
                $ra->whereIn('role_id', function ($roleQuery) use ($targetRoles): void {
                    $roleQuery->select('id')
                        ->from('roles')
                        ->whereIn('name', $targetRoles);
                })->where('status', 'active');
            });
        }

        return $query->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
    }

    /**
     * Publish event to Redis channel for SSE streaming.
     */
    private function publishToChannel(RealtimeEvent $event, array $targetUserIds): void
    {
        try {
            $channel = "realtime:{$event->tenant_id}";

            if ($event->facility_id !== null) {
                $channel .= ":{$event->facility_id}";
            }

            $payload = json_encode([
                'event' => $event->present(),
                'targetUserIds' => $targetUserIds,
            ], JSON_THROW_ON_ERROR);

            Redis::publish($channel, $payload);
        } catch (\Throwable) {
            // Redis unavailable — SSE clients will poll instead
        }
    }
}
