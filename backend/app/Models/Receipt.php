<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A payment receipt (Phase 85 — complete revenue cycle): issued when a
 * payment is captured against an invoice. The receipt is an immutable
 * document of the financial transaction, generated with hospital branding
 * at issuance time.
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_EMAILED = 'emailed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'payment_id',
        'invoice_id',
        'patient_id',
        'receipt_number',
        'status',
        'amount_minor',
        'currency',
        'method',
        'payment_method_label',
        'items',
        'branding_snapshot',
        'printed',
        'printed_at',
        'emailed',
        'emailed_at',
        'issued_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'items' => 'array',
            'branding_snapshot' => 'array',
            'printed' => 'boolean',
            'printed_at' => 'datetime',
            'emailed' => 'boolean',
            'emailed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
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
            'receiptNumber' => $this->receipt_number,
            'paymentId' => $this->payment_id,
            'invoiceId' => $this->invoice_id,
            'patientId' => $this->patient_id,
            'status' => $this->status,
            'amountMinor' => $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->method,
            'paymentMethodLabel' => $this->payment_method_label,
            'items' => $this->items,
            'printed' => $this->printed,
            'printedAt' => $this->printed_at?->toIso8601String(),
            'emailed' => $this->emailed,
            'emailedAt' => $this->emailed_at?->toIso8601String(),
            'issuedBy' => $this->issued_by,
            'issuedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
