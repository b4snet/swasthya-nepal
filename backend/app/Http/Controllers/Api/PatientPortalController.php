<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\GrantAccessRequest;
use App\Http\Requests\Portal\LoginRequest;
use App\Http\Requests\Portal\ProvisionAccountRequest;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientConsentRecord;
use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Services\PatientPortalService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient Portal (PRODUCT_REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * Two distinct surfaces, both STRICTLY self-only:
 *
 *  - Public + portal-authenticated (prefix /portal): login (identifier +
 *    password against a tenant disambiguated by organization code),
 *    logout, and read-only views of the patient's OWN appointments,
 *    results, and bills — every view requires an ACTIVE consent-bound
 *    grant for its scope (missing/revoked → the same generic 403). The
 *    patient identity is derived from the authenticated portal token by
 *    ResolvePortalContext, never from client input.
 *
 *  - Staff-managed (organizations/{organization}/patients/{patient}/portal
 *    and portal-accounts/...): provisioning accounts, issuing and revoking
 *    consent-bound grants, and disabling accounts. All gated by
 *    portal:manage; every action is audited with facts only.
 */
final class PatientPortalController extends Controller
{
    public function __construct(
        private readonly PatientPortalService $portal,
        private readonly AuditLogger $audit,
    ) {}

    // ─────────────────────────── Public / portal ───────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->portal->login(
            (string) $request->validated('organizationCode'),
            (string) $request->validated('identifier'),
            (string) $request->validated('password'),
            $request,
        );

        $this->audit->record(
            'portal.login',
            'portal_session',
            $result['session']->getKey(),
            ['facilityId' => $result['account']->facility_id],
            $request,
            tenantId: $result['account']->tenant_id,
            facilityId: $result['account']->facility_id,
            actorType: AuditEvent::ACTOR_PATIENT,
            actorEmail: $result['account']->login_identifier,
        );

        return Envelope::success([
            'token' => $result['token'],
            'tokenType' => 'Bearer',
            'expiresAt' => now()
                ->addMinutes((int) config('swasthya.auth.access_token_ttl_minutes'))
                ->toIso8601String(),
            'account' => $this->accountPayload($result['account']),
        ], [], [], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $account = $context->portalAccount;

        if ($account === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        $session = $account->sessions()
            ->orderByDesc('created_at')
            ->first();

        $this->portal->logout($account, (string) $request->bearerToken());

        $this->audit->record(
            'portal.logout',
            'portal_session',
            $session?->getKey(),
            [],
            $request,
        );

        return Envelope::success(['loggedOut' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        return Envelope::success([
            'account' => $this->accountPayload($account),
            'grants' => $this->portal->selfGrants($account),
        ]);
    }

    public function appointments(Request $request): JsonResponse
    {
        return Envelope::success(['appointments' => $this->portal->selfAppointments($this->currentAccount($request))]);
    }

    public function results(Request $request): JsonResponse
    {
        return Envelope::success(['results' => $this->portal->selfResults($this->currentAccount($request))]);
    }

    public function bills(Request $request): JsonResponse
    {
        return Envelope::success(['bills' => $this->portal->selfBills($this->currentAccount($request))]);
    }

    // ─────────────────────── PHR Endpoints ────────────────────────────

    public function medicalHistory(Request $request): JsonResponse
    {
        return Envelope::success($this->portal->selfMedicalHistory($this->currentAccount($request)));
    }

    public function medications(Request $request): JsonResponse
    {
        return Envelope::success(['medications' => $this->portal->selfMedications($this->currentAccount($request))]);
    }

    public function labResults(Request $request): JsonResponse
    {
        return Envelope::success(['results' => $this->portal->selfLabResults($this->currentAccount($request))]);
    }

    public function radiologyReports(Request $request): JsonResponse
    {
        return Envelope::success(['reports' => $this->portal->selfRadiologyReports($this->currentAccount($request))]);
    }

    public function prescriptions(Request $request): JsonResponse
    {
        return Envelope::success(['prescriptions' => $this->portal->selfPrescriptions($this->currentAccount($request))]);
    }

    public function documents(Request $request): JsonResponse
    {
        return Envelope::success(['documents' => $this->portal->selfDocuments($this->currentAccount($request))]);
    }

    public function referrals(Request $request): JsonResponse
    {
        return Envelope::success(['referrals' => $this->portal->selfReferrals($this->currentAccount($request))]);
    }

    public function immunizations(Request $request): JsonResponse
    {
        return Envelope::success(['immunizations' => $this->portal->selfImmunizations($this->currentAccount($request))]);
    }

    public function profile(Request $request): JsonResponse
    {
        return Envelope::success(['patient' => $this->portal->selfProfile($this->currentAccount($request))]);
    }

    // ─────────────────── Secure Messaging ─────────────────────────────

    public function messages(Request $request): JsonResponse
    {
        return Envelope::success(['messages' => $this->portal->selfMessages($this->currentAccount($request))]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipientStaffId' => 'required|uuid',
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'category' => 'nullable|string',
        ]);

        $account = $this->currentAccount($request);
        $message = $this->portal->sendMessage(
            $account,
            $validated['recipientStaffId'],
            $validated['subject'],
            $validated['body'],
            $validated['category'] ?? 'general',
        );

        $this->audit->record(
            'portal.message_sent',
            'secure_message',
            $message->getKey(),
            ['category' => $message->category, 'phi_safe' => $message->phi_safe],
            $request,
        );

        return Envelope::success(['message' => ['id' => $message->getKey(), 'status' => $message->status]], [], [], 201);
    }

    // ──────────────── Notification Preferences ────────────────────────

    public function notificationPreferences(Request $request): JsonResponse
    {
        return Envelope::success(['preferences' => $this->portal->selfNotificationPreferences($this->currentAccount($request))]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emailEnabled' => 'nullable|boolean',
            'smsEnabled' => 'nullable|boolean',
            'pushEnabled' => 'nullable|boolean',
            'appointmentReminders' => 'nullable|boolean',
            'resultNotifications' => 'nullable|boolean',
            'billingNotifications' => 'nullable|boolean',
            'messagingNotifications' => 'nullable|boolean',
            'marketingOptOut' => 'nullable|boolean',
            'preferredLanguage' => 'nullable|string',
            'timezone' => 'nullable|string',
        ]);

        $mapping = [];
        if (isset($validated['emailEnabled'])) {
            $mapping['email_enabled'] = $validated['emailEnabled'];
        }
        if (isset($validated['smsEnabled'])) {
            $mapping['sms_enabled'] = $validated['smsEnabled'];
        }
        if (isset($validated['pushEnabled'])) {
            $mapping['push_enabled'] = $validated['pushEnabled'];
        }
        if (isset($validated['appointmentReminders'])) {
            $mapping['appointment_reminders'] = $validated['appointmentReminders'];
        }
        if (isset($validated['resultNotifications'])) {
            $mapping['result_notifications'] = $validated['resultNotifications'];
        }
        if (isset($validated['billingNotifications'])) {
            $mapping['billing_notifications'] = $validated['billingNotifications'];
        }
        if (isset($validated['messagingNotifications'])) {
            $mapping['messaging_notifications'] = $validated['messagingNotifications'];
        }
        if (isset($validated['marketingOptOut'])) {
            $mapping['marketing_opt_out'] = $validated['marketingOptOut'];
        }
        if (isset($validated['preferredLanguage'])) {
            $mapping['preferred_language'] = $validated['preferredLanguage'];
        }
        if (isset($validated['timezone'])) {
            $mapping['timezone'] = $validated['timezone'];
        }

        $prefs = $this->portal->updateNotificationPreferences($this->currentAccount($request), $mapping);

        return Envelope::success(['preferences' => $prefs]);
    }

    // ──────────────────── Consent Management ──────────────────────────

    public function consentRecords(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $records = PatientConsentRecord::query()
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->getKey(),
                'dataCategory' => $r->data_category,
                'consentStatus' => $r->consent_status,
                'purpose' => $r->purpose,
                'grantedAt' => $r->granted_at?->toIso8601String(),
                'revokedAt' => $r->revoked_at?->toIso8601String(),
                'expiresAt' => $r->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Envelope::success(['consents' => $records]);
    }

    public function revokeConsent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consentId' => 'required|uuid',
            'reason' => 'nullable|string',
        ]);

        $account = $this->currentAccount($request);
        $consent = PatientConsentRecord::query()
            ->where('id', $validated['consentId'])
            ->where('patient_id', $account->patient_id)
            ->firstOrFail();

        $consent->update([
            'consent_status' => PatientConsentRecord::STATUS_REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => $validated['reason'] ?? null,
        ]);

        $this->audit->record(
            'portal.consent_revoked',
            'patient_consent_record',
            $consent->getKey(),
            ['dataCategory' => $consent->data_category],
            $request,
        );

        return Envelope::success(['consent' => ['id' => $consent->getKey(), 'status' => $consent->consent_status]]);
    }

    public function grants(Request $request): JsonResponse
    {
        return Envelope::success(['grants' => $this->portal->selfGrants($this->currentAccount($request))]);
    }

    /**
     * Patient self-service revocation — the patient can take a granted
     * scope away at any time (the DB partial unique then allows a future
     * staff re-grant). The grant MUST belong to the authenticated portal
     * account — any other grant resolves to the same generic 404 (no
     * existence leak across patients).
     */
    public function revokeGrant(PortalAccessGrant $grant, Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        if ($grant->portal_account_id !== $account->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Not found.', 404);
        }

        $revoked = $this->portal->revokeGrant($grant, staffId: null, byPatient: true);

        $this->audit->record(
            'portal.access_revoked',
            'portal_access_grant',
            $revoked->getKey(),
            ['scope' => $revoked->data_scope, 'byPatient' => true],
            $request,
        );

        return Envelope::success(['grant' => $this->grantPayload($revoked)]);
    }

    // ────────────────────────────── Staff ──────────────────────────────────

    /**
     * Provision a patient's portal account. The patient is resolved inside
     * the tenant context (route binding → RLS), so a cross-tenant or
     * cross-facility patient is a 404 before any logic runs. The account is
     * anchored to the PATIENT's facility (identity is facility-local).
     */
    public function provisionAccount(
        ProvisionAccountRequest $request,
        Organization $organization,
        Patient $patient,
    ): JsonResponse {
        AccessCheck::organization($organization->getKey(), write: true);
        AccessCheck::scoped($patient, write: true);

        $staffId = $this->currentStaffId(TenantContext::current());
        if ($staffId === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'A staff profile is required to provision portal accounts.', 403);
        }

        $account = $this->portal->provisionAccount(
            tenantId: $patient->tenant_id,
            facilityId: $patient->facility_id,
            patientId: $patient->getKey(),
            identifier: (string) $request->validated('loginIdentifier'),
            password: (string) $request->validated('password'),
            staffId: $staffId,
        );

        $this->audit->record(
            'portal.account_provisioned',
            'portal_account',
            $account->getKey(),
            ['facilityId' => $account->facility_id],
            $request,
        );

        return Envelope::success(['account' => $this->accountPayload($account)], [], [], 201);
    }

    public function grantAccess(GrantAccessRequest $request, PortalAccount $portalAccount): JsonResponse
    {
        AccessCheck::scoped($portalAccount, write: true);

        $staffId = $this->currentStaffId(TenantContext::current());
        if ($staffId === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'A staff profile is required to issue portal grants.', 403);
        }

        $grant = $this->portal->grantAccess(
            $portalAccount,
            (string) $request->validated('dataScope'),
            (string) $request->validated('purpose'),
            $staffId,
        );

        $this->audit->record(
            'portal.access_granted',
            'portal_access_grant',
            $grant->getKey(),
            ['scope' => $grant->data_scope],
            $request,
        );

        return Envelope::success(['grant' => $this->grantPayload($grant)], [], [], 201);
    }

    public function revokeGrantByStaff(PortalAccessGrant $grant, Request $request): JsonResponse
    {
        AccessCheck::scoped($grant, write: true);

        $staffId = $this->currentStaffId(TenantContext::current());

        $revoked = $this->portal->revokeGrant($grant, $staffId, byPatient: false);

        $this->audit->record(
            'portal.access_revoked',
            'portal_access_grant',
            $revoked->getKey(),
            ['scope' => $revoked->data_scope, 'byPatient' => false],
            $request,
        );

        return Envelope::success(['grant' => $this->grantPayload($revoked)]);
    }

    public function disableAccount(PortalAccount $portalAccount, Request $request): JsonResponse
    {
        AccessCheck::scoped($portalAccount, write: true);

        $staffId = $this->currentStaffId(TenantContext::current());
        if ($staffId === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'A staff profile is required to disable portal accounts.', 403);
        }

        $disabled = $this->portal->disableAccount($portalAccount, $staffId);

        $this->audit->record(
            'portal.account_disabled',
            'portal_account',
            $disabled->getKey(),
            ['facilityId' => $disabled->facility_id],
            $request,
        );

        return Envelope::success(['account' => $this->accountPayload($disabled)]);
    }

    // ────────────────────────────── Helpers ────────────────────────────────

    private function currentAccount(Request $request): PortalAccount
    {
        $account = TenantContext::current()->portalAccount
            ?? ($request->user() instanceof PortalAccount ? $request->user() : null);

        if ($account === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(PortalAccount $account): array
    {
        return [
            'id' => $account->getKey(),
            'patientId' => $account->patient_id,
            'loginIdentifier' => $account->login_identifier,
            'status' => $account->status,
            'mfaEnabled' => $account->mfa_enabled,
            'lastLoginAt' => $account->last_login_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grantPayload(PortalAccessGrant $grant): array
    {
        return [
            'id' => $grant->getKey(),
            'scope' => $grant->data_scope,
            'purpose' => $grant->purpose,
            'status' => $grant->status,
            'grantedAt' => $grant->granted_at->toIso8601String(),
            'revokedAt' => $grant->revoked_at?->toIso8601String(),
            'revokedByPatient' => $grant->revoked_by_patient,
        ];
    }

    private function currentStaffId(TenantContext $context): ?string
    {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }
}
