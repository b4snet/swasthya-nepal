<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InventoryAdjustmentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval-gated stock adjustment path (PRODUCT_REQUIREMENTS §6.15:
 * "cycle counts and corrections with approval workflow (never silent
 * edits)"; ROADMAP Phase 14 acceptance: "adjustments approval-gated").
 * A requester submits a signed delta with a mandatory reason; an approver
 * (never the requester) approves — the approval applies the stock CAS and
 * writes the ledger row atomically. Rejection is terminal with a reason.
 */
class InventoryAdjustmentRequest extends Model
{
    /** @use HasFactory<InventoryAdjustmentRequestFactory> */
    use HasFactory, HasUuid;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'inventory_item_id',
        'quantity_delta',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'rejected_by',
        'rejection_reason',
        'approved_at',
        'rejected_at',
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
            'quantity_delta' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'lock_version' => 'integer',
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
