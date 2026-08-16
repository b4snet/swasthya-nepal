<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parameterized report definition (DATABASE.md §3.51): the QUERY is a
 * whitelisted structure (source_table, filter criteria, aggregate columns,
 * date column, named period) — NEVER raw SQL (MASTER_RULES.md §25.4). Runs
 * execute against the dedicated `reporting` read-replica connection so
 * reporting load never degrades transactional paths (ROADMAP Phase 17).
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ReportTemplate extends Model
{
    /** @use HasFactory<ReportTemplateFactory> */
    use HasFactory, HasUuid;

    public const CATEGORY_OPERATIONAL = 'operational';

    public const CATEGORY_FINANCIAL = 'financial';

    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_EXECUTIVE = 'executive';

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_FACILITY = 'facility';

    public const SCOPE_BRANCH = 'branch';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'category',
        'scope',
        'parameter_schema',
        'query',
        'is_active',
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
            'parameter_schema' => 'array',
            'query' => 'array',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<ReportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'template_id');
    }

    /**
     * @return HasMany<ReportSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class, 'template_id');
    }
}
