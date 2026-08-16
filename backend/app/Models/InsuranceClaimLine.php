<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InsuranceClaimLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claim line per invoice line (DATABASE.md §3.35): `billed_minor` is
 * the invoice line's frozen amount + tax — the claim maps exactly to
 * invoice truth, never fabricated. `approved_minor` and the per-line
 * status (pending → approved | denied) are populated by payer settlement
 * recording. The unique (tenant_id, invoice_line_id) index means an
 * invoice line can be claimed at most once. TENANT tier, RLS on + FORCED.
 */
class InsuranceClaimLine extends Model
{
    /** @use HasFactory<InsuranceClaimLineFactory> */
    use HasFactory, HasUuid;

    /**
     * The migration names the table `claim_lines` (DATABASE.md §3.35).
     */
    protected $table = 'claim_lines';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'claim_id',
        'invoice_line_id',
        'billed_minor',
        'approved_minor',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billed_minor' => 'integer',
            'approved_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InsuranceClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'claim_id');
    }

    /**
     * @return BelongsTo<InvoiceLine, $this>
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }
}
