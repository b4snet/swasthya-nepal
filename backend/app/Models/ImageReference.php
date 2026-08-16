<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ImageReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DICOM/PACS reference attached to a study (DATABASE.md §3.29,
 * PRODUCT_REQUIREMENTS §6.9). References ONLY — study/series/SOP instance
 * UIDs and PACS URLs, never pixels (PACS owns the images). The composite
 * FK to `studies` is the no-dangling guarantee: a reference can only exist
 * against a study in the same tenant, so a report can never point at
 * imagery that does not exist or belongs elsewhere.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ImageReference extends Model
{
    /** @use HasFactory<ImageReferenceFactory> */
    use HasFactory, HasUuid;

    public const TYPE_STUDY_UID = 'dicom_study_instance_uid';

    public const TYPE_SERIES_UID = 'dicom_series_instance_uid';

    public const TYPE_SOP_UID = 'dicom_sop_instance_uid';

    public const TYPE_PACS_URL = 'pacs_url';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'study_id',
        'reference_type',
        'reference_value',
        'description',
        'created_by',
    ];

    /**
     * @return BelongsTo<Study, $this>
     */
    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class, 'study_id');
    }
}
