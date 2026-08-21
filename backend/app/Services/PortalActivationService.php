<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PortalAccount;
use App\Models\PortalInvitation;
use App\Models\PortalSession;
use App\Support\AuditLogger;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Secure portal activation (Phase 82): invitation-based account creation,
 * self-service password setup, and password reset.
 *
 * Key security properties:
 * - Staff never chooses the patient's password
 * - Invitation tokens are single-use, time-limited (72h)
 * - Password setup requires a valid invitation token
 * - Password reset requires identity verification (email/phone + org code)
 * - Patient identity is derived from the invitation, never from client input
 */
final class PortalActivationService
{
    public function __construct(
        private readonly PatientPortalService $portal,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Send an invitation for a portal account (staff action).
     * Creates a pending invitation with a secure token.
     *
     * @return array{invitationId: string, token: string, expiresAt: string}
     */
    public function sendInvitation(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $staffId,
        ?string $email = null,
        ?string $phone = null,
    ): array {
        // Verify the portal account exists and is in the right state
        $account = PortalAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->first();

        if ($account === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Portal account not found. Provision the account first.', 404);
        }

        if ($account->status === PortalAccount::STATUS_DISABLED) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'This portal account is disabled.', 403);
        }

        // Invalidate any previous pending invitations for this account
        PortalInvitation::query()
            ->where('portal_account_id', $account->getKey())
            ->where('status', PortalInvitation::STATUS_PENDING)
            ->update(['status' => PortalInvitation::STATUS_REVOKED, 'revoked_at' => now()]);

        // Create new invitation
        $invitation = PortalInvitation::createInvitation(
            $tenantId,
            $facilityId,
            $account->getKey(),
            $patientId,
            $email,
            $phone,
            $staffId,
        );

        $this->audit->record(
            'portal.invitation.sent',
            'portal_invitations',
            $invitation->getKey(),
            [
                'patientId' => $patientId,
                'email' => $email,
                'phone' => $phone,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ],
            null,
            tenantId: $tenantId,
            facilityId: $facilityId,
        );

        return [
            'invitationId' => $invitation->getKey(),
            'token' => $invitation->invitation_token,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ];
    }

    /**
     * Verify an invitation token (public, no auth required).
     * Returns the invitation details if valid, or throws if invalid/expired.
     */
    public function verifyToken(string $token): array
    {
        $invitation = PortalInvitation::findValidToken($token);

        if ($invitation === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Invalid or expired invitation link.', 404);
        }

        // Get patient info for display (minimum necessary)
        $patient = Patient::query()
            ->where('id', $invitation->patient_id)
            ->where('tenant_id', $invitation->tenant_id)
            ->first();

        return [
            'invitationId' => $invitation->getKey(),
            'patientName' => $patient?->full_name ?? 'Patient',
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'email' => $invitation->email,
        ];
    }

    /**
     * Activate a portal account with a password (public, no auth required).
     * The patient sets their own password — staff never chooses it.
     *
     * @return array{token: string, session: PortalSession}
     */
    public function activate(string $token, string $password, ?string $requestIp = null, ?string $requestUserAgent = null): array
    {
        $invitation = PortalInvitation::findValidToken($token);

        if ($invitation === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Invalid or expired invitation link.', 404);
        }

        // Validate password
        $minLength = PatientPortalService::PASSWORD_MIN_LENGTH;
        if (mb_strlen($password) < $minLength) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, "Password must be at least {$minLength} characters.", 422);
        }

        // Set the tenant context for the account update
        $account = PortalAccount::query()
            ->where('tenant_id', $invitation->tenant_id)
            ->where('id', $invitation->portal_account_id)
            ->first();

        if ($account === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Portal account not found.', 404);
        }

        if ($account->status !== PortalAccount::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'This portal account is not active.', 403);
        }

        DB::transaction(function () use ($invitation, $account, $password): void {
            // Update password
            PortalAccount::query()
                ->whereKey($account->getKey())
                ->update([
                    'password_hash' => Hash::make($password),
                    'lock_version' => $account->lock_version + 1,
                ]);

            // Mark invitation as accepted
            $invitation->markAccepted();

            // Audit
            $this->audit->record(
                'portal.account.activated',
                'portal_accounts',
                $account->getKey(),
                ['patientId' => $account->patient_id],
                null,
                tenantId: $account->tenant_id,
                facilityId: $account->facility_id,
            );
        });

        // Auto-login after activation
        $account->refresh();
        $accessToken = $account->createToken(
            'portal-access',
            [],
            now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
        );

        $session = DB::transaction(function () use ($account, $accessToken, $requestIp, $requestUserAgent) {
            DatabaseTenantContext::setTenant($account->tenant_id);

            return PortalSession::query()->create([
                'tenant_id' => $account->tenant_id,
                'facility_id' => $account->facility_id,
                'portal_account_id' => $account->getKey(),
                'patient_id' => $account->patient_id,
                'token_id' => $accessToken->accessToken->getKey(),
                'ip_address' => $requestIp,
                'user_agent' => $requestUserAgent,
                'expires_at' => now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
            ]);
        });

        return [
            'token' => $accessToken->plainTextToken,
            'session' => $session,
        ];
    }

    /**
     * Request a password reset (public, no auth required).
     * Sends a reset token to the patient's registered contact.
     * Returns a generic success regardless of whether the account exists.
     */
    public function requestPasswordReset(
        string $organizationCode,
        string $identifier,
    ): array {
        // Find org (bypass RLS)
        $organization = DB::transaction(function () use ($organizationCode) {
            DatabaseTenantContext::setPlatform(true);

            return Organization::query()
                ->where('code', $organizationCode)
                ->first();
        });

        if ($organization === null) {
            // Generic response — don't reveal org existence
            return ['message' => 'If an account exists, a reset link has been sent.'];
        }

        // Find account within tenant
        $account = DB::transaction(function () use ($organization, $identifier) {
            DatabaseTenantContext::setTenant($organization->getKey());

            return PortalAccount::query()
                ->where('tenant_id', $organization->getKey())
                ->whereRaw('lower(login_identifier) = ?', [strtolower($identifier)])
                ->first();
        });

        if ($account !== null && $account->status === PortalAccount::STATUS_ACTIVE) {
            // Create a reset invitation (same mechanism, shorter expiry)
            $invitation = PortalInvitation::createInvitation(
                $account->tenant_id,
                $account->facility_id,
                $account->getKey(),
                $account->patient_id,
                is_numeric($account->login_identifier) ? null : $account->login_identifier,
                is_numeric($account->login_identifier) ? $account->login_identifier : null,
            );

            $this->audit->record(
                'portal.password_reset_requested',
                'portal_invitations',
                $invitation->getKey(),
                ['patientId' => $account->patient_id],
                null,
                tenantId: $account->tenant_id,
            );
        }

        // Always return generic message — no account enumeration
        return ['message' => 'If an account exists, a reset link has been sent.'];
    }
}
