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
 *
 * `token_no` is issued at check-in from the per-(provider, date) counter.
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, HasUuid;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const SOURCE_COUNTER = 'counter';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_WALK_IN = 'walk_in';

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
