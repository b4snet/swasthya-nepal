<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\LabTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant's lab/radiology test catalog (DATABASE.md §3.25,
 * PRODUCT_REQUIREMENTS §6.8). Laboratory tests and radiology studies share
 * this reference (category distinguishes them). Soft-deletable so retired
 * tests stay referenced by order history.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class LabTest extends Model
{
    /** @use HasFactory<LabTestFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const CATEGORY_LABORATORY = 'laboratory';

    public const CATEGORY_RADIOLOGY = 'radiology';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'category',
        'sample_type',
        'unit',
        'reference_range',
        'method',
        'status',
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
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
