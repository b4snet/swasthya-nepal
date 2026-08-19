<?php

namespace App\Services\Notification\Adapters;

use App\Services\Notification\ChannelAdapter;
use Illuminate\Support\Facades\Log;

/**
 * SMS notification adapter (Phase 12).
 *
 * Provider-agnostic SMS adapter. In production, configure a real SMS
 * provider (Twilio, Nexmo, local Nepal SMS gateway, etc.).
 *
 * The adapter never exposes the SMS provider to the domain layer.
 */
class SmsAdapter extends ChannelAdapter
{
    public function channel(): string
    {
        return 'sms';
    }

    public function isAvailable(): bool
    {
        return ! empty(config('services.sms.provider'));
    }

    public function validateConfig(): void
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('SMS provider is not configured. Set services.sms.provider in .env');
        }
    }

    public function send(array $params): array
    {
        try {
            $phone = $params['recipient_phone'] ?? null;
            if (empty($phone)) {
                return [
                    'success' => false,
                    'provider_message_id' => null,
                    'provider_response' => null,
                    'error_message' => 'No recipient phone number',
                ];
            }

            $body = $params['body'] ?? '';

            // Provider abstraction — in production, replace with actual provider
            // For now, log the SMS as a placeholder
            Log::info('SMS notification (provider not configured)', [
                'recipient' => $phone,
                'body' => substr($body, 0, 160),
            ]);

            return [
                'success' => true,
                'provider_message_id' => 'sms-'.uniqid(),
                'provider_response' => 'SMS queued (provider not configured)',
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('SMS adapter failed', [
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
