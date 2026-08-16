<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PharmacyReturnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pharmacy return/reversal of a dispensed prescription line
 * (DATABASE.md §3.30, PRODUCT_REQUIREMENTS §6.7).
 *
 * The row IS the immutable reversal record: the line flips dispensed →
 * reversed, stock is restored through the append-only ledger ('return'
 * movement), and the linked posted charge stays immutable — the refund path
 * opens through a refund_requests row (requested → approved by billing),
 * never by mutating the charge. One return per line (unique tenant +
 * prescription_line_id): double restoration is impossible.
 */
class PharmacyReturn extends Model
{
    /** @use HasFactory<PharmacyReturnFactory> */
    use HasFactory, HasUuid;

    public const REASON_PATIENT_RETURN = 'patient_return';

    public const REASON_WRONG_MEDICATION = 'wrong_medication';

    public const REASON_ADVERSE_REACTION = 'adverse_reaction';

    public const REASON_DISPENSING_ERROR = 'dispensing_error';

    public const REASON_DUPLICATE_DISPENSE = 'duplicate_dispense';

    public const REASON_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'prescription_line_id',
        'prescription_id',
        'charge_id',
        'quantity_minor',
        'reason_code',
        'reason_note',
        'returned_by',
        'returned_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_minor' => 'integer',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PrescriptionLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PrescriptionLine::class, 'prescription_line_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }
}
