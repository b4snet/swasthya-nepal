<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An appointment (DATABASE.md §3.15): patient × provider × slot — the
 * booking that backs queues, tokens, check-in, cancellation.
 *
 * Tenant-scoped (tenant_id, facility_id NOT NULL). Never soft-deleted:
 * status transitions only. Status lifecycle:
 *   booked → checked_in → in_consultation → completed
 *   booked → cancelled (reason required) / no_show
 *   checked_in → cancelled / no_show
 *
 * `token_no` is issued at check-in from the per-(provider, date) counter.
 * `rescheduled_from` preserves provenance when an appointment is rescheduled.
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUuid;

    public const TYPE_OPD = 'opd';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_TELECONSULT = 'teleconsult';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const SOURCE_COUNTER = 'counter';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_WALK_IN = 'walk_in';

    public const SOURCE_FOLLOW_UP = 'follow_up';

    /**
     * Allowed status transitions. Every entry is: from_status => [to_statuses].
     * The CHECK constraint is the DB-level guard; this map is the
     * application-level state machine that prevents invalid transitions
     * before they reach the DB.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_BOOKED => [self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CHECKED_IN => [self::STATUS_IN_CONSULTATION, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_IN_CONSULTATION => [self::STATUS_COMPLETED],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'provider_staff_id',
        'service_id',
        'appointment_type',
        'starts_at',
        'ends_at',
        'status',
        'cancel_reason',
        'token_no',
        'source',
        'checked_in_by',
        'checked_in_at',
        'lock_version',
        'rescheduled_from',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * Whether this appointment can transition to the given status.
     */
    public function canTransitionTo(string $status): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($status, $allowed, true);
    }

    /**
     * Transition to a new status. Throws \InvalidArgumentException if the
     * transition is not allowed by the state machine.
     */
    public function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                "Cannot transition from [{$this->status}] to [{$status}]."
            );
        }

        $this->status = $status;
        $this->lock_version += 1;
    }

    /**
     * Whether the appointment is in a terminal state (no further transitions).
     */
    public function isTerminal(): bool
    {
        return ! isset(self::ALLOWED_TRANSITIONS[$this->status]);
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * @return HasOne<Encounter, $this>
     */
    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class, 'appointment_id');
    }
}
