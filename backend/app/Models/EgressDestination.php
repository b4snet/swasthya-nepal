<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\EgressDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved outbound destination (host:port) for a tenant (DATABASE.md
 * §3.42, INTEROPERABILITY.md §11, SECURITY.md §22 — the SSRF guard).
 * An adapter must pass `assertAllowed()` before ANY outbound call; a host
 * that is not allowlisted is refused even if a provider credential exists.
 * Tenant-scoped, RLS on + FORCED.
 */
class EgressDestination extends Model
{
    /** @use HasFactory<EgressDestinationFactory> */
    use HasFactory, HasUuid;

    /**
     * The allowlist table (the model name is deliberately not table-derived).
     */
    protected $table = 'egress_allowlist';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'integration_id',
        'host',
        'port',
        'purpose',
        'is_active',
        'created_by_staff_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'is_active' => 'boolean',
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
