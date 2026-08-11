<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\WardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A clinical ward within a facility (DATABASE.md §3.24): general, surgery,
 * pediatric, ICU… Groups rooms and beds.
 *
 * Tenant-scoped (tenant_id NOT NULL). Soft-deletable, but RESTRICT while
 * rooms exist — a ward with clinical capacity is never removed.
 */
class Ward extends Model
{
    /** @use HasFactory<WardFactory> */
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
        'name',
        'code',
        'ward_type',
        'status',
        'settings',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
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
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'ward_id');
    }
}
