<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RpmDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A device adapter ENROLLED against a patient (DATABASE.md §3.56, ROADMAP
 * Phase 20 — RPM). Enrollment requires the patient's ACTIVE
 * device_monitoring consent; only ACTIVE devices ingest readings.
 *
 *   pending → active ⇄ disabled   (CAS-guarded, audited)
 *
 * settings holds PERSONALIZED thresholds per parameter
 * (`settings.thresholds.{parameter}.{high|low}`) and the alert cooldown
 * (`settings.alert_cooldown_minutes`, default 15) — alert-fatigue control.
 * `adapter` is a transport/adapter reference, never device content.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class RpmDevice extends Model
{
    /** @use HasFactory<RpmDeviceFactory> */
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const DEFAULT_ALERT_COOLDOWN_MINUTES = 15;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'device_identifier',
        'model',
        'manufacturer',
        'reading_type',
        'status',
        'settings',
        'adapter',
        'last_seen_at',
        'created_by',
        'updated_by',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_seen_at' => 'datetime',
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
     * @return HasMany<RpmReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(RpmReading::class, 'device_id');
    }

    /**
     * Personalized thresholds for a parameter (device override, else null).
     *
     * @return array{high?: float|int, low?: float|int}|null
     */
    public function thresholdFor(string $parameter): ?array
    {
        $thresholds = $this->settings['thresholds'] ?? [];

        return isset($thresholds[$parameter]) && is_array($thresholds[$parameter])
            ? $thresholds[$parameter]
            : null;
    }

    public function alertCooldownMinutes(): int
    {
        $minutes = $this->settings['alert_cooldown_minutes'] ?? self::DEFAULT_ALERT_COOLDOWN_MINUTES;

        return is_int($minutes) && $minutes > 0 ? $minutes : self::DEFAULT_ALERT_COOLDOWN_MINUTES;
    }
}
