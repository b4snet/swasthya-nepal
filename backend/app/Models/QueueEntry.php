<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A department-level queue entry: the operational waiting order for a
 * patient within a department. Distinct from appointments (which own
 * scheduled time) and encounters (which own clinical care).
 *
 * State machine:
 *   waiting → called → in_progress → completed
 *   waiting → cancelled
 *   waiting → no_show
 *   called → skipped → waiting (re-queued)
 *   called → recalled → waiting (re-queued)
 *   called → cancelled
 *   in_progress → completed
 */
class QueueEntry extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CALLED = 'called';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_RECALLED = 'recalled';

    /**
     * Allowed status transitions. Every entry is: from_status => [to_statuses].
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_WAITING => [self::STATUS_CALLED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CALLED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED, self::STATUS_NO_SHOW, self::STATUS_SKIPPED, self::STATUS_RECALLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'facility_id', 'department', 'queue_code',
        'patient_id', 'appointment_id', 'provider_staff_id',
        'priority', 'status', 'token_number', 'called_at',
        'started_at', 'completed_at', 'waiting_room', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }

    /**
     * @return HasMany<QueueEntryHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(QueueEntryHistory::class, 'queue_entry_id');
    }

    /**
     * Whether this entry can transition to the given status.
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
                "Cannot transition queue entry from [{$this->status}] to [{$status}]."
            );
        }

        $this->status = $status;
    }

    /**
     * Record a state transition in the history table.
     */
    public function recordHistory(string $action, string $fromStatus, string $toStatus, ?string $actorId = null, ?string $reason = null, ?string $department = null, ?array $metadata = null): QueueEntryHistory
    {
        return QueueEntryHistory::create([
            'tenant_id' => $this->tenant_id,
            'facility_id' => $this->facility_id,
            'queue_entry_id' => $this->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'department' => $department,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Generate a unique queue code.
     */
    public static function generateQueueCode(): string
    {
        return 'Q-'.strtoupper(Str::random(8));
    }
}
