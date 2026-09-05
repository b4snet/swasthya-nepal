<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records when an order set is applied to an encounter. Preserves
 * provenance: which version was used, who applied it, and what
 * modifications were made. This is the audit trail for order-set usage.
 */
class OrderSetApplication extends Model
{
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'order_set_id',
        'order_set_version',
        'encounter_id',
        'applied_by_staff_id',
        'applied_at',
        'item_count',
        'modified_items',
        'created_orders',
    ];

    protected function casts(): array
    {
        return [
            'order_set_version' => 'integer',
            'item_count' => 'integer',
            'modified_items' => 'array',
            'created_orders' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderSet, $this>
     */
    public function orderSet(): BelongsTo
    {
        return $this->belongsTo(OrderSet::class, 'order_set_id');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'applied_by_staff_id');
    }
}
