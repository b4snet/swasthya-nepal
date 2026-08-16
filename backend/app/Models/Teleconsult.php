<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TeleconsultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A virtual consultation (DATABASE.md §3.55, PRODUCT_REQUIREMENTS §6.20):
 * booked through the SAME schedule/queue model as an in-person visit (the
 * appointment row carries appointment_type = 'teleconsult'), then conducted
 * over a secure video session with an EXPLICIT, consent-bound recording
 * decision and a documented connectivity-failure fallback.
 *
 * State machine (CLINICAL_SAFETY.md §7):
 *   scheduled → ready → in_progress → completed
 *             ↘ cancelled
 *             ↘ failed (connectivity failure → fallback_mode documented:
 *               phone / in_person / reschedule — the consult never silently
 *               drops; the fallback is audited and the encounter can still
 *               be documented and signed to the same standard as OPD).
 *
 * Tenant+facility scoped, RLS on + FORCED (2026_08_17_310100).
 */
class Teleconsult extends Model
{
    /** @use HasFactory<TeleconsultFactory> */
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const FALLBACK_PHONE = 'phone';

    public const FALLBACK_IN_PERSON = 'in_person';

    public const FALLBACK_RESCHEDULE = 'reschedule';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'appointment_id',
        'patient_id',
        'provider_staff_id',
        'status',
        'scheduled_at',
        'starts_at',
        'ends_at',
        'fallback_mode',
        'fallback_reason',
        'created_by_staff_id',
        'updated_by_staff_id',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }

    /**
     * The shared clinical encounter for this consultation — the SAME record
     * discipline as OPD: notes, diagnoses, prescriptions, follow-up, and
     * sign-off all live on the encounter (PRODUCT_REQUIREMENTS §6.20.4).
     *
     * @return HasOne<Encounter, $this>
     */
    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class, 'appointment_id', 'appointment_id');
    }

    /**
     * @return HasMany<VideoSession, $this>
     */
    public function videoSessions(): HasMany
    {
        return $this->hasMany(VideoSession::class, 'teleconsult_id');
    }

    /**
     * Whether the consultation can move out of its current state — a
     * cancelled or completed teleconsult is terminal.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
