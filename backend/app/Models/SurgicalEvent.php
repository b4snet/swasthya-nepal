<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SurgicalEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A time-stamped intra-operative event (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10). Append-only. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class SurgicalEvent extends Model
{
    /** @use HasFactory<SurgicalEventFactory> */
    use HasFactory, HasUuid;

    public const EVENT_TIME_OUT = 'time_out';

    public const EVENT_INCISION = 'incision';

    public const EVENT_CLOSURE = 'closure';

    public const EVENT_SIGN_OUT = 'sign_out';

    public const EVENT_COMPLICATION = 'complication';

    public const EVENT_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'event_type',
        'occurred_at',
        'staff_id',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
