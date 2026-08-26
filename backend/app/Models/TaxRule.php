<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Effective-dated tax rule (Nepal Financial Architecture).
 *
 * Every charge references the tax rule active at posting time.
 * Historical invoices remain reproducible using the rule version that applied.
 *
 * CRITICAL: All statutory values are CONFIGURABLE, not hard-coded.
 * Rate changes create new rules with effective_from dates, never UPDATE existing ones.
 *
 * Tax types:
 * - vat: Nepal standard VAT (currently 13%)
 * - health_service_tax: Health service tax on private healthcare (currently 5%)
 * - health_equity_fee: Health equity fee on private healthcare (currently 3%)
 * - excise: Excise duty
 * - other: Other configured taxes
 */
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory, HasUuid;

    public const TYPE_VAT = 'vat';

    public const TYPE_HEALTH_SERVICE_TAX = 'health_service_tax';

    public const TYPE_HEALTH_EQUITY_FEE = 'health_equity_fee';

    public const TYPE_EXCISE = 'excise';

    public const TYPE_OTHER = 'other';

    public const METHOD_PERCENTAGE = 'percentage';

    public const METHOD_FIXED = 'fixed_amount';

    public const METHOD_PER_UNIT = 'per_unit';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'tax_type',
        'description',
        'rate_method',
        'rate_value_bps',
        'currency',
        'fixed_amount_minor',
        'jurisdiction',
        'service_category',
        'applies_to_opd',
        'applies_to_ipd',
        'applies_to_pharmacy',
        'applies_to_lab',
        'applies_to_radiology',
        'effective_from',
        'effective_to',
        'source_authority',
        'source_document',
        'source_effective_date',
        'source_url',
        'source_version',
        'status',
        'is_default',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_value_bps' => 'integer',
            'fixed_amount_minor' => 'integer',
            'applies_to_opd' => 'boolean',
            'applies_to_ipd' => 'boolean',
            'applies_to_pharmacy' => 'boolean',
            'applies_to_lab' => 'boolean',
            'applies_to_radiology' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'source_effective_date' => 'date',
            'is_default' => 'boolean',
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
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * Calculate tax amount for a given base amount.
     * Uses integer arithmetic (basis points) — never floating point.
     */
    public function calculateTax(int $baseAmountMinor): int
    {
        return match ($this->rate_method) {
            self::METHOD_PERCENTAGE => (int) round($baseAmountMinor * $this->rate_value_bps / 10000),
            self::METHOD_FIXED => $this->fixed_amount_minor ?? 0,
            self::METHOD_PER_UNIT => 0, // Requires quantity parameter
            default => 0,
        };
    }

    /**
     * Check if this rule applies to a given service category.
     */
    public function appliesTo(string $category): bool
    {
        if ($this->service_category !== null && $this->service_category !== $category) {
            return false;
        }

        return match ($category) {
            'opd' => $this->applies_to_opd,
            'ipd' => $this->applies_to_ipd,
            'pharmacy' => $this->applies_to_pharmacy,
            'lab' => $this->applies_to_lab,
            'radiology' => $this->applies_to_radiology,
            default => true,
        };
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
