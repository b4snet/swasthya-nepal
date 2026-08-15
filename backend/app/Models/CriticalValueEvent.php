<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CriticalValueEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A laboratory critical/panic value escalation (PRODUCT_REQUIREMENTS §6.8,
 * CLINICAL_SAFETY §7). Triggered at result entry when the enterer flags an
 * item critical; targeted at the ordering clinician, who must acknowledge it
 * (who/when recorded). If it stays unacknowledged a supervisor escalates it —
 * fail loudly, never silently (MASTER_RULES §11.3).
 *
 *   triggered → acknowledged   (target clinician, lab:acknowledge)
 *   triggered → escalated      (supervisor, lab:escalate, never the target)
 *   escalated → acknowledged   (target clinician — escalation stays loud
 *                               until a human closes it)
 *
 * Transitions are compare-and-swap on (status, lock_version); one OPEN event
 * per flagged item is the DB backstop. The event references the flagged item
 * but stores no result value.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class CriticalValueEvent extends Model
{
    /** @use HasFactory<CriticalValueEventFactory> */
    use HasFactory, HasUuid;

    public const STATUS_TRIGGERED = 'triggered';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_item_id',
        'patient_id',
        'encounter_id',
        'target_staff_id',
        'status',
        'detected_by_staff_id',
        'detected_at',
        'escalated_by_staff_id',
        'escalated_at',
        'acknowledged_by_staff_id',
        'acknowledged_at',
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
            'detected_at' => 'datetime',
            'escalated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LabOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LabOrderItem::class, 'lab_order_item_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'target_staff_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
