<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maintenance work on an asset (PRODUCT_REQUIREMENTS §6.18, DATABASE.md
 * §3.46): open → in_progress → completed | cancelled, with honest downtime
 * tracking (downtime_started_at/ended_at — a machine listed as available
 * while down is a safety and planning hazard) and a certification reference
 * that must be provable. An asset with an OPEN downtime work order is
 * under_repair; completing the order returns it to deployed. Transitions
 * are CAS-guarded (status + lock_version).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'maintenance_schedule_id',
        'work_order_number',
        'status',
        'opened_at',
        'opened_by_staff_id',
        'completed_at',
        'completed_by_staff_id',
        'downtime_started_at',
        'downtime_ended_at',
        'description',
        'certification_ref',
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
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'downtime_started_at' => 'datetime',
            'downtime_ended_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<MaintenanceSchedule, $this>
     */
    public function maintenanceSchedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }
}
