<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PrescriptionLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medication on a prescription (DATABASE.md §3.21): dose, route,
 * frequency — the source of dispensing and interaction checks.
 */
class PrescriptionLine extends Model
{
    /** @use HasFactory<PrescriptionLineFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'prescription_id',
        'medication_id',
        'dose',
        'route',
        'frequency',
        'duration',
        'quantity_minor',
        'instructions',
        'status',
        'line_no',
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
            'quantity_minor' => 'integer',
            'line_no' => 'integer',
            'dispensed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'dispensed_by_staff_id');
    }
}
