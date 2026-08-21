<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referral lifecycle: pending → accepted → scheduled → completed,
 * or pending → rejected, or any active state → cancelled.
 *
 * Supports internal (receiving_staff_id) and external
 * (receiving_facility_name) destinations.
 */
class Referral extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'referring_staff_id', 'referring_department',
        'receiving_staff_id', 'receiving_facility_name', 'receiving_department',
        'reason', 'clinical_summary', 'urgency', 'specialty', 'attachments',
        'status', 'rejection_reason', 'completion_notes',
        'accepted_at', 'rejected_at', 'completed_at', 'cancelled_at',
        'scheduled_appointment_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'attachments' => 'array',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_ACCEPTED = 'accepted';

    const STATUS_REJECTED = 'rejected';

    const STATUS_SCHEDULED = 'scheduled';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    const URGENCY_ROUTINE = 'routine';

    const URGENCY_URGENT = 'urgent';

    const URGENCY_EMERGENT = 'emergent';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function referringStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'referring_staff_id');
    }

    public function receivingStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'receiving_staff_id');
    }

    public function scheduledAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'scheduled_appointment_id');
    }

    public function isInternal(): bool
    {
        return $this->receiving_staff_id !== null;
    }

    public function isExternal(): bool
    {
        return $this->receiving_facility_name !== null;
    }

    public function canAccept(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canReject(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canComplete(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_SCHEDULED]);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_SCHEDULED]);
    }
}
