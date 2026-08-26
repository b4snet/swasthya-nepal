<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientComplaint extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'facility_id', 'complaint_code', 'patient_id',
        'category', 'title', 'description', 'severity',
        'status', 'assigned_to', 'response', 'responded_by',
        'responded_at', 'closed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'response' => 'array',
            'metadata' => 'array',
            'responded_at' => 'datetime',
            'closed_at' => 'datetime',
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
