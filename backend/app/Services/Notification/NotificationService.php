<?php

namespace App\Services\Notification;

use App\Models\BroadcastCampaign;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Notification\Adapters\EmailAdapter;
use App\Services\Notification\Adapters\InAppAdapter;
use App\Services\Notification\Adapters\PushAdapter;
use App\Services\Notification\Adapters\SmsAdapter;

/**
 * Core notification service (Phase 12).
 *
 * Orchestrates the full notification lifecycle:
 * - Campaign creation and lifecycle management
 * - Audience resolution
 * - Message rendering
 * - Multi-channel delivery
 * - Delivery tracking and retry
 * - Acknowledgement handling
 * - Emergency broadcast mode
 *
 * All operations are tenant-scoped and idempotent.
 */
class NotificationService
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'in_app' => new InAppAdapter,
            'email' => new EmailAdapter,
            'sms' => new SmsAdapter,
            'push' => new PushAdapter,
        ];
    }

    /**
     * Get a channel adapter by name.
     */
    public function getAdapter(string $channel): ChannelAdapter
    {
        if (! isset($this->adapters[$channel])) {
            throw new \InvalidArgumentException("Unknown channel: {$channel}");
        }

        return $this->adapters[$channel];
    }

    /**
     * Create a notification (simple in-app, no campaign).
     */
    public function createNotification(
        string $tenantId,
        ?string $userId,
        string $type,
        string $channel,
        array $payload,
        bool $sensitive = false,
        ?string $patientId = null,
        ?string $campaignId = null,
    ): Notification {
        return Notification::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'patient_id' => $patientId,
            'type' => $type,
            'channel' => $channel,
            'payload' => $payload,
            'status' => Notification::STATUS_SENT, // In-app is synchronous
            'sensitive' => $sensitive,
            'campaign_id' => $campaignId,
            'priority' => 'normal',
            'severity' => 'info',
        ]);
    }

    /**
     * Create and dispatch a broadcast campaign.
     */
    public function dispatchCampaign(BroadcastCampaign $campaign): void
    {
        if ($campaign->status !== BroadcastCampaign::STATUS_APPROVED
            && $campaign->status !== BroadcastCampaign::STATUS_SCHEDULED
            && ! $campaign->isEmergency()
        ) {
            throw new \RuntimeException("Campaign {$campaign->id} is not approved for dispatch");
        }

        // Resolve recipients
        $recipientIds = $this->resolveRecipients($campaign);
        $campaign->update([
            'status' => BroadcastCampaign::STATUS_SENDING,
            'started_at' => now(),
            'total_recipients' => count($recipientIds),
        ]);

        // Create recipient records
        foreach ($recipientIds as $userId) {
            NotificationRecipient::firstOrCreate(
                ['campaign_id' => $campaign->id, 'user_id' => $userId],
                ['tenant_id' => $campaign->tenant_id, 'delivery_status' => 'pending']
            );
        }

        // Deliver through each channel
        $channels = $campaign->getChannels();
        $template = $campaign->template;
        $content = $campaign->message_content;

        foreach ($recipientIds as $userId) {
            foreach ($channels as $channel) {
                $this->deliverToRecipient($campaign, $userId, $channel, $template, $content);
            }
        }

        // Update campaign status
        $this->refreshCampaignStatus($campaign);
    }

    /**
     * Resolve recipients for a campaign.
     */
    private function resolveRecipients(BroadcastCampaign $campaign): array
    {
        // Use segment if available
        if ($campaign->segment_id) {
            $segment = $campaign->segment;
            if ($segment) {
                return $segment->resolveRecipientIds();
            }
        }

        // Use inline targeting criteria
        $criteria = $campaign->targeting_criteria;
        if (empty($criteria)) {
            return [];
        }

        $query = User::query();

        if (isset($criteria['user_ids']) && is_array($criteria['user_ids'])) {
            return $criteria['user_ids'];
        }

        if (isset($criteria['role_codes']) && is_array($criteria['role_codes'])) {
            $roleIds = Role::whereIn('code', $criteria['role_codes'])->pluck('id');
            $assignmentUserIds = RoleAssignment::whereIn('role_id', $roleIds)
                ->where('status', 'active')
                ->pluck('user_id');
            $query->whereIn('id', $assignmentUserIds);
        }

        if (isset($criteria['facility_ids']) && is_array($criteria['facility_ids'])) {
            $assignmentUserIds = RoleAssignment::whereIn('facility_id', $criteria['facility_ids'])
                ->where('status', 'active')
                ->pluck('user_id');
            $query->whereIn('id', $assignmentUserIds);
        }

        return $query->distinct()->pluck('id')->toArray();
    }

    /**
     * Deliver to a single recipient through a single channel.
     */
    private function deliverToRecipient(
        BroadcastCampaign $campaign,
        string $userId,
        string $channel,
        ?NotificationTemplate $template,
        array $content,
    ): void {
        // Render message
        $rendered = $this->renderMessage($template, $content, $userId);

        // Create delivery attempt
        $attempt = DeliveryAttempt::create([
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'recipient_user_id' => $userId,
            'channel' => $channel,
            'status' => DeliveryAttempt::STATUS_SENDING,
            'attempt_number' => 1,
            'started_at' => now(),
        ]);

        // Create in-app notification
        if ($channel === 'in_app') {
            $notification = $this->createNotification(
                $campaign->tenant_id,
                $userId,
                $campaign->is_emergency() ? 'emergency' : 'broadcast',
                'in_app',
                array_merge($rendered, ['campaign_id' => $campaign->id]),
                false,
                null,
                $campaign->id,
            );
            $attempt->update(['notification_id' => $notification->id]);
        }

        // Send through channel adapter
        $adapter = $this->getAdapter($channel);
        $result = $adapter->send([
            'recipient_id' => $userId,
            'recipient_email' => null, // Would be resolved from user model
            'recipient_phone' => null, // Would be resolved from user model
            'subject' => $rendered['subject'] ?? null,
            'body' => $rendered['body'] ?? '',
            'priority' => $campaign->priority,
            'metadata' => ['campaign_id' => $campaign->id],
        ]);

        // Update attempt status
        $attempt->update([
            'status' => $result['success'] ? DeliveryAttempt::STATUS_SENT : DeliveryAttempt::STATUS_FAILED,
            'provider' => $channel,
            'provider_message_id' => $result['provider_message_id'],
            'provider_response' => $result['provider_response'],
            'error_message' => $result['error_message'],
            'completed_at' => now(),
        ]);
    }

    /**
     * Render a message from template or inline content.
     */
    private function renderMessage(
        ?NotificationTemplate $template,
        array $content,
        string $userId,
    ): array {
        if ($template) {
            return $template->render(array_merge($content, ['user_id' => $userId]));
        }

        return [
            'subject' => $content['subject'] ?? 'Swasthya Notification',
            'body' => $content['body'] ?? '',
        ];
    }

    /**
     * Refresh campaign status based on delivery attempts.
     */
    private function refreshCampaignStatus(BroadcastCampaign $campaign): void
    {
        $total = $campaign->total_recipients;
        $delivered = $campaign->deliveryAttempts()
            ->where('status', DeliveryAttempt::STATUS_SENT)
            ->count();
        $failed = $campaign->deliveryAttempts()
            ->where('status', DeliveryAttempt::STATUS_FAILED)
            ->count();

        $campaign->update([
            'delivered_count' => $delivered,
            'failed_count' => $failed,
        ]);

        if ($delivered >= $total && $total > 0) {
            $campaign->update([
                'status' => BroadcastCampaign::STATUS_SENT,
                'completed_at' => now(),
            ]);
        } elseif ($failed > 0 && ($delivered + $failed) >= $total) {
            $campaign->update([
                'status' => BroadcastCampaign::STATUS_PARTIALLY_DELIVERED,
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Process retry for failed delivery attempts.
     */
    public function processRetries(): int
    {
        $retryable = DeliveryAttempt::where('status', DeliveryAttempt::STATUS_FAILED)
            ->whereColumn('attempt_number', '<', 'max_retries')
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->with('campaign')
            ->limit(50)
            ->get();

        $processed = 0;

        foreach ($retryable as $attempt) {
            if (! $attempt->campaign) {
                continue;
            }

            $retryConfig = $attempt->campaign->getRetryConfig();
            $newAttemptNumber = $attempt->attempt_number + 1;
            $backoff = $retryConfig['backoff_seconds'] * pow($retryConfig['backoff_multiplier'], $newAttemptNumber - 1);

            // Create new attempt
            DeliveryAttempt::create([
                'tenant_id' => $attempt->tenant_id,
                'campaign_id' => $attempt->campaign_id,
                'recipient_user_id' => $attempt->recipient_user_id,
                'channel' => $attempt->channel,
                'status' => DeliveryAttempt::STATUS_PENDING,
                'attempt_number' => $newAttemptNumber,
            ]);

            // Mark original as superseded
            $attempt->update([
                'next_retry_at' => now()->addSeconds($backoff),
            ]);

            $processed++;
        }

        return $processed;
    }

    /**
     * Acknowledge a notification.
     */
    public function acknowledge(DeliveryAttempt $attempt, ?string $data = null): void
    {
        $attempt->update([
            'status' => DeliveryAttempt::STATUS_DELIVERED,
            'acknowledged_at' => now(),
            'acknowledgement_data' => $data,
        ]);

        // Update recipient status
        NotificationRecipient::where('campaign_id', $attempt->campaign_id)
            ->where('user_id', $attempt->recipient_user_id)
            ->update([
                'delivery_status' => 'acknowledged',
                'acknowledged_at' => now(),
            ]);

        // Update campaign acknowledgement count
        BroadcastCampaign::where('id', $attempt->campaign_id)
            ->increment('acknowledged_count');
    }

    /**
     * Cancel a campaign.
     */
    public function cancelCampaign(BroadcastCampaign $campaign, string $reason): void
    {
        if (! $campaign->canTransitionTo(BroadcastCampaign::STATUS_CANCELLED)) {
            throw new \RuntimeException("Cannot cancel campaign in status: {$campaign->status}");
        }

        $campaign->update([
            'status' => BroadcastCampaign::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'completed_at' => now(),
        ]);
    }

    /**
     * Approve a campaign.
     */
    public function approveCampaign(BroadcastCampaign $campaign, string $approvedBy): void
    {
        if (! $campaign->canTransitionTo(BroadcastCampaign::STATUS_APPROVED)) {
            throw new \RuntimeException("Cannot approve campaign in status: {$campaign->status}");
        }

        $campaign->update([
            'status' => BroadcastCampaign::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);
    }
}
