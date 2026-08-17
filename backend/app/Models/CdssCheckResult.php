<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CdssCheckResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted CDSS alert (DATABASE.md §3.57): what the knowledge base
 * flagged, at which rule version, for which patient. The open→overridden
 * transition records the prescriber's reason (audited — overrides are
 * never a silent dismiss, AI_RULES.md §7, CLINICAL_SAFETY.md §5–6).
 */
class CdssCheckResult extends Model
{
    /** @use HasFactory<CdssCheckResultFactory> */
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_OVERRIDDEN = 'overridden';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'alert_type',
        'rule_code',
        'rule_version',
        'severity',
        'message',
        'triggering_facts',
        'status',
        'override_reason',
        'overridden_by',
        'overridden_at',
        'lock_version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggering_facts' => 'array',
            'rule_version' => 'integer',
            'lock_version' => 'integer',
            'overridden_at' => 'datetime',
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
