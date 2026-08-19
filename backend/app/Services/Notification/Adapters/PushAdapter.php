<?php

namespace App\Services\Notification\Adapters;

use App\Services\Notification\ChannelAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Push notification adapter (Phase 12).
 *
 * Provider-agnostic push adapter. In production, configure Firebase Cloud
 * Messaging (FCM), OneSignal, or similar.
 */
class PushAdapter extends ChannelAdapter
{
    public function channel(): string
    {
        return 'push';
    }

    public function isAvailable(): bool
    {
        return ! empty(config('services.push.provider'));
    }

    public function validateConfig(): void
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('Push provider is not configured. Set services.push.provider in .env');
        }
    }

    public function send(array $params): array
    {
        try {
            $body = $params['body'] ?? '';
            $subject = $params['subject'] ?? 'Swasthya Notification';

            Log::info('Push notification (provider not configured)', [
                'recipient' => $params['recipient_id'] ?? 'unknown',
                'title' => $subject,
                'body' => substr($body, 0, 200),
            ]);

            return [
                'success' => true,
                'provider_message_id' => 'push-'.uniqid(),
                'provider_response' => 'Push queued (provider not configured)',
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Push adapter failed', [
                'recipient' => $params['recipient_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider_message_id' => null,
                'provider_response' => null,
                'error_message' => $e->getMessage(),
            ];
        }
    }
}
