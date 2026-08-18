<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InventoryTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inter-facility stock transfer (PRODUCT_REQUIREMENTS §6.15, DATABASE.md
 * §3.31): one row per transfer with the source and destination facility,
 * executed ATOMICALLY — the source item is CAS-decremented and the
 * destination item CAS-incremented in one transaction with a paired
 * `transfer` ledger movement on each side (both rows share this
 * inventory_transfer_id). Stock never goes in-transit; the movement ledger
 * remains the only stock truth.
 */
class InventoryTransfer extends Model
{
    /** @use HasFactory<InventoryTransferFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'destination_facility_id',
        'inventory_item_id',
        'medication_id',
        'quantity',
        'reason',
        'dispatched_by',
        'dispatched_at',
        'received_by',
        'received_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
