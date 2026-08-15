<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

/**
 * The global identity account (DATABASE.md §3.4).
 *
 * Deliberately global — no tenant_id. Tenancy is expressed through
 * role_assignments (user × role × tenant × facility scope), never on the
 * user row (DATABASE.md §1.3). A user with no active assignments has no
 * access (TENANCY.md §0).
 *
 * Credentials: argon2id password hash; access tokens via Sanctum (hashed at
 * rest, short-lived); rotating refresh tokens (SECURITY.md §4–5). MFA
 * columns are schema readiness for the MFA phase (MASTER_RULES.md §7.3).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password_hash',
        'status',
        'auth_subject_id',
        'mfa_secret_encrypted',
        'mfa_recovery_codes_encrypted',
        'last_login_at',
        'password_changed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'mfa_secret_encrypted',
        'mfa_recovery_codes_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'mfa_recovery_codes_encrypted' => 'array',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Staff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'user_id');
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return HasMany<RefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /* ------------------------------------------------------------------ */
    /* MFA (PROGRAM PHASE 2, SECURITY.md §3) */
    /* ------------------------------------------------------------------ */

    /**
     * Fully enrolled AND activated: a stored (encrypted) secret AND a
     * non-empty set of recovery-code hashes. Enrollment alone (secret set,
     * no recovery hashes) does not enable the requirement.
     */
    public function mfaEnabled(): bool
    {
        return $this->mfa_secret_encrypted !== null
            && is_array($this->mfa_recovery_codes_encrypted)
            && count($this->mfa_recovery_codes_encrypted) > 0;
    }

    public function mfaSecret(): ?string
    {
        return $this->mfa_secret_encrypted !== null
            ? Crypt::decryptString($this->mfa_secret_encrypted)
            : null;
    }

    public function setMfaSecret(string $secret): void
    {
        $this->forceFill(['mfa_secret_encrypted' => Crypt::encryptString($secret)])->save();
    }

    /**
     * @return list<string>
     */
    public function mfaRecoveryHashes(): array
    {
        $codes = $this->mfa_recovery_codes_encrypted;

        return is_array($codes) ? array_values($codes) : [];
    }

    /**
     * @param  list<string>  $hashes
     */
    public function setMfaRecoveryHashes(array $hashes): void
    {
        $this->forceFill(['mfa_recovery_codes_encrypted' => $hashes])->save();
    }

    public function clearMfa(): void
    {
        $this->forceFill([
            'mfa_secret_encrypted' => null,
            'mfa_recovery_codes_encrypted' => null,
        ])->save();
    }
}
