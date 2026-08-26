<?php

namespace App\Services\Events\Handlers;

use App\Models\DomainEvent;
use App\Models\Notification;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Handles notification.created events (Phase 33).
 *
 * Creates an in-app notification record for the target user.
 * Idempotent: checks for existing notification before creating.
 */
final class SendNotificationHandler implements EventHandlerInterface
{
    public function handle(DomainEvent $event): void
    {
        $payload = $event->payload ?? [];

        $userId = $payload['user_id'] ?? null;
        $title = $payload['title'] ?? 'Notification';
        $body = $payload['body'] ?? '';
        $type = $payload['type'] ?? 'info';
        $link = $payload['link'] ?? null;

        if ($userId === null) {
            Log::warning('SendNotificationHandler: no user_id in payload', [
                'event_id' => $event->getKey(),
            ]);

            return;
        }

        // Idempotency: check if notification already exists for this event
        $existing = Notification::query()
            ->where('user_id', $userId)
            ->where('title', $title)
            ->where('created_at', '>=', $event->created_at->subSeconds(5))
            ->exists();

        if ($existing) {
            return; // Already processed
        }

        Notification::create([
            'user_id' => $userId,
            'tenant_id' => $event->tenant_id,
            'facility_id' => $event->facility_id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'link' => $link,
            'read' => false,
        ]);

        Log::info('Notification created from domain event', [
            'event_id' => $event->getKey(),
            'user_id' => $userId,
        ]);
    }
}
