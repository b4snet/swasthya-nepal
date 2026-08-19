<?php

namespace App\Services\Notification\Adapters;

use App\Services\Notification\ChannelAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email notification adapter (Phase 12).
 *
 * Provider-agnostic email adapter. Uses Laravel's mailer abstraction.
 * In production, configure a real mail driver (SMTP, SES, Mailgun, etc.).
 *
 * The adapter never exposes the mail driver to the domain layer.
 */
class EmailAdapter extends ChannelAdapter
{
    public function channel(): string
    {
        return 'email';
    }

    public function isAvailable(): bool
    {
        return config('mail.default') !== 'log';
    }

    public function validateConfig(): void
    {
        // Laravel's mail config is validated at boot time
    }

    public function send(array $params): array
    {
        try {
            $email = $params['recipient_email'] ?? null;
            if (empty($email)) {
                return [
                    'success' => false,
                    'provider_message_id' => null,
                    'provider_response' => null,
                    'error_message' => 'No recipient email address',
                ];
            }

            // Use Laravel's mailer to send
            $subject = $params['subject'] ?? 'Swasthya Notification';
            $body = $params['body'] ?? '';

            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)
                    ->subject($subject)
                    ->from(config('mail.from.address', 'noreply@swasthya.gov.np'), config('mail.from.name', 'Swasthya'));
            });

            return [
                'success' => true,
                'provider_message_id' => 'email-'.uniqid(),
                'provider_response' => 'Email sent successfully',
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Email adapter failed', [
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
