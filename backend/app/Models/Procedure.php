<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ProcedureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The executed surgical procedure record (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10): team log, anesthesia, time-stamped events,
 * checklists, recovery. Case closure (ot:close) requires checklist
 * compliance. Tenant+facility scoped, RLS on + FORCED.
 */
class Procedure extends Model
{
    /** @use HasFactory<ProcedureFactory> */
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_request_id',
        'patient_id',
        'encounter_id',
        'theatre_id',
        'status',
        'started_at',
        'ended_at',
        'surgeon_staff_id',
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
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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

    /**
     * @return BelongsTo<ProcedureRequest, $this>
     */
    public function procedureRequest(): BelongsTo
    {
        return $this->belongsTo(ProcedureRequest::class, 'procedure_request_id');
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'procedure_id');
    }
}
