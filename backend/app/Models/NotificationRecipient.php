<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Campaign-to-recipient linkage (Phase 12).
 *
 * Tracks each recipient's overall delivery status for a campaign.
 */
class NotificationRecipient extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'user_id',
        'delivery_status',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'campaign_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }
}
