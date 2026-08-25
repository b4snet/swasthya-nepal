<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalDocument extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';
    public const STATUS_RELEASED = 'released';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_SUPERSEDED = 'superseded';

    public const CATEGORY_CLINICAL = 'clinical';
    public const CATEGORY_FINANCIAL = 'financial';
    public const CATEGORY_HR = 'hr';
    public const CATEGORY_ADMINISTRATIVE = 'administrative';
    public const CATEGORY_GOVERNANCE = 'governance';
    public const CATEGORY_PROCUREMENT = 'procurement';
    public const CATEGORY_PATIENT = 'patient_generated';

    protected $fillable = [
        'tenant_id', 'facility_id', 'document_code', 'document_type',
        'category', 'classification', 'title', 'description',
        'patient_id', 'encounter_id', 'staff_id', 'department',
        'source_type', 'source_id', 'parent_document_id',
        'version', 'is_latest', 'file_path', 'file_hash',
        'mime_type', 'file_size_bytes', 'object_key',
        'metadata', 'tags', 'status', 'uploaded_by',
        'finalized_by', 'finalized_at', 'retention_days',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'version' => 'integer',
            'is_latest' => 'boolean',
            'metadata' => 'array',
            'tags' => 'array',
            'finalized_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgement::class, 'document_id');
    }
}
