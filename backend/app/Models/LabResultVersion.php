<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LabResultVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only version of an ordered test's result (DATABASE.md §3.28,
 * CLINICAL_SAFETY §7). Entry writes version 1; a correction (reported →
 * correcting) writes version N+1 with its reason (CHECK: only version 1 may
 * lack a reason). The original always remains visible; the item's current
 * columns are the live view of the LATEST version. If a corrected version is
 * flagged critical, escalation re-runs (a fresh critical_value_event).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LabResultVersion extends Model
{
    /** @use HasFactory<LabResultVersionFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_item_id',
        'version_no',
        'result_value',
        'result_unit',
        'reference_range',
        'is_critical',
        'correction_reason',
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
            'version_no' => 'integer',
            'is_critical' => 'boolean',
            'entered_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LabOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LabOrderItem::class, 'lab_order_item_id');
    }
}
