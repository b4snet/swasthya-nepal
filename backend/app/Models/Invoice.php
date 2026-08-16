<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice (DATABASE.md §3.33): the bill presented to the patient, built
 * from posted charges. Lines are frozen snapshots in invoice_lines.
 *
 * Tenant-scoped. Status lifecycle: draft → issued → partially_paid → paid.
 * lock_version guards concurrent payment allocation.
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'invoice_number',
        'status',
        'total_minor',
        'total_tax_minor',
        'paid_minor',
        'issued_at',
        'void_reason',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'total_tax_minor' => 'integer',
            'paid_minor' => 'integer',
            'issued_at' => 'datetime',
            'lock_version' => 'integer',
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
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id');
    }

    /**
     * Phase 3 slice 18 — insurance claims built from this invoice's truth
     * (DATABASE.md §3.35).
     *
     * @return HasMany<InsuranceClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class, 'invoice_id');
    }
}
