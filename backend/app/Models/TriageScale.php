<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TriageScaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable acuity scale level (DATABASE.md §3.17a, PRODUCT_REQUIREMENTS
 * §6.6): the tenant's triage catalog (e.g. the 5-level emergency scale).
 * `level` is the priority ordinal (1 = most urgent); `reassessment_minutes`
 * is the interval driving reassessment scheduling. Catalog rows are
 * tenant+facility scoped; a scale may be soft-inactivated but never deleted
 * while triage history references it (RESTRICT).
 */
class TriageScale extends Model
{
    /** @use HasFactory<TriageScaleFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'level',
        'color',
        'reassessment_minutes',
        'is_default',
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
            'level' => 'integer',
            'reassessment_minutes' => 'integer',
            'is_default' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return HasMany<TriageAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TriageAssignment::class, 'triage_scale_id');
    }
}
