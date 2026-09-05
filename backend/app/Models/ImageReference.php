<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ImageReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DICOM/PACS reference attached to a performed/reported study.
 * References only — never pixels. Composite FK guarantees no dangling refs.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ImageReference extends Model
{
    /** @use HasFactory<ImageReferenceFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'study_id',
        'reference_type', // dicom_study_instance_uid, dicom_series_instance_uid, dicom_sop_instance_uid, pacs_url
        'reference_value',
        'series_instance_uid',
        'sop_instance_uid',
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