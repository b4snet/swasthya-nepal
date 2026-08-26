<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectiveAction extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'facility_id', 'incident_id', 'compliance_report_id',
        'action_code', 'title', 'description', 'action_type',
        'owner_staff_id', 'due_date', 'completed_date',
        'verified_by', 'verified_at', 'status', 'evidence',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_date' => 'date',
            'verified_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(HospitalIncident::class, 'incident_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
