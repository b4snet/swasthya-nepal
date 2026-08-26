<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSignature extends Model
{
    use HasFactory, HasUuid;

    public const TYPES = [
        'patient', 'guardian', 'witness', 'clinician', 'doctor',
        'nurse', 'pharmacist', 'admin',
    ];

    protected $fillable = [
        'tenant_id', 'submission_id', 'signature_type',
        'signer_id', 'signer_name', 'signer_role',
        'signature_data', 'signature_method',
        'signed_at', 'ip_address', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'signed_at' => 'datetime',
    ];

    protected $hidden = [
        'signature_data',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function scopeForSubmission($query, string $submissionId)
    {
        return $query->where('submission_id', $submissionId);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('signature_type', $type);
    }
}
