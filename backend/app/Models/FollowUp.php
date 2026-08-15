<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned return visit or teleconsult linked to the encounter that
 * generated it (DATABASE.md §3.17, PRODUCT_REQUIREMENTS §6.7). Planned →
 * booked (linked to a real appointment) → completed, or cancelled with a
 * reason. Never soft-deleted — a cancelled plan is history.
 */
class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory, HasUuid;

    public const TYPE_RETURN_VISIT = 'return_visit';

    public const TYPE_TELECONSULT = 'teleconsult';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'provider_staff_id',
        'follow_up_type',
        'planned_at',
        'reason',
        'booked_appointment_id',
        'status',
        'cancel_reason',
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
            'planned_at' => 'datetime',
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
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'booked_appointment_id');
    }
}
