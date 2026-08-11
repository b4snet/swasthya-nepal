<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\BedStatus;
use Database\Factories\BedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The allocatable unit of inpatient capacity (DATABASE.md §3.26): live state
 * plus assignment history. Never soft-deleted — `out_of_service` is a status.
 *
 * Status is a state machine (DATABASE.md §0.5): transitions are validated by
 * BedStatus and every change is audited. `lock_version` is the
 * optimistic-locking counter (DATABASE.md §0.7). `current_admission_id`
 * receives its tenant-safe FK in the IPD phase.
 */
class Bed extends Model
{
    /** @use HasFactory<BedFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'branch_id',
        'room_id',
        'bed_code',
        'status',
        'current_admission_id',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === BedStatus::AVAILABLE;
    }
}
