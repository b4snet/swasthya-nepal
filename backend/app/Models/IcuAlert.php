<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IcuAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An ICU alert (DATABASE.md §3.49, PRODUCT_REQUIREMENTS §6.11): score
 * escalations, threshold breaches, and MISSED observations. Every alert
 * must be acknowledged (WHO saw it, WHEN). Message carries facts only —
 * never patient identifiers or PHI. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class IcuAlert extends Model
{
    /** @use HasFactory<IcuAlertFactory> */
    use HasFactory, HasUuid;

    public const TYPE_SCORE_ESCALATION = 'score_escalation';

    public const TYPE_MISSED_OBSERVATION = 'missed_observation';

    public const TYPE_THRESHOLD_BREACH = 'threshold_breach';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'icu_admission_id',
        'warning_score_id',
        'alert_type',
        'severity',
        'message',
        'status',
        'acknowledged_at',
        'acknowledged_by_staff_id',
        'resolved_at',
        'resolved_by_staff_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
