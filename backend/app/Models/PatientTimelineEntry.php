<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PatientTimelineEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chronological, patient-scoped event (PRODUCT_REQUIREMENTS §6.1):
 * registration, identifier/contact/policy changes, consents, documents,
 * merges. Written ONLY by the PatientTimeline service, entries carry facts
 * and references — never clinical content (the no-PHI rule of MASTER_RULES
 * §10.5).
 */
class PatientTimelineEntry extends Model
{
    /** @use HasFactory<PatientTimelineEntryFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'occurred_at',
        'event_type',
        'summary',
        'actor_id',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'summary' => 'array',
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
