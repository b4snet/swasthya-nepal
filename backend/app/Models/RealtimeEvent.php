<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RealtimeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A realtime operations event (Phase 86): lightweight operational
 * events dispatched to the hospital notification center. Events are
 * tenant+facility scoped and routed by role/permission.
 *
 * Categories: appointment, clinical, pharmacy, billing, admin, system.
 * Severity: info, warning, urgent, critical.
 * Channels: operations, clinical, finance, admin, emergency.
 */
class RealtimeEvent extends Model
{
    /** @use HasFactory<RealtimeEventFactory> */
    use HasFactory, HasUuid;

    // ── Event types ──
    public const TYPE_APPOINTMENT_CHECK_IN = 'appointment.check_in';

    public const TYPE_APPOINTMENT_BOOKED = 'appointment.booked';

    public const TYPE_APPOINTMENT_CANCELLED = 'appointment.cancelled';

    public const TYPE_QUEUE_UPDATE = 'queue.update';

    public const TYPE_QUEUE_PATIENT_CALLED = 'queue.patient_called';

    public const TYPE_LAB_CRITICAL_VALUE = 'lab.critical_value';

    public const TYPE_LAB_RESULT_READY = 'lab.result_ready';

    public const TYPE_RADIOLOGY_RESULT = 'radiology.result';

    public const TYPE_RADIOLOGY_CRITICAL = 'radiology.critical_finding';

    public const TYPE_ADMISSION_CREATED = 'admission.created';

    public const TYPE_ADMISSION_BED_CHANGE = 'admission.bed_change';

    public const TYPE_DISCHARGE_COMPLETED = 'discharge.completed';

    public const TYPE_PHARMACY_DISPENSED = 'pharmacy.dispensed';

    public const TYPE_PHARMACY_LOW_STOCK = 'pharmacy.low_stock';

    public const TYPE_PHARMACY_CRITICAL_STOCK = 'pharmacy.critical_stock';

    public const TYPE_BILLING_INVOICE_ISSUED = 'billing.invoice_issued';

    public const TYPE_BILLING_PAYMENT_RECEIVED = 'billing.payment_received';

    public const TYPE_BILLING_REFUND_APPROVED = 'billing.refund_approved';

    public const TYPE_APPROVAL_PENDING = 'approval.pending';

    public const TYPE_APPROVAL_COMPLETED = 'approval.completed';

    public const TYPE_ICU_CRITICAL_ALERT = 'icu.critical_alert';

    public const TYPE_ICU_VITALS_ABNORMAL = 'icu.vitals_abnormal';

    public const TYPE_BLOOD_BANK_REQUEST = 'blood_bank.request';

    public const TYPE_SYSTEM_MAINTENANCE = 'system.maintenance';

    public const TYPE_SYSTEM_ALERT = 'system.alert';

    // ── Categories ──
    public const CAT_APPOINTMENT = 'appointment';

    public const CAT_CLINICAL = 'clinical';

    public const CAT_PHARMACY = 'pharmacy';

    public const CAT_BILLING = 'billing';

    public const CAT_ADMIN = 'admin';

    public const CAT_SYSTEM = 'system';

    // ── Severities ──
    public const SEV_INFO = 'info';

    public const SEV_WARNING = 'warning';

    public const SEV_URGENT = 'urgent';

    public const SEV_CRITICAL = 'critical';

    // ── Channels ──
    public const CH_OPERATIONS = 'operations';

    public const CH_CLINICAL = 'clinical';

    public const CH_FINANCE = 'finance';

    public const CH_ADMIN = 'admin';

    public const CH_EMERGENCY = 'emergency';

    // ── Statuses ──
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'event_type',
        'category',
        'severity',
        'priority',
        'title',
        'message',
        'metadata',
        'action_url',
        'channel',
        'target_roles',
        'target_users',
        'broadcast',
        'source_type',
        'source_id',
        'delivered_count',
        'acknowledged_count',
        'acknowledgement_required_count',
        'acknowledgement_required',
        'status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'target_roles' => 'array',
            'target_users' => 'array',
            'broadcast' => 'boolean',
            'delivered_count' => 'integer',
            'acknowledged_count' => 'integer',
            'acknowledgement_required_count' => 'integer',
            'acknowledgement_required' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RealtimeEventReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(RealtimeEventReceipt::class, 'event_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'eventType' => $this->event_type,
            'category' => $this->category,
            'severity' => $this->severity,
            'priority' => $this->priority,
            'title' => $this->title,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'actionUrl' => $this->action_url,
            'channel' => $this->channel,
            'sourceType' => $this->source_type,
            'sourceId' => $this->source_id,
            'deliveredCount' => $this->delivered_count,
            'acknowledgedCount' => $this->acknowledged_count,
            'acknowledgementRequired' => $this->acknowledgement_required,
            'status' => $this->status,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
