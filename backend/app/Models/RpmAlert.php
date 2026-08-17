<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RpmAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personalized-threshold breach that requires HUMAN-mediated escalation
 * (ROADMAP Phase 20, CLINICAL_SAFETY.md §7): the alert is acknowledged by a
 * clinician (who/what/when) and then resolved after action — never
 * auto-silenced.
 *
 *   open → acknowledged → resolved   (CAS-guarded; double-ack/replay refused)
 *
 * Alert-fatigue controls: one OPEN alert per (device, parameter) — a second
 * breach while open does not create a duplicate; a cooldown (device
 * settings.alert_cooldown_minutes, default 15) suppresses re-alerting for
 * the same parameter until the previous alert is resolved.
 *
 * Threshold/observed values are clinical PHI and never reach audit payloads
 * (facts and ids only).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class RpmAlert extends Model
{
    /** @use HasFactory<RpmAlertFactory> */
    use HasFactory, HasUuid;

    public const TYPE_HIGH = 'threshold_high';

    public const TYPE_LOW = 'threshold_low';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'device_id',
        'reading_id',
        'alert_type',
        'parameter',
        'threshold_value',
        'observed_value',
        'severity',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledged_note',
        'resolved_by',
        'resolved_at',
        'created_by',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_value' => 'array',
            'observed_value' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<RpmDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(RpmDevice::class, 'device_id');
    }

    /**
     * @return BelongsTo<RpmReading, $this>
     */
    public function reading(): BelongsTo
    {
        return $this->belongsTo(RpmReading::class, 'reading_id');
    }
}
