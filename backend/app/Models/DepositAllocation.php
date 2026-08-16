<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DepositAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The append-only record of a deposit's money being applied to an invoice
 * (DATABASE.md §3.33): one allocation per (deposit, invoice) pair — the
 * unique index makes a double allocation structurally impossible. The row
 * is never edited or deleted; a correction is a reversing entry, not an
 * update. Tenant+facility scoped, RLS on + FORCED.
 */
class DepositAllocation extends Model
{
    /** @use HasFactory<DepositAllocationFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'deposit_id',
        'invoice_id',
        'amount_minor',
        'allocated_by',
        'allocated_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deposit, $this>
     */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class, 'deposit_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
