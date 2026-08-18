<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A goods receipt line: one line per PO line per GRN (partial unique), with
 * the received quantity and the received unit price — the two facts the
 * three-way match compares against the PO line (ordered quantity, PO
 * price). Each line creates a `receipt` stock movement (stock-in).
 */
class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'grn_id',
        'po_line_id',
        'medication_id',
        'quantity_received',
        'unit_price_received',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'unit_price_received' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'po_line_id');
    }
}
