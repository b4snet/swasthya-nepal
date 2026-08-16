<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RosterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member assigned to a shift template on a date (PRODUCT_REQUIREMENTS
 * §6.17, DATABASE.md §3.45). Conflict detection (overlapping shifts, rest
 * rules) is application-enforced in HrService; the partial unique prevents
 * an exact duplicate row per (staff, shift, date).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Roster extends Model
{
    /** @use HasFactory<RosterFactory> */
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'shift_template_id',
        'roster_date',
        'status',
        'notes',
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
            'roster_date' => 'date',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }
}
