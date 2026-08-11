<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The clinical visit container (DATABASE.md §3.17) — the spine of the
 * clinical record. Started from a checked-in appointment for OPD.
 *
 * Tenant-scoped (tenant_id, facility_id NOT NULL). Signed encounters are
 * immutable history; amendment is the only evolution path. Status lifecycle
 * in this phase: open → signed. lock_version guards concurrent edits.
 */
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_AMENDED = 'amended';

    public const STATUS_CLOSED = 'closed';

    public const TYPE_OPD = 'opd';

    public const TYPE_IPD = 'ipd';

    public const TYPE_ER = 'er';

    public const TYPE_TELECONSULT = 'teleconsult';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'provider_staff_id',
        'type',
        'status',
        'started_at',
        'ended_at',
        'signed_by',
        'signed_at',
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
            'signed_at' => 'datetime',
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }

    /**
     * @return HasMany<Diagnosis, $this>
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class, 'encounter_id');
    }

    /**
     * @return HasMany<ClinicalNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'encounter_id');
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'encounter_id');
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'encounter_id');
    }
}
