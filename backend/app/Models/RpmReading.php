<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RpmReadingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only, VALIDATED and clearly LABELED device reading (DATABASE.md
 * §3.56, ROADMAP Phase 20 acceptance: device-sourced data is never silently
 * treated as verified).
 *
 * validation_status:  validated — within plausible range and thresholds
 *                     flagged   — within plausible range but outside the
 *                                 personalized threshold (may alert)
 *                     rejected  — structurally invalid / implausible; stored
 *                                 for provenance but never alerts and never
 *                                 treated as a measurement
 *
 * Idempotency: (tenant_id, ingestion_id) is unique — an adapter retry with
 * the same ingestion id is a no-op returning the original reading.
 * BRIN-indexed on received_at (high-volume append; DATABASE.md §4 design
 * default — actual RANGE partitioning is a deployment-phase decision).
 * Value payloads are clinical PHI and never reach audit payloads.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class RpmReading extends Model
{
    /** @use HasFactory<RpmReadingFactory> */
    use HasFactory, HasUuid;

    public const VALIDATED = 'validated';

    public const FLAGGED = 'flagged';

    public const REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'device_id',
        'reading_type',
        'value',
        'units',
        'measured_at',
        'received_at',
        'source',
        'validation_status',
        'validation_reason',
        'provenance',
        'ingestion_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'provenance' => 'array',
            'measured_at' => 'datetime',
            'received_at' => 'datetime',
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
}
