<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LabOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered test within a lab order (DATABASE.md §3.27). Carries the
 * entered result value/unit, the reference-range snapshot taken at order
 * time, and the entry/verification actors — verification is a distinct,
 * audited step (entry ≠ verification enforced at the application layer).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LabOrderItem extends Model
{
    /** @use HasFactory<LabOrderItemFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_id',
        'lab_test_id',
        'result_value',
        'result_unit',
        'reference_range',
        'entered_by_staff_id',
        'entered_at',
        'verified_by_staff_id',
        'verified_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LabOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    /**
     * @return BelongsTo<LabTest, $this>
     */
    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }
}
