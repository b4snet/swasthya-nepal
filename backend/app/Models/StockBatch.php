<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StockBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received lot of a medication (DATABASE.md §3.31, PRODUCT_REQUIREMENTS
 * §6.7). Batches are the unit of expiry-safe dispensing:
 *
 *   available → depleted | quarantined
 *
 * quantity_received is immutable (what came in); quantity_remaining is the
 * live shelf figure, CAS-guarded at every movement. An EXPIRED batch is
 * never selectable for dispensing (the CAS expiry guard refuses it — the
 * acceptance criterion "expired batches never issuable"). Controlled
 * substances with controlled_dispense_requires_dual demand a SECOND
 * pharmacist's verification (Phase 2 dual verification; dispenser ≠
 * verifier).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use HasFactory, HasUuid;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_DEPLETED = 'depleted';

    public const STATUS_QUARANTINED = 'quarantined';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'inventory_item_id',
        'medication_id',
        'batch_number',
        'expiry_date',
        'quantity_received',
        'quantity_remaining',
        'status',
        'controlled_dispense_requires_dual',
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
            'expiry_date' => 'date',
            'quantity_received' => 'integer',
            'quantity_remaining' => 'integer',
            'controlled_dispense_requires_dual' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }
}
