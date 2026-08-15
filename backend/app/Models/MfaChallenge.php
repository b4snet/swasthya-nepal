<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MfaChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, short-lived MFA login challenge (SECURITY.md §3). Only the
 * SHA-256 hash of the challenge id is stored; the plaintext travels to the
 * client exactly once. Challenges are consumed on use and expire after five
 * minutes.
 */
class MfaChallenge extends Model
{
    /** @use HasFactory<MfaChallengeFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'challenge_hash',
        'expires_at',
        'consumed_at',
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
            'consumed_at' => 'datetime',
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
