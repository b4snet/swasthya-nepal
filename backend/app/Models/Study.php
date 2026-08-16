<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StudyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imaging study per radiology order (DATABASE.md §3.29,
 * PRODUCT_REQUIREMENTS §6.9). The study tracks the imaging lifecycle on the
 * SHARED order surface (a `lab_orders` row with a category='radiology'
 * item):
 *
 *   ordered → scheduled (modality + slot) → performed (radiographer,
 *   images captured) → reported (a report exists)
 *
 * `cancelled` is terminal and requires a reason. Images live in PACS — this
 * table carries only DICOM/pixel *references* (image_references), never
 * pixels, and a report can only be released against a study that exists
 * (traceability: report → study → modality → order).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Study extends Model
{
    /** @use HasFactory<StudyFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PERFORMED = 'performed';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_id',
        'modality_id',
        'status',
        'ordered_at',
        'scheduled_at',
        'performed_at',
        'performed_by_staff_id',
        'cancel_reason',
        'preparation_instructions',
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
            'ordered_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'performed_at' => 'datetime',
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

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class, 'modality_id');
    }

    /**
     * @return HasMany<RadiologyReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(RadiologyReport::class, 'study_id');
    }

    /**
     * @return HasMany<ImageReference, $this>
     */
    public function imageReferences(): HasMany
    {
        return $this->hasMany(ImageReference::class, 'study_id');
    }
}
