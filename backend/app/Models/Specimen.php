<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SpecimenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical sample collected for a lab order (DATABASE.md §3.28,
 * PRODUCT_REQUIREMENTS §6.8). An order yields one or more specimens, each
 * with a UNIQUE per-tenant accession number (the printed label) and a chain
 * of custody:
 *
 *   collected → accessioned → processing → completed | rejected
 *
 * Every step records WHO and WHEN (the medico-legal specimen chain).
 * Rejection requires a reason (CHECK). The accession number is minted at
 * collection and never reused.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Specimen extends Model
{
    /** @use HasFactory<SpecimenFactory> */
    use HasFactory, HasUuid;

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_ACCESSIONED = 'accessioned';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_id',
        'accession_number',
        'specimen_type',
        'container',
        'status',
        'collected_by_staff_id',
        'collected_at',
        'accessioned_by_staff_id',
        'accessioned_at',
        'processing_by_staff_id',
        'processing_at',
        'completed_by_staff_id',
        'completed_at',
        'rejected_by_staff_id',
        'rejected_at',
        'rejection_reason',
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
            'collected_at' => 'datetime',
            'accessioned_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LabOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }
}
