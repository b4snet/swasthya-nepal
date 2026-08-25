<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Accounts Payable — supplier obligations (DATABASE.md §3.60).
 *
 * Created when a supplier invoice is matched against a purchase order
 * and goods receipt (three-way match). Lifecycle: draft → approved →
 * scheduled → paid. Supports partial payments and multiple payment
 * methods.
 */
class AccountPayable extends Model
{
    /** @use HasFactory<\Database\Factories\AccountPayableFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'supplier_id',
        'purchase_order_id',
        'goods_receipt_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'total_minor',
        'tax_minor',
        'paid_minor',
        'status',
        'approved_by',
        'approved_at',
        'payment_reference',
        'paid_at',
        'notes',
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
            'invoice_date' => 'date',
            'due_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'total_minor' => 'integer',
            'tax_minor' => 'integer',
            'paid_minor' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function remainingMinor(): int
    {
        return $this->total_minor + $this->tax_minor - $this->paid_minor;
    }
}
