<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Portal invitation (Phase 82): a secure, single-use token sent to the
 * patient's email/phone for portal account activation. The patient clicks
 * the link, sets their own password — staff never chooses the password.
 *
 * Tokens are cryptographically random (32 bytes = 256 bits of entropy),
 * expire after 72 hours, and are invalidated after use or revocation.
 */
class PortalInvitation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const TOKEN_EXPIRY_HOURS = 72;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'portal_account_id',
        'patient_id',
        'invitation_token',
        'email',
        'phone',
        'status',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'sent_by_staff_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Generate a new invitation for a portal account.
     */
    public static function createInvitation(
        string $tenantId,
        string $facilityId,
        string $portalAccountId,
        string $patientId,
        ?string $email = null,
        ?string $phone = null,
        ?string $sentByStaffId = null,
    ): self {
        return self::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'portal_account_id' => $portalAccountId,
            'patient_id' => $patientId,
            'invitation_token' => Str::random(64),
            'email' => $email,
            'phone' => $phone,
            'status' => self::STATUS_PENDING,
            'expires_at' => now()->addHours(self::TOKEN_EXPIRY_HOURS),
            'sent_by_staff_id' => $sentByStaffId,
        ]);
    }

    /**
     * Check if the invitation is valid (pending and not expired).
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Mark the invitation as accepted.
     */
    public function markAccepted(): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Mark the invitation as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Mark the invitation as revoked.
     */
    public function markRevoked(): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    /**
     * Find a valid invitation by token.
     */
    public static function findValidToken(string $token): ?self
    {
        return self::query()
            ->where('invitation_token', $token)
            ->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Present as API-safe array.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status,
            'email' => $this->email,
            'phone' => $this->phone,
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'acceptedAt' => $this->accepted_at?->toIso8601String(),
            'revokedAt' => $this->revoked_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
