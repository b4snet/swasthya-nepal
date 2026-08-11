<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rotating refresh-token record (SECURITY.md §4–5).
 *
 * Only the SHA-256 hash of the token is stored; the plaintext is returned to
 * the client exactly once at issuance. Rotation revokes the used token and
 * issues a successor in the same family; presenting a revoked token is
 * reuse detection and revokes the whole family.
 */
class RefreshToken extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'family_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'replaced_by',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
