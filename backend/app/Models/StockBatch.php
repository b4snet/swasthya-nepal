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
     * Expiry visibility (ROADMAP Phase 12 acceptance: "expiring/expired
     * batches visible and never issuable"). `expiring_soon` is the
     * presentation window — the batch's own expiry_date is the only hard
     * gate; this label never blocks or permits dispensing (the CAS expiry
     * guard uses the actual date).
     */
    public const EXPIRY_STATUS_VALID = 'valid';

    public const EXPIRY_STATUS_EXPIRING_SOON = 'expiring_soon';

    public const EXPIRY_STATUS_EXPIRED = 'expired';

    /**
     * The visibility window before expiry that flags a batch as
     * `expiring_soon` (90 days, a documented presentation constant — not a
     * dispensing rule).
     */
    public const EXPIRING_SOON_DAYS = 90;

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
     * Date-derived expiry status: `expired` (expiry_date before today),
     * `expiring_soon` (today .. today + EXPIRING_SOON_DAYS), else `valid`.
     * Pure presentation facts — never a dispensing gate.
     */
    public function expiryStatus(): string
    {
        if ($this->expiry_date === null) {
            return self::EXPIRY_STATUS_VALID;
        }

        $today = now()->startOfDay();

        if ($this->expiry_date->lt($today)) {
            return self::EXPIRY_STATUS_EXPIRED;
        }

        if ($this->expiry_date->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS))) {
            return self::EXPIRY_STATUS_EXPIRING_SOON;
        }

        return self::EXPIRY_STATUS_VALID;
    }

    /**
     * Whole days until expiry; negative when the batch is already expired.
     */
    public function daysToExpiry(): int
    {
        if ($this->expiry_date === null) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
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
