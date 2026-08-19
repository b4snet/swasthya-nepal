<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Broadcast campaign (Phase 12).
 *
 * Represents a targeted mass notification with full lifecycle:
 * draft → review → approved → scheduled → sending → sent → partially_delivered / failed / cancelled / expired
 *
 * Tenant-scoped.
 */
class BroadcastCampaign extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_EMERGENCY = 'emergency';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_EMERGENCY = 'emergency';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'status',
        'priority',
        'severity',
        'is_emergency',
        'template_id',
        'segment_id',
        'message_content',
        'targeting_criteria',
        'delivery_config',
        'scheduled_at',
        'started_at',
        'completed_at',
        'expires_at',
        'approval_required',
        'approved_by',
        'approved_at',
        'cancel_reason',
        'total_recipients',
        'delivered_count',
        'failed_count',
        'acknowledged_count',
        'acknowledgement_required',
        'escalation_policy',
        'retry_policy',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'message_content' => 'array',
            'targeting_criteria' => 'array',
            'delivery_config' => 'array',
            'escalation_policy' => 'array',
            'retry_policy' => 'array',
            'is_emergency' => 'boolean',
            'acknowledgement_required' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'total_recipients' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'acknowledged_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(AudienceSegment::class, 'segment_id');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class, 'campaign_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'campaign_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'campaign_id');
    }

    /**
     * Check if the campaign can transition to the given status.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            self::STATUS_DRAFT => [self::STATUS_REVIEW, self::STATUS_CANCELLED],
            self::STATUS_REVIEW => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_CANCELLED],
            self::STATUS_APPROVED => [self::STATUS_SCHEDULED, self::STATUS_SENDING, self::STATUS_CANCELLED],
            self::STATUS_SCHEDULED => [self::STATUS_SENDING, self::STATUS_CANCELLED],
            self::STATUS_SENDING => [self::STATUS_SENT, self::STATUS_PARTIALLY_DELIVERED, self::STATUS_FAILED],
            self::STATUS_SENT => [],
            self::STATUS_PARTIALLY_DELIVERED => [self::STATUS_SENT, self::STATUS_FAILED],
            self::STATUS_FAILED => [],
            self::STATUS_CANCELLED => [],
            self::STATUS_EXPIRED => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? [], true);
    }

    /**
     * Check if this is an emergency broadcast.
     */
    public function isEmergency(): bool
    {
        return $this->is_emergency || $this->priority === self::PRIORITY_EMERGENCY;
    }

    /**
     * Get the channels to deliver through.
     */
    public function getChannels(): array
    {
        return $this->delivery_config['channels'] ?? ['in_app'];
    }

    /**
     * Get the retry configuration.
     */
    public function getRetryConfig(): array
    {
        return array_merge([
            'max_retries' => 3,
            'backoff_seconds' => 60,
            'backoff_multiplier' => 2,
        ], $this->retry_policy);
    }
}
