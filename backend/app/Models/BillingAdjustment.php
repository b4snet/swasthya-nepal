<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\BillingAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A billing adjustment (Phase 85 — complete revenue cycle): credit or
 * debit entry applied to an invoice after issuance. Corrections to
 * posted financial records are adjusting entries, never edits to
 * original data.
 *
 * Lifecycle: pending → approved → applied (or rejected).
 * Segregation: requester ≠ approver ≠ applier.
 */
class BillingAdjustment extends Model
{
    /** @use HasFactory<BillingAdjustmentFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const REASON_OVERCHARGE = 'overcharge';

    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_CLINICAL_OVERRIDE = 'clinical_override';

    public const REASON_DISCOUNT = 'discount';

    public const REASON_CORRECTION = 'correction';

    public const REASON_WRITE_OFF = 'write_off';

    public const REASON_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'invoice_id',
        'patient_id',
        'adjustment_number',
        'type',
        'amount_minor',
        'currency',
        'reason_code',
        'reason_note',
        'status',
        'adjustment_of_charge_id',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'applied_by',
        'applied_at',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'adjustmentNumber' => $this->adjustment_number,
            'invoiceId' => $this->invoice_id,
            'patientId' => $this->patient_id,
            'type' => $this->type,
            'amountMinor' => $this->amount_minor,
            'currency' => $this->currency,
            'reasonCode' => $this->reason_code,
            'reasonNote' => $this->reason_note,
            'status' => $this->status,
            'requestedBy' => $this->requested_by,
            'requestedAt' => $this->requested_at?->toIso8601String(),
            'approvedBy' => $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'appliedBy' => $this->applied_by,
            'appliedAt' => $this->applied_at?->toIso8601String(),
            'lockVersion' => $this->lock_version,
        ];
    }
}
