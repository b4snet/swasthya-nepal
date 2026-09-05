<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable, append-only snapshot of ONE insurance claim submission
 * (INSURANCE - COVERAGE §20, §24, §62). Written once at submit, never
 * updated: it answers "what exactly was submitted to the payer?" for that
 * attempt. A reopen-and-resubmit of the same claim (denied|rejected → draft
 * → submitted) appends a NEW row (submission_number+1), so the historical
 * submission is never destroyed or overwritten.
 *
 * TENANT tier (no facility_id — §3.35), RLS on + FORCED (the same boundary
 * as the parent claim): a payer submission belonging to Tenant A is never
 * visible to Tenant B.
 */
class InsuranceClaimSubmission extends Model
{
    use HasUuid;

    protected $table = 'claim_submissions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'claim_id', 'submission_number',
        'submitted_snapshot', 'submitted_at', 'submitted_by',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submission_number' => 'integer',
            'submitted_snapshot' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InsuranceClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'claim_id');
    }
}
