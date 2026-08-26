<?php

namespace App\Models;

use App\Exceptions\ApiException;
use App\Models\Concerns\HasUuid;
use App\Services\PeriodGuard;
use App\Services\TaxResolver;
use Database\Factories\ChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A charge (DATABASE.md §3.33): what was charged and from which source.
 * Amounts are integer minor units, never floats. Posted charges are
 * immutable — corrections are reversing entries, never UPDATEs; void is a
 * status with reason and approver.
 */
class Charge extends Model
{
    /** @use HasFactory<ChargeFactory> */
    use HasFactory, HasUuid;

    public const SOURCE_ENCOUNTER = 'encounter';

    public const SOURCE_PRESCRIPTION = 'prescription';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_DISPENSING = 'dispensing';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'source_type',
        'encounter_id',
        'prescription_id',
        'prescription_line_id',
        'dispensing_id',
        'description',
        'amount_minor',
        'currency',
        'tax_rate_bps',
        'tax_rule_id',
        'status',
        'voided_by',
        'void_reason',
        'charged_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'charged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TaxRule, $this>
     */
    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class, 'tax_rule_id');
    }

    /**
     * Resolve the tax fields for a new charge based on the effective-dated
     * tax rules. Returns an array with 'tax_rule_id' and 'tax_rate_bps'
     * that should be merged into the charge creation data.
     *
     * Also validates that the fiscal period is open for this facility/date.
     *
     * @return array{tax_rule_id: string|null, tax_rate_bps: int}
     *
     * @throws ApiException if the fiscal period is closed or locked
     */
    public static function resolveTaxFields(string $facilityId, ?string $serviceCategory = null): array
    {
        // Validate the fiscal period is open before posting a charge.
        app(PeriodGuard::class)->assertOpen($facilityId);

        $resolver = app(TaxResolver::class);
        $rule = $resolver->resolve($facilityId, $serviceCategory);

        return [
            'tax_rule_id' => $rule?->getKey(),
            'tax_rate_bps' => $rule?->rate_value_bps ?? 0,
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    /**
     * @return HasMany<RefundRequest, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'charge_id');
    }
}
