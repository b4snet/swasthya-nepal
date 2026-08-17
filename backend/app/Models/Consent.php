<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patient consent as a first-class, versioned, auditable record
 * (DATABASE.md §3.39): treatment, data use, telehealth, marketing opt-out,
 * research.
 *
 * One active consent per (patient, type); a new capture creates a new
 * version and expires the prior one. History outlives the consent —
 * disputes require it.
 */
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory, HasUuid;

    public const TYPE_TREATMENT = 'treatment';

    public const TYPE_DATA_USE = 'data_use';

    public const TYPE_TELEHEALTH = 'telehealth';

    // Phase 3 slice 25 — RPM: device monitoring consent (data collection
    // consent; CLINICAL_SAFETY.md §7 — no silent device data collection).
    public const TYPE_DEVICE_MONITORING = 'device_monitoring';

    public const TYPE_MARKETING = 'marketing';

    public const TYPE_RESEARCH = 'research';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'consent_type',
        'version',
        'status',
        'scope',
        'given_by',
        'given_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'given_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
