<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\WastageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medication wastage record (PRODUCT_REQUIREMENTS §6.7, prompt §38).
 *
 * Records medication that is wasted / destroyed: the exact batch, quantity,
 * reason, actor, and optional witness. Every wastage is a movement on the
 * append-only inventory ledger (TYPE_WASTAGE) — stock is CAS-decremented
 * and the ledger row is the traceable evidence. Wasted stock never silently
 * re-enters available inventory.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Wastage extends Model
{
    /** @use HasFactory<WastageFactory> */
    use HasFactory, HasUuid;

    public const REASON_EXPIRED = 'expired';
    public const REASON_DAMAGED = 'damaged';
    public const REASON_CONTAMINATED = 'contaminated';
    public const REASON_RECALLED = 'recalled';
    public const REASON_PARTIAL_USE = 'partial_use';
    public const REASON_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'medication_id',
        'inventory_item_id',
        'stock_batch_id',
        'batch_number',
        'batch_expires_at',
        'quantity_minor',
        'reason_code',
        'reason_note',
        'wasted_by_staff_id',
        'witness_staff_id',
        'wasted_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_expires_at' => 'date',
            'quantity_minor' => 'integer',
            'wasted_at' => 'datetime',
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

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function wastedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'wasted_by_staff_id');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'witness_staff_id');
    }
}
