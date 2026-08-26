<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned benefit rule for payers (SSF, HIB, private insurance).
 *
 * Each rule defines coverage for a service category under a payer scheme.
 * Historical claims use the rule active at claim time.
 *
 * CRITICAL: All statutory values are CONFIGURABLE, not hard-coded.
 * Benefit changes create new rules with effective_from dates.
 *
 * Example usage:
 * - SSF: 'SSF_OPD_MEDICINE' with 100% coverage up to NPR 5,000
 * - HIB: 'HIB_IPD_SURGERY' with 75% coverage up to NPR 100,000
 * - Private: 'PRIVATE_MATERNITY' with 80% coverage up to NPR 200,000
 */
class BenefitRule extends Model
{
    /** @use HasFactory<BenefitRuleFactory> */
    use HasFactory, HasUuid;

    public const COVERAGE_FULL = 'full';

    public const COVERAGE_CO_PAY = 'co_pay';

    public const COVERAGE_DEDUCTIBLE = 'deductible';

    public const COVERAGE_CAPPED = 'capped';

    public const COVERAGE_EXCLUDED = 'excluded';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'payer_id',
        'code',
        'name',
        'scheme_version',
        'service_category',
        'coverage_type',
        'coverage_percent_bps',
        'limit_minor',
        'copay_minor',
        'copay_percent_bps',
        'deductible_minor',
        'eligible_opd',
        'eligible_ipd',
        'eligible_maternity',
        'eligible_dependents',
        'max_dependents',
        'effective_from',
        'effective_to',
        'source_authority',
        'source_document',
        'source_effective_date',
        'source_url',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coverage_percent_bps' => 'integer',
            'limit_minor' => 'integer',
            'copay_minor' => 'integer',
            'copay_percent_bps' => 'integer',
            'deductible_minor' => 'integer',
            'eligible_opd' => 'boolean',
            'eligible_ipd' => 'boolean',
            'eligible_maternity' => 'boolean',
            'eligible_dependents' => 'boolean',
            'max_dependents' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'source_effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Payer, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class, 'payer_id');
    }

    /**
     * Calculate the payer's covered amount for a given service charge.
     * Returns the amount the payer will cover (before patient responsibility).
     */
    public function calculateCoverage(int $chargeAmountMinor): int
    {
        return match ($this->coverage_type) {
            self::COVERAGE_FULL => $chargeAmountMinor,
            self::COVERAGE_CO_PAY => max(0, $chargeAmountMinor - ($this->copay_minor ?? 0)),
            self::COVERAGE_CAPPED => min($chargeAmountMinor, $this->limit_minor ?? PHP_INT_MAX),
            self::COVERAGE_DEDUCTIBLE => max(0, $chargeAmountMinor - ($this->deductible_minor ?? 0)),
            self::COVERAGE_EXCLUDED => 0,
            default => 0,
        };
    }

    /**
     * Calculate the patient's responsibility for a given service charge.
     */
    public function calculatePatientResponsibility(int $chargeAmountMinor): int
    {
        return $chargeAmountMinor - $this->calculateCoverage($chargeAmountMinor);
    }

    /**
     * Check if this rule is currently effective.
     */
    public function isCurrentlyEffective(): bool
    {
        $now = now()->toDateString();

        return $this->effective_from <= $now
            && ($this->effective_to === null || $this->effective_to >= $now);
    }

    /**
     * Scope: rules active on a given date.
     */
    public function scopeActiveOn($query, string $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }

    /**
     * Scope: currently active rules.
     */
    public function scopeCurrentlyActive($query)
    {
        return $this->scopeActiveOn($query, now()->toDateString());
    }
}
