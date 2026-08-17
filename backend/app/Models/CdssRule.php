<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CdssRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned, clinically reviewed entry in the CDSS knowledge base
 * (DATABASE.md §3.57, AI_RULES.md §6–7). Never edited in place —
 * supersession creates a new version (kpi_definitions discipline).
 */
class CdssRule extends Model
{
    /** @use HasFactory<CdssRuleFactory> */
    use HasFactory, HasUuid;

    public const TYPE_INTERACTION = 'interaction';

    public const TYPE_ALLERGEN = 'allergen';

    public const TYPE_DOSE = 'dose';

    public const TYPE_PATHWAY = 'pathway';

    public const SEVERITY_CONTRAINDICATED = 'contraindicated';

    public const SEVERITY_MAJOR = 'major';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_MINOR = 'minor';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'rule_type',
        'code',
        'name',
        'severity',
        'spec',
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
            'spec' => 'array',
            'version' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
