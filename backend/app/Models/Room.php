<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room within a ward, containing beds (DATABASE.md §3.25). Carries
 * room-type and charge-rate configuration — rates are financial truth for
 * bed charges and every rate change is audited.
 *
 * Tenant-scoped (tenant_id NOT NULL). Money is integer minor units
 * (DATABASE.md §0.4). Soft-deletable, but RESTRICT while beds exist.
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'branch_id',
        'ward_id',
        'name',
        'code',
        'room_type',
        'daily_rate_minor',
        'currency',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_id');
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class, 'room_id');
    }
}
