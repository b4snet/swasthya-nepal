<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's leave request (PRODUCT_REQUIREMENTS §6.17, DATABASE.md
 * §3.45): request → approval → balance. An approval is CAS-guarded
 * (status + lock_version) so a double approval can never happen; the
 * balance check (approved days vs entitlement) runs inside the same
 * transaction, so an over-entitlement approval is refused.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'leave_type_id',
        'starts_on',
        'ends_on',
        'days_requested',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_notes',
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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days_requested' => 'integer',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
