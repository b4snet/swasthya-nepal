<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StockCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock count / cycle count record (prompt §58): expected quantity,
 * counted quantity, variance, reason, reviewer, and adjustment.
 *
 * Statuses: counted → reviewed (or rejected).
 * A reviewed stock count with variance triggers an approval-gated
 * inventory adjustment — the count itself never mutates stock directly.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class StockCount extends Model
{
    /** @use HasFactory<StockCountFactory> */
    use HasFactory, HasUuid;

    public const STATUS_COUNTED = 'counted';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'medication_id',
        'inventory_item_id',
        'expected_quantity',
        'counted_quantity',
        'variance',
        'reason',
        'counted_by_staff_id',
        'reviewed_by_staff_id',
        'status',
        'counted_at',
        'reviewed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'variance' => 'integer',
            'counted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'counted_by_staff_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewed_by_staff_id');
    }
}
