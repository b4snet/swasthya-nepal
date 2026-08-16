<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift definition (PRODUCT_REQUIREMENTS §6.17, DATABASE.md §3.45):
 * day/night/rotating with start/end times and working minutes. Rosters
 * assign staff to a shift template on a date.
 *
 * Soft-deletable (active-scope partial unique on code).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory, HasUuid;

    public const TYPE_DAY = 'day';

    public const TYPE_NIGHT = 'night';

    public const TYPE_ROTATING = 'rotating';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'code',
        'name',
        'shift_type',
        'starts_at',
        'ends_at',
        'working_minutes',
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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'working_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
