<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TransferEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audited bed/ward transfer (DATABASE.md §3.23, PRODUCT_REQUIREMENTS
 * §6.5, ROADMAP Phase 8): the patient moves from one bed to another with a
 * captured reason and the authorizing staff. The transfer history IS the
 * historical bed timeline — every move is an immutable row.
 *
 * Tenant-scoped with tenant-safe composite FKs; the row is never mutated
 * after creation. Reason text is clinical context and never reaches audit
 * payloads (facts only: ids, beds, who, when).
 */
class TransferEvent extends Model
{
    /** @use HasFactory<TransferEventFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'admission_id',
        'from_bed_id',
        'to_bed_id',
        'reason',
        'transferred_by',
        'transferred_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function fromBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'from_bed_id');
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function toBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'to_bed_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'transferred_by');
    }
}
