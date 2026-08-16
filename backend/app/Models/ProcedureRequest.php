<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ProcedureRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a surgical procedure (DATABASE.md §3.48, PRODUCT_REQUIREMENTS
 * §6.10): theatre scheduling with conflict detection. Tenant+facility
 * scoped, RLS on + FORCED.
 */
class ProcedureRequest extends Model
{
    /** @use HasFactory<ProcedureRequestFactory> */
    use HasFactory, HasUuid;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_EMERGENCY = 'emergency';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'requested_by_staff_id',
        'procedure_name',
        'priority',
        'status',
        'theatre_id',
        'scheduled_at',
        'scheduled_duration_minutes',
        'equipment_requirements',
        'team_requirements',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'scheduled_duration_minutes' => 'integer',
            'equipment_requirements' => 'array',
            'team_requirements' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Theatre, $this>
     */
    public function theatre(): BelongsTo
    {
        return $this->belongsTo(Theatre::class, 'theatre_id');
    }
}
