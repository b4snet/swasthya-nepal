<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IntegrationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message exchange row in the integration log (DATABASE.md §3.42,
 * INTEROPERABILITY.md §6–10): direction, message type, correlation id,
 * consent basis at the moment of exchange, payload reference (facts only —
 * never PHI), status machine with CAS-guarded retry counts, and mapping
 * version in effect. The retry discipline is ready here even though no live
 * adapter consumes it yet (INTEROPERABILITY.md §7–8). Tenant-scoped, RLS
 * on + FORCED.
 */
class IntegrationEvent extends Model
{
    /** @use HasFactory<IntegrationEventFactory> */
    use HasFactory, HasUuid;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_QUARANTINED = 'quarantined';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'integration_id',
        'direction',
        'message_type',
        'correlation_id',
        'consent_basis',
        'payload',
        'status',
        'attempts',
        'error',
        'mapping_version',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'correlation_id' => 'string',
            'payload' => 'array',
            'attempts' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }
}
