<?php

namespace App\Services\Notification;

/**
 * Abstract channel adapter (Phase 12).
 *
 * All notification delivery providers implement this interface.
 * The domain layer never depends on a specific vendor.
 *
 * Adapters must be stateless and idempotent — the same delivery attempt
 * with the same parameters must produce the same result.
 */
abstract class ChannelAdapter
{
    /**
     * Get the channel identifier (e.g., 'email', 'sms', 'push', 'voice').
     */
    abstract public function channel(): string;

    /**
     * Check if this adapter is configured and available.
     */
    abstract public function isAvailable(): bool;

    /**
     * Send a notification through this channel.
     *
     * @param array{
     *     recipient_id: string,
     *     recipient_email: ?string,
     *     recipient_phone: ?string,
     *     subject: ?string,
     *     body: string,
     *     priority: string,
     *     metadata: array,
     * } $params
     * @return array{
     *     success: bool,
     *     provider_message_id: ?string,
     *     provider_response: ?string,
     *     error_message: ?string,
     * }
     */
    abstract public function send(array $params): array;

    /**
     * Validate that the adapter has the required configuration.
     *
     * @throws \RuntimeException If configuration is missing.
     */
    abstract public function validateConfig(): void;
}
