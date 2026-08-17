<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DispensingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A STANDALONE dispensing record (PRODUCT_REQUIREMENTS §6.7 `dispensing`
 * entity; DATABASE.md §3.30): a pharmacist dispenses a medication directly
 * to a patient with NO prescription (walk-in/OTC-style stock-out), drawing
 * the exact batch with the same CAS machinery as prescription dispensing.
 *
 *   dispensed → reversed
 *
 * The row is the immutable dispensing record: patient, medication, exact
 * batch (id + snapshot), quantity, and the dispenser stamp. The stock truth
 * stays the batch/shelf CAS + the append-only ledger (the movement
 * references this row via inventory_movements.dispensing_id), and the
 * financial truth is the posted charge with source_type = 'dispensing'
 * (charges.dispensing_id). Prescription-linked dispensing is a SEPARATE
 * surface (prescription_lines stamps) and is untouched.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Dispensing extends Model
{
    /** @use HasFactory<DispensingFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'medication_id',
        'inventory_item_id',
        'stock_batch_id',
        'batch_number',
        'batch_expires_at',
        'quantity_minor',
        'status',
        'dispensed_by_staff_id',
        'dispensed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_expires_at' => 'date',
            'quantity_minor' => 'integer',
            'dispensed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * @return BelongsTo<StockBatch, $this>
     */
    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'dispensed_by_staff_id');
    }
}
