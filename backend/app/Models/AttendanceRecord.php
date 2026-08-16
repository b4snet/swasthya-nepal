<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member's attendance for one day (PRODUCT_REQUIREMENTS §6.17,
 * DATABASE.md §3.45): clock-in/out (or schedule-based). A CORRECTION is a
 * separate approval flow — the correction is never silently edited into the
 * record: it is requested (reason), then approved/rejected by HR, and only
 * an approved correction mutates the row (CAS on lock_version).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory, HasUuid;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_LEAVE = 'leave';

    public const SOURCE_CLOCK = 'clock';

    public const SOURCE_SCHEDULE = 'schedule';

    public const SOURCE_MANUAL = 'manual';

    public const CORRECTION_NONE = 'none';

    public const CORRECTION_PENDING = 'pending';

    public const CORRECTION_APPROVED = 'approved';

    public const CORRECTION_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'attendance_date',
        'clock_in_at',
        'clock_out_at',
        'status',
        'source',
        'correction_status',
        'correction_reason',
        'correction_proposed_clock_in_at',
        'correction_proposed_clock_out_at',
        'correction_requested_by',
        'correction_approved_by',
        'correction_approved_at',
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
            'attendance_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'correction_proposed_clock_in_at' => 'datetime',
            'correction_proposed_clock_out_at' => 'datetime',
            'correction_approved_at' => 'datetime',
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
}
