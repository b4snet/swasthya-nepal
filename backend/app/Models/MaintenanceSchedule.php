<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MaintenanceScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scheduled maintenance for an asset (PRODUCT_REQUIREMENTS §6.18, DATABASE.md
 * §3.46): preventive/contract/certification with frequency and next due date.
 * Work orders are opened against a schedule (or ad hoc); completing a work
 * order advances the schedule's last_completed_at and next_due_date.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class MaintenanceSchedule extends Model
{
    /** @use HasFactory<MaintenanceScheduleFactory> */
    use HasFactory, HasUuid;

    public const TYPE_PREVENTIVE = 'preventive';

    public const TYPE_CONTRACT = 'contract';

    public const TYPE_CERTIFICATION = 'certification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'schedule_type',
        'frequency_days',
        'next_due_date',
        'last_completed_at',
        'contract_ref',
        'status',
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
            'frequency_days' => 'integer',
            'next_due_date' => 'date',
            'last_completed_at' => 'date',
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
}
