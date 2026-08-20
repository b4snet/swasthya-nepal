<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDocument extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_STAGED = 'staged';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_PATIENT_ONLY = 'patient_only';

    public const VISIBILITY_STAFF_ONLY = 'staff_only';

    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id',
        'encounter_id', 'document_type', 'title', 'description',
        'file_path', 'file_hash', 'mime_type', 'file_size_bytes', 'visibility', 'patient_accessible', 'uploaded_by_staff_id',
        'metadata', 'object_key', 'checksum', 'size_bytes', 'status',
        'uploaded_by', 'uploaded_at', 'expires_at', 'retention_class', 'parent_document_id',
        'phi_safe',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'patient_accessible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
