<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MetricSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OBSERVED aggregate (DATABASE.md §3.51): the value of a KPI definition
 * computed from the real source table for a period at generation time, plus
 * the row_count observed — never fabricated (MASTER_RULES.md P.15). One
 * snapshot per (KPI, period, dimension) — the DB partial unique makes a
 * concurrent double-refresh impossible; the refresh is idempotent.
 * Tenant+facility scoped, RLS on + FORCED.
 */
class MetricSnapshot extends Model
{
    /** @use HasFactory<MetricSnapshotFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'kpi_definition_id',
        'period_start',
        'period_end',
        'value',
        'dimension',
        'row_count',
        'generated_at',
        'generated_by_staff_id',
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
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'value' => 'float',
            'dimension' => 'array',
            'row_count' => 'integer',
            'generated_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KpiDefinition, $this>
     */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }
}
