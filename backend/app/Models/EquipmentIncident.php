<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equipment safety incident report (prompt §88). Records malfunctions,
 * safety events, near-misses with severity and resolution. Never hidden
 * inside maintenance notes. Tenant+facility scoped, RLS on + FORCED.
 */
class EquipmentIncident extends Model
{
    /** @use HasFactory<EquipmentIncidentFactory> */
    use HasFactory, HasUuid;

    public const TYPE_MALFUNCTION = 'malfunction';
    public const TYPE_SAFETY = 'safety';
    public const TYPE_NEAR_MISS = 'near_miss';
    public const TYPE_OTHER = 'other';

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'incident_type',
        'description',
        'reported_by_staff_id',
        'occurred_at',
        'severity',
        'status',
        'resolution',
        'resolved_by_staff_id',
        'resolved_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
