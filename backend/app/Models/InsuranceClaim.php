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

    public const STATUS_REJECTED = 'rejected';

    /**
     * Claim statuses that have NOT been accepted for processing by the payer
     * (submission not accepted → rejected) vs processed-but-not-payable
     * (→ denied). §25 keeps rejection and denial distinct; this list is the
     * set of statuses that may be reopened for correction and resubmission.
     *
     * @return list<string>
     */
    public static function reopenableStatuses(): array
    {
        return [self::STATUS_DENIED, self::STATUS_REJECTED];
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'claim_number',
        'policy_id',
        'invoice_id',
        'payer_id',
        'benefit_rule_id',
        'claim_type',
        'external_claim_number',
        'status',
        'submitted_at',
        'denial_reason',
        'rejection_reason',
        'settlement_minor',
        'patient_responsibility_minor',
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
     * The claim's append-only submission snapshots, oldest first. Each row is
     * an immutable record of exactly what was submitted on that attempt
     * (INSURANCE - COVERAGE §20); resubmission appends rather than overwrites
     * (§24, §62).
     *
     * @return HasMany<InsuranceClaimSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(InsuranceClaimSubmission::class, 'claim_id')
            ->orderBy('submission_number');
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
