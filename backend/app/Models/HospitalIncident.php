<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalIncident extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_ACTIONS = 'actions_pending';

    public const STATUS_CLOSED = 'closed';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    protected $table = 'hospital_incidents';

    protected $fillable = [
        'tenant_id', 'facility_id', 'incident_code', 'title', 'category',
        'severity', 'status', 'description', 'reported_by', 'reported_at',
        'assigned_to', 'patient_id', 'encounter_id', 'root_cause',
        'contributing_factors', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'contributing_factors' => 'array',
            'metadata' => 'array',
            'reported_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class, 'incident_id');
    }
}
