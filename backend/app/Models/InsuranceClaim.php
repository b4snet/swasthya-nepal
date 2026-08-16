<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InsuranceClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An insurance claim built from invoice truth (DATABASE.md §3.35,
 * PRODUCT_REQUIREMENTS §6.14): claim lines map EXACTLY to invoice lines —
 * no fabricated claim lines. Lifecycle:
 *
 *   draft → submitted → pending → partial | paid
 *                              ↘ denied  (a denied claim may be re-created
 *                                         for resubmission; the partial
 *                                         unique excludes denied claims)
 *
 * Every transition is CAS-guarded on (status, lock_version). Claims data
 * maps exactly to invoice truth; benefit enforcement happens at charge
 * time (insurance_policies.benefits), never at claim time. TENANT tier
 * (no facility_id — §3.35), RLS on + FORCED.
 */
class InsuranceClaim extends Model
{
    /** @use HasFactory<InsuranceClaimFactory> */
    use HasFactory, HasUuid;

    /**
     * The migration names the table `claims` (DATABASE.md §3.35), not the
     * Laravel-conventional `insurance_claims`.
     */
    protected $table = 'claims';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_DENIED = 'denied';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'claim_number',
        'policy_id',
        'invoice_id',
        'payer_id',
        'status',
        'submitted_at',
        'denial_reason',
        'settlement_minor',
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
            'lock_version' => 'integer',
            'settlement_minor' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InsurancePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * @return BelongsTo<Payer, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class, 'payer_id');
    }

    /**
     * @return HasMany<InsuranceClaimLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InsuranceClaimLine::class, 'claim_id');
    }

    /**
     * The claim's total billed amount — the sum of its line bills (invoice
     * truth), never fabricated.
     */
    public function billedTotalMinor(): int
    {
        return (int) $this->lines()->sum('billed_minor');
    }
}
