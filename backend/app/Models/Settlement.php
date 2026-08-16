<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily cashier settlement (DATABASE.md §3.34, PRODUCT_REQUIREMENTS §6.13):
 * one row per (facility, cashier, day) — the unique index serializes the
 * day. `expected_minor` is the day's captured payments for that cashier;
 * reconciliation records `actual_minor` (what was counted) and the variance
 * is never silently absorbed: a zero variance reconciles, a non-zero
 * variance DISPUTES (and alerts via audit) until a human resolves it.
 *
 * Status: `open → reconciled | disputed` (CAS on status + lock_version —
 * a second reconcile of an already-closed day affects zero rows and 409s).
 * Integer minor units. Tenant+facility scoped, RLS on + FORCED.
 */
class Settlement extends Model
{
    /** @use HasFactory<SettlementFactory> */
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_DISPUTED = 'disputed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'branch_id',
        'cashier_id',
        'settlement_date',
        'expected_minor',
        'actual_minor',
        'variance_minor',
        'status',
        'reconciled_by',
        'reconciled_at',
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
            'expected_minor' => 'integer',
            'actual_minor' => 'integer',
            'variance_minor' => 'integer',
            'lock_version' => 'integer',
            'settlement_date' => 'date',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'cashier_id');
    }
}
