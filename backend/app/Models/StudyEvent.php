<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StudyEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit audit trail for study lifecycle events.
 * Provides a longitudinal view beyond the generic audit_events table.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class StudyEvent extends Model
{
    /** @use HasFactory<StudyEventFactory> */
    use HasFactory, HasUuid;

    public const EVENT_ORDERED = 'ordered';
    public const EVENT_SCHEDULED = 'scheduled';
    public const EVENT_RESCHEDULED = 'rescheduled';
    public const EVENT_ARRIVED = 'arrived';
    public const EVENT_IN_PROGRESS = 'in_progress';
    public const EVENT_ACQUIRED = 'acquired';
    public const EVENT_PERFORMED = 'performed';
    public const EVENT_REPORT_DRAFTED = 'report_drafted';
    public const EVENT_REPORT_VERIFIED = 'report_verified';
    public const EVENT_REPORT_AMENDED = 'report_amended';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_RELEASED_TO_CLINICIAN = 'released_to_clinician';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'study_id',
        'event_type',
        'event_description',
        'actor_staff_id',
        'metadata',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Study, $this>
     */
    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class, 'study_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id');
    }
}