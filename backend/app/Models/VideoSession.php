<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\VideoSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A secure video session for a teleconsult (DATABASE.md §3.55,
 * PRODUCT_REQUIREMENTS §6.20.3): METADATA ONLY — the WebRTC session
 * reference, participant, start/end, and the EXPLICIT recording decision.
 * Pixels and media streams never enter the database (object storage /
 * relay refs only). Recording is consent-bound and policy-bound: the
 * facility's `telehealth.recording_policy` setting (disabled |
 * consent_required | always_allowed — default disabled) plus the separate
 * telehealth:record permission plus the patient's ACTIVE telehealth
 * consent covering 'recording' when the policy requires it.
 *
 * State machine: active → ended | failed. A failed session is the
 * connectivity-failure trigger: the teleconsult records its fallback_mode
 * (phone / in_person / reschedule) and continues — never silently drops.
 *
 * Tenant+facility scoped, RLS on + FORCED (2026_08_17_310100).
 */
class VideoSession extends Model
{
    /** @use HasFactory<VideoSessionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_FAILED = 'failed';

    public const PARTICIPANT_PROVIDER = 'provider';

    public const PARTICIPANT_PATIENT = 'patient';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'teleconsult_id',
        'status',
        'started_at',
        'ended_at',
        'provider_session_ref',
        'participant_type',
        'recording_requested',
        'recording_consent_verified',
        'recording_started_at',
        'recording_ended_at',
        'recording_storage_ref',
        'failure_reason',
        'created_by_staff_id',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'recording_started_at' => 'datetime',
            'recording_ended_at' => 'datetime',
            'recording_requested' => 'boolean',
            'recording_consent_verified' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Teleconsult, $this>
     */
    public function teleconsult(): BelongsTo
    {
        return $this->belongsTo(Teleconsult::class, 'teleconsult_id');
    }
}
