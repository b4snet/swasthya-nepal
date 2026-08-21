<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RealtimeEventReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user delivery receipt for a realtime event (Phase 86).
 * Tracks delivery, read, acknowledgement, and dismissal per user.
 */
class RealtimeEventReceipt extends Model
{
    /** @use HasFactory<RealtimeEventReceiptFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_DISMISSED = 'dismissed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'event_id',
        'user_id',
        'status',
        'delivered_at',
        'read_at',
        'acknowledged_at',
        'acknowledgement_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RealtimeEvent::class, 'event_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'eventId' => $this->event_id,
            'userId' => $this->user_id,
            'status' => $this->status,
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'readAt' => $this->read_at?->toIso8601String(),
            'acknowledgedAt' => $this->acknowledged_at?->toIso8601String(),
            'acknowledgementNote' => $this->acknowledgement_note,
        ];
    }
}
