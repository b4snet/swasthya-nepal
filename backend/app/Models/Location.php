<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generic physical place that is not a clinical bed space (DATABASE.md
 * §3.9): waiting areas, stores, nursing stations, procedure areas. Used by
 * inventory placement and asset tracking in later phases.
 *
 * Tenant-scoped (tenant_id NOT NULL). branch_id is reserved for the branches
 * entity (later phase).
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const TYPE_STORE = 'store';

    public const TYPE_WAITING_AREA = 'waiting_area';

    public const TYPE_NURSING_STATION = 'nursing_station';

    public const TYPE_PROCEDURE_AREA = 'procedure_area';

    public const TYPE_OTHER = 'other';

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
        'type',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
