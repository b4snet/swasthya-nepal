<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The append-only stock ledger (DATABASE.md §3.23): every change to on-hand
 * quantity — receipt, adjustment, or dispense — is a row here. Negative
 * deltas are dispenses/adjustments down; zero deltas are rejected by CHECK.
 */
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory, HasUuid;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_DISPENSE = 'dispense';

    public const TYPE_RETURN = 'return';

    // Phase 14 — inter-facility transfers (paired source/destination rows).
    public const TYPE_TRANSFER = 'transfer';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'inventory_item_id',
        'movement_type',
        'quantity_delta',
        'reason',
        'prescription_line_id',
        // Phase 3 slice 17 — the batch every movement touched (batch-level
        // ledger traceability for expiry-safe dispensing).
        'stock_batch_id',
        // Phase 3 — the standalone dispensing record a movement belongs to
        // (dispensing without a prescription; no prescription_line_id).
        'dispensing_id',
        'occurred_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<PrescriptionLine, $this>
     */
    public function prescriptionLine(): BelongsTo
    {
        return $this->belongsTo(PrescriptionLine::class, 'prescription_line_id');
    }
}
