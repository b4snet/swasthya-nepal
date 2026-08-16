<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DashboardKpiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A KPI placed on a dashboard at an ordered position (DATABASE.md §3.51).
 * One active slot per position (DB partial unique). Tenant+facility scoped,
 * RLS on + FORCED.
 */
class DashboardKpi extends Model
{
    /** @use HasFactory<DashboardKpiFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'dashboard_id',
        'kpi_definition_id',
        'position',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
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
