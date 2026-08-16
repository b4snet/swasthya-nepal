<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\KpiDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A VERSIONED metric definition (DATABASE.md §3.51, PRODUCT REQUIREMENTS
 * §6.19): "a changing KPI is not a KPI." Only one ACTIVE version of a code
 * exists per facility (DB partial unique); superseding a definition marks the
 * active row superseded and creates version+1. Aggregates are computed from
 * the real source tables at snapshot time — analytics reflect observed data
 * only (MASTER_RULES.md P.15). Tenant+facility scoped, RLS on + FORCED.
 */
class KpiDefinition extends Model
{
    /** @use HasFactory<KpiDefinitionFactory> */
    use HasFactory, HasUuid;

    public const DOMAIN_OPERATIONAL = 'operational';

    public const DOMAIN_FINANCIAL = 'financial';

    public const DOMAIN_CLINICAL = 'clinical';

    public const DOMAIN_EXECUTIVE = 'executive';

    public const AGGREGATION_COUNT = 'count';

    public const AGGREGATION_SUM = 'sum';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'domain',
        'source_table',
        'date_column',
        'filter',
        'aggregation',
        'sum_column',
        'unit',
        'version',
        'status',
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
            'filter' => 'array',
            'version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<MetricSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class, 'kpi_definition_id');
    }
}
