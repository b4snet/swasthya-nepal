<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RefundRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund/adjustment request against a posted charge (DATABASE.md §3.33,
 * PRODUCT_REQUIREMENTS §6.13).
 *
 * The approved request IS the immutable reversing entry: the original charge
 * is never mutated (posted financial rows are immutable; corrections are
 * reversing entries). The refundable amount for a charge is
 * `amount_minor − Σ(approved)` — both creation and approval re-check it, and
 * approval locks the charge row so concurrent approvals can never over-refund.
 */
class RefundRequest extends Model
{
    /** @use HasFactory<RefundRequestFactory> */
    use HasFactory, HasUuid;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const REASON_OVERCHARGE = 'overcharge';

    public const REASON_DUPLICATE_CHARGE = 'duplicate_charge';

    public const REASON_SERVICE_NOT_RENDERED = 'service_not_rendered';

    public const REASON_PATIENT_REQUEST = 'patient_request';

    public const REASON_ADJUSTMENT = 'adjustment';

    public const REASON_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'charge_id',
        'amount_minor',
        'reason_code',
        'reason_note',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejection_reason',
        'rejected_at',
        'completed_by',
        'completed_at',
        'lock_version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
