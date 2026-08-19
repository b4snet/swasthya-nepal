<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-recipient per-channel delivery attempt (Phase 12).
 *
 * Tracks each delivery attempt through a specific channel adapter.
 * Includes provider tracking, retry state, and acknowledgement.
 */
class DeliveryAttempt extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BOUNCED = 'bounced';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'notification_id',
        'recipient_user_id',
        'channel',
        'status',
        'provider',
        'provider_message_id',
        'provider_response',
        'error_message',
        'attempt_number',
        'started_at',
        'completed_at',
        'delivered_at',
        'acknowledged_at',
        'acknowledgement_data',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'attempt_number' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'campaign_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }
}
