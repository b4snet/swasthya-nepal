<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ReactionReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A transfusion reaction report (DATABASE.md §3.50, PRODUCT_REQUIREMENTS
 * §6.12). Tenant+facility scoped, RLS on + FORCED.
 */
class ReactionReport extends Model
{
    /** @use HasFactory<ReactionReportFactory> */
    use HasFactory, HasUuid;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'transfusion_id',
        'occurred_at',
        'severity',
        'symptoms',
        'action_taken',
        'status',
        'reviewed_at',
        'reviewed_by_staff_id',
        'reported_by_staff_id',
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
            'occurred_at' => 'datetime',
            'symptoms' => 'array',
            'reviewed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
