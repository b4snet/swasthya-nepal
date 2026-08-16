<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\WarningScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A computed early-warning score (NEWS-style, configurable) for an ICU
 * observation set (DATABASE.md §3.49, PRODUCT_REQUIREMENTS §6.11).
 * Computed, never hand-entered — the score derives from the observation
 * values in the service. Tenant+facility scoped, RLS on + FORCED.
 */
class WarningScore extends Model
{
    /** @use HasFactory<WarningScoreFactory> */
    use HasFactory, HasUuid;

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_EMERGENCY = 'emergency';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'icu_admission_id',
        'observation_set_id',
        'score_total',
        'severity',
        'breakdown',
        'scale_version',
        'computed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_total' => 'integer',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
