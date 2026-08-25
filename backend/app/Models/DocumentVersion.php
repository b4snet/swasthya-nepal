<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'document_id', 'version_number', 'title',
        'description', 'file_path', 'file_hash', 'mime_type',
        'file_size_bytes', 'object_key', 'change_reason',
        'created_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HospitalDocument::class, 'document_id');
    }
}
