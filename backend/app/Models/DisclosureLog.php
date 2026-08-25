<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisclosureLog extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DENIED = 'denied';

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'requester_name',
        'requester_organization', 'purpose', 'authorized_by',
        'recipient_name', 'recipient_organization', 'disclosed_at',
        'documents', 'status', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'documents' => 'array',
            'metadata' => 'array',
            'disclosed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
