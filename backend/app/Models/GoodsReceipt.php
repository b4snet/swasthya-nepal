<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goods receipt (PRODUCT_REQUIREMENTS §6.16, DATABASE.md §3.32): the
 * goods-check-in against a PO. Status: draft → received (stock-in applied,
 * PO lines CAS-advanced) → matched (only when the three-way match passes).
 * match_status (matched | mismatch) is the payment gate — a mismatched GRN
 * can never reach `matched`, and the PO cannot close while any GRN is
 * unmatched/mismatched.
 */
class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_MATCHED = 'matched';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_MISMATCH = 'mismatch';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'grn_number',
        'po_id',
        'received_by',
        'received_at',
        'status',
        'match_status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'grn_id');
    }
}
