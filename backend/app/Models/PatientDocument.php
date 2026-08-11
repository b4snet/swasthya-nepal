<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PatientDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for a patient file (DATABASE.md §3.38): consent forms, IDs,
 * referrals, reports. The object key is the pointer; the bytes live in
 * object storage — which does not exist yet, so a document is honestly
 * registered as `staged` with no key and becomes `available` only when the
 * storage integration lands. No endpoint claims a file can be downloaded.
 */
class PatientDocument extends Model
{
    /** @use HasFactory<PatientDocumentFactory> */
    use HasFactory, HasUuid;

    public const STATUS_STAGED = 'staged';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_PURGED = 'purged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'document_type',
        'object_key',
        'checksum',
        'size_bytes',
        'mime_type',
        'status',
        'uploaded_by',
        'uploaded_at',
        'expires_at',
        'retention_class',
        'parent_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
