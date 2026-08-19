<?php

namespace App\Services\Notification\Adapters;

use App\Models\Notification;
use App\Services\Notification\ChannelAdapter;

/**
 * In-app notification adapter (Phase 12).
 *
 * Creates a notification record directly — synchronous, no external provider.
 * This is the primary channel for real-time in-app alerts.
 */
class InAppAdapter extends ChannelAdapter
{
    public function channel(): string
    {
        return Notification::CHANNEL_IN_APP;
    }

    public function isAvailable(): bool
    {
        return true; // Always available — no external dependency
    }

    public function validateConfig(): void
    {
        // No configuration needed for in-app
    }

    public function send(array $params): array
    {
        // In-app notifications are created directly — the notification record
        // IS the delivery. No external provider round-trip.
        return [
            'success' => true,
            'provider_message_id' => null,
            'provider_response' => null,
            'error_message' => null,
        ];
    }
}
