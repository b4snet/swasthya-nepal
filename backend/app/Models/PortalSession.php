<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PortalSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One portal login's session record (DATABASE.md §3.53): the Sanctum token
 * row id (never the token value — tokens are hashed at rest), expiry, IP,
 * user agent, and revocation. Every portal login and logout is audited
 * through these rows; revocation is CAS on revoked_at (a concurrent logout
 * can never double-revoke). Tenant+facility scoped, RLS on + FORCED.
 */
class PortalSession extends Model
{
    /** @use HasFactory<PortalSessionFactory> */
    use HasFactory, HasUuid;

    public const REVOKED_BY_PATIENT = 'patient';

    public const REVOKED_BY_STAFF = 'staff';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'portal_account_id',
        'patient_id',
        'token_id',
        'ip_address',
        'user_agent',
        'expires_at',
        'revoked_at',
        'revoked_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PortalAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
