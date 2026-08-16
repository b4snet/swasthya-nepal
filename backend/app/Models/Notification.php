<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification (DATABASE.md §3.37): what was dispatched, via which channel,
 * and its state. Tenant-scoped (tenant_id NOT NULL, no facility_id — §3.37).
 *
 * Phase 3 slice 10 delivers the in-app channel for follow-up reminders:
 * a planned follow-up carries one appointment_reminder notification for its
 * patient (partial unique (tenant_id, follow_up_id) — retries and concurrent
 * triggers cannot duplicate). Email/SMS/push channels, templates, delivery
 * attempts, and preferences remain the documented later-phase surface.
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuid;

    public const TYPE_APPOINTMENT_REMINDER = 'appointment_reminder';

    public const TYPE_RESULT = 'result';

    public const TYPE_BILLING = 'billing';

    public const TYPE_CLINICAL_ALERT = 'clinical_alert';

    public const TYPE_STOCK_ALERT = 'stock_alert';

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PUSH = 'push';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'patient_id',
        'follow_up_id',
        'type',
        'channel',
        'template_id',
        'payload',
        'status',
        'sensitive',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sensitive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<FollowUp, $this>
     */
    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_up_id');
    }
}
