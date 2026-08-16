<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\OauthPartnerTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued OAuth2 partner access token (INTEROPERABILITY.md §11): the
 * sha256 hash of the bearer token (the token itself is returned once and
 * never stored), the scopes granted on THIS token, expiry, revocation, and
 * last use. Tenant-scoped, RLS on + FORCED.
 */
class OauthPartnerToken extends Model
{
    /** @use HasFactory<OauthPartnerTokenFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'oauth_partner_id',
        'token_hash',
        'scopes',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OauthPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(OauthPartner::class, 'oauth_partner_id');
    }
}
