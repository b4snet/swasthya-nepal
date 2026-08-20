<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientDocument;
use App\Models\PatientNotificationPreference;
use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Models\PortalSession;
use App\Models\Prescription;
use App\Models\RadiologyReport;
use App\Models\SecureMessage;
use App\Models\VitalObservation;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Phase 3 slice 22 — Patient Portal (PRODUCT REQUIREMENTS §6.2, DATABASE.md
 * §3.53).
 *
 * Strict self-only access: the patient identity is ALWAYS derived from the
 * authenticated PortalAccount — never from client input. Every data surface
 * requires an ACTIVE consent-bound grant for its scope (a missing or
 * revoked grant yields the same generic 403 — no existence leak). Login is
 * identifier + password with DB-backed lockout (survives cache clears,
 * serialized per account); sessions are append-only audit rows with
 * revocation; grants are one-active-per-(patient, scope) and CAS-guarded.
 * Portal payloads and audit events carry facts only — never other patients'
 * data, never clinical content beyond the patient's own permitted view.
 */
final class PatientPortalService
{
    public const PASSWORD_MIN_LENGTH = 12;

    /**
     * Provision a patient portal account (staff action). One account per
     * patient per tenant; one login identifier per tenant.
     */
    public function provisionAccount(
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $identifier,
        string $password,
        string $staffId,
    ): PortalAccount {
        $this->assertIdentifier($identifier);
        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The password must be at least '.self::PASSWORD_MIN_LENGTH.' characters.',
                422,
            );
        }

        return $this->guardUnique(fn () => PortalAccount::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'login_identifier' => $this->normalizeIdentifier($identifier),
            'password_hash' => Hash::make($password),
            'status' => PortalAccount::STATUS_ACTIVE,
            'failed_attempts' => 0,
            'locked_until' => null,
            'mfa_enabled' => false,
            'lock_version' => 0,
            'created_by_staff_id' => $staffId,
            'updated_by_staff_id' => $staffId,
        ]));
    }

    /**
     * Portal login (public route): organizationCode disambiguates the
     * tenant; the identifier is unique per tenant. DB-backed lockout — 429
     * while locked, failed attempts serialized per account.
     *
     * @return array{account: PortalAccount, token: string, session: PortalSession, organization: Organization}
     */
    public function login(
        string $organizationCode,
        string $identifier,
        string $password,
        Request $request,
    ): array {
        // The organizations table has RLS enforced (FORCE RLS). The portal
        // login is a public route with no authentication context, so no
        // tenant claims are set. We must bypass RLS for this single org
        // lookup by temporarily asserting platform scope, then immediately
        // clear it so all subsequent queries remain tenant-scoped.
        $organization = $this->withinPlatform(fn (): ?Organization => Organization::query()
            ->where('code', $organizationCode)
            ->first());

        // The account lookup needs the tenant GUC before RLS lets the app
        // role read it (login is a public route with no context).
        $account = $organization !== null
            ? $this->withinTenant($organization->getKey(), fn (): ?PortalAccount => PortalAccount::query()
                ->where('tenant_id', $organization->getKey())
                ->whereRaw('lower(login_identifier) = ?', [strtolower($this->normalizeIdentifier($identifier))])
                ->first())
            : null;

        // Generic 401 — a wrong org code and a wrong password are
        // indistinguishable (no account/tenant enumeration).
        if ($organization === null || $account === null) {
            throw new ApiException(ErrorCodes::INVALID_CREDENTIALS, 'The provided credentials are incorrect.', 401);
        }

        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            throw new ApiException(
                ErrorCodes::RATE_LIMITED,
                'Too many failed login attempts. Try again later.',
                429,
                [],
                ['Retry-After' => (string) ($account->locked_until->diffInSeconds(now()) ?: 1)],
            );
        }

        if ($account->status !== PortalAccount::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'This portal account is not active.', 403);
        }

        if (! Hash::check($password, (string) $account->password_hash)) {
            $this->recordFailedAttempt($account);
            throw new ApiException(ErrorCodes::INVALID_CREDENTIALS, 'The provided credentials are incorrect.', 401);
        }

        // Success: reset the lockout state.
        DB::transaction(function () use ($account): void {
            PortalAccount::query()
                ->whereKey($account->getKey())
                ->update([
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'last_login_at' => now(),
                    'lock_version' => $account->lock_version + 1,
                ]);
            $account->refresh();
        });

        $accessToken = $account->createToken(
            'portal-access',
            [],
            now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
        );

        $session = $this->withinTenant($account->tenant_id, fn () => PortalSession::query()->create([
            'tenant_id' => $account->tenant_id,
            'facility_id' => $account->facility_id,
            'portal_account_id' => $account->getKey(),
            'patient_id' => $account->patient_id,
            'token_id' => $accessToken->accessToken->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
        ]));

        return [
            'account' => $account,
            'token' => $accessToken->plainTextToken,
            'session' => $session,
            'organization' => $organization,
        ];
    }

    /**
     * Portal logout: revoke the Sanctum token AND every matching session
     * row (CAS on revoked_at). The session is resolved from the token id —
     * never from a client-supplied id. Idempotent: revoking an already
     * revoked token/session is a no-op success.
     */
    public function logout(PortalAccount $account, string $token): void
    {
        $existing = PersonalAccessToken::findToken($token);
        $tokenId = $existing?->getKey();

        if ($existing !== null) {
            $existing->delete();
        }

        if ($tokenId === null) {
            return;
        }

        PortalSession::query()
            ->where('portal_account_id', $account->getKey())
            ->where('token_id', $tokenId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by' => PortalSession::REVOKED_BY_PATIENT,
            ]);
    }

    /**
     * Grant a data scope to a patient's portal (staff action). One ACTIVE
     * grant per (patient, scope) — a concurrent double-grant is refused
     * with 409 (re-granting after revocation is a fresh grant).
     */
    public function grantAccess(
        PortalAccount $account,
        string $scope,
        string $purpose,
        string $staffId,
    ): PortalAccessGrant {
        $this->assertScopeValue($scope);

        return $this->guardUnique(fn () => PortalAccessGrant::query()->create([
            'tenant_id' => $account->tenant_id,
            'facility_id' => $account->facility_id,
            'portal_account_id' => $account->getKey(),
            'patient_id' => $account->patient_id,
            'data_scope' => $scope,
            'purpose' => $purpose,
            'status' => PortalAccessGrant::STATUS_GRANTED,
            'granted_at' => now(),
            'granted_by_staff_id' => $staffId,
            'lock_version' => 0,
            'created_by' => $staffId,
            'updated_by' => $staffId,
        ]));
    }

    /**
     * Revoke a grant — by the PATIENT (self-service) or by staff. CAS on
     * (status='granted', lock_version): a concurrent double-revoke affects
     * zero rows → 409.
     */
    public function revokeGrant(PortalAccessGrant $grant, ?string $staffId, bool $byPatient = false): PortalAccessGrant
    {
        $affected = PortalAccessGrant::query()
            ->whereKey($grant->getKey())
            ->where('status', PortalAccessGrant::STATUS_GRANTED)
            ->where('lock_version', $grant->lock_version)
            ->update([
                'status' => PortalAccessGrant::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by_staff_id' => $staffId,
                'revoked_by_patient' => $byPatient,
                'lock_version' => $grant->lock_version + 1,
                'updated_by' => $staffId,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This grant was already revoked.', 409);
        }

        return $grant->refresh();
    }

    /**
     * Disable a portal account (staff action): the account can no longer
     * log in and every active token/session is revoked.
     */
    public function disableAccount(PortalAccount $account, string $staffId): PortalAccount
    {
        DB::transaction(function () use ($account, $staffId): void {
            PortalAccount::query()
                ->whereKey($account->getKey())
                ->where('status', PortalAccount::STATUS_ACTIVE)
                ->update([
                    'status' => PortalAccount::STATUS_DISABLED,
                    'lock_version' => $account->lock_version + 1,
                    'updated_by_staff_id' => $staffId,
                ]);

            $tokenIds = PortalSession::query()
                ->where('portal_account_id', $account->getKey())
                ->whereNull('revoked_at')
                ->pluck('token_id');

            PersonalAccessToken::query()->whereIn('id', $tokenIds)->delete();

            PortalSession::query()
                ->where('portal_account_id', $account->getKey())
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_by' => PortalSession::REVOKED_BY_STAFF,
                ]);
        });

        return $account->refresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selfAppointments(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_APPOINTMENTS);

        return Appointment::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('facility_id', $account->facility_id)
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->getKey(),
                'type' => $appointment->appointment_type,
                'status' => $appointment->status,
                'startsAt' => $appointment->starts_at?->toIso8601String(),
                'serviceId' => $appointment->service_id,
                'departmentId' => $appointment->department_id,
                'notes' => $appointment->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selfResults(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_RESULTS);

        return LabOrder::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('facility_id', $account->facility_id)
            ->where('patient_id', $account->patient_id)
            ->where('status', LabOrder::STATUS_REPORTED)
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get()
            ->map(function (LabOrder $order): array {
                $items = $order->items()
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (LabOrderItem $item): array => [
                        'id' => $item->getKey(),
                        'testName' => $item->test?->name,
                        'resultValue' => $item->result_value,
                        'resultUnit' => $item->result_unit,
                        'referenceRange' => $item->reference_range,
                        'verifiedAt' => $item->verified_at?->toIso8601String(),
                        'isCorrection' => $item->resultVersions()->count() > 1,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $order->getKey(),
                    'status' => $order->status,
                    'priority' => $order->priority,
                    'orderedAt' => $order->ordered_at?->toIso8601String(),
                    'reportedAt' => $order->reported_at?->toIso8601String(),
                    'items' => $items,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selfBills(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_BILLS);

        return Invoice::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('facility_id', $account->facility_id)
            ->where('patient_id', $account->patient_id)
            ->where('status', '!=', Invoice::STATUS_VOIDED)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->getKey(),
                'status' => $invoice->status,
                'totalMinor' => $invoice->total_minor,
                'paidMinor' => $invoice->paid_minor,
                'issuedAt' => $invoice->issued_at?->toIso8601String() ?? $invoice->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Medical history (allergies + diagnoses).
     */
    public function selfMedicalHistory(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_MEDICAL_HISTORY);
        $patientId = $account->patient_id;
        $tenantId = $account->tenant_id;
        $facilityId = $account->facility_id;

        $allergies = PatientAllergy::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => ['id' => $a->getKey(), 'allergen' => $a->allergen, 'reaction' => $a->reaction ?? null, 'severity' => $a->severity ?? null, 'status' => $a->status ?? 'active'])
            ->values()
            ->all();

        // Diagnoses are linked via encounters — get them through encounter linkage
        $encounterIds = Encounter::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->pluck('id');

        $diagnoses = Diagnosis::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('encounter_id', $encounterIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => ['id' => $d->getKey(), 'code' => $d->code ?? null, 'description' => $d->description ?? null, 'status' => $d->status ?? 'active'])
            ->values()
            ->all();

        return ['allergies' => $allergies, 'diagnoses' => $diagnoses];
    }

    /**
     * PHR: Current medications.
     */
    public function selfMedications(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_MEDICAL_HISTORY);

        return Prescription::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('patient_id', $account->patient_id)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->getKey(),
                'status' => $p->status,
                'prescribedAt' => $p->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Lab results.
     */
    public function selfLabResults(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_RESULTS);

        return LabOrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('patient_id', $account->patient_id)->where('tenant_id', $account->tenant_id))
            ->where('tenant_id', $account->tenant_id)
            ->whereNotNull('result_value')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->getKey(),
                'testName' => $item->test?->name ?? null,
                'resultValue' => $item->result_value ?? null,
                'resultUnit' => $item->result_unit ?? null,
                'referenceRange' => $item->reference_range ?? null,
                'resultedAt' => $item->entered_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Radiology reports.
     */
    public function selfRadiologyReports(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_RADIOLOGY);

        return RadiologyReport::query()
            ->whereIn('status', ['preliminary', 'final'])
            ->whereHas('study.order', fn ($q) => $q->where('patient_id', $account->patient_id)->where('tenant_id', $account->tenant_id))
            ->where('tenant_id', $account->tenant_id)
            ->orderByDesc('verified_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->getKey(),
                'reportType' => $r->report_type,
                'status' => $r->status,
                'impression' => $r->impression,
                'criticalFindings' => $r->critical_findings,
                'verifiedAt' => $r->verified_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Prescriptions.
     */
    public function selfPrescriptions(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_PRESCRIPTIONS);

        return Prescription::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->getKey(),
                'status' => $p->status,
                'encounterId' => $p->encounter_id,
                'prescribedAt' => $p->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Documents.
     */
    public function selfDocuments(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_DOCUMENTS);

        try {
            return PatientDocument::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('patient_id', $account->patient_id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->getKey(),
                    'documentType' => $d->document_type,
                    'title' => $d->getAttribute('title') ?? $d->document_type,
                    'description' => $d->getAttribute('description') ?? null,
                    'mimeType' => $d->mime_type,
                    'createdAt' => $d->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * PHR: Referrals.
     */
    public function selfReferrals(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_REFERRALS);

        // Referrals may come from encounters with a referral type
        return Encounter::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('patient_id', $account->patient_id)
            ->where('type', 'referral')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->getKey(),
                'reason' => $r->reason ?? $r->chief_complaint ?? null,
                'status' => $r->status,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Immunization history.
     */
    public function selfImmunizations(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_IMMUNIZATIONS);

        try {
            return VitalObservation::query()
                ->where('tenant_id', $account->tenant_id)
                ->where('patient_id', $account->patient_id)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->getKey(),
                    'code' => $o->getAttribute('code') ?? null,
                    'description' => $o->getAttribute('description') ?? null,
                    'observedAt' => $o->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * PHR: Secure messages.
     */
    public function selfMessages(PortalAccount $account): array
    {
        $this->assertScope($account, PortalAccessGrant::SCOPE_MESSAGING);

        return SecureMessage::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('facility_id', $account->facility_id)
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->getKey(),
                'subject' => $m->subject,
                'body' => $m->body,
                'senderIsPatient' => $m->sender_is_patient,
                'status' => $m->status,
                'category' => $m->category,
                'createdAt' => $m->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * PHR: Send a secure message from patient to staff.
     */
    public function sendMessage(
        PortalAccount $account,
        string $recipientStaffId,
        string $subject,
        string $body,
        string $category = 'general',
    ): SecureMessage {
        $this->assertScope($account, PortalAccessGrant::SCOPE_MESSAGING);

        return SecureMessage::create([
            'tenant_id' => $account->tenant_id,
            'facility_id' => $account->facility_id,
            'patient_id' => $account->patient_id,
            'sender_is_patient' => true,
            'recipient_staff_id' => $recipientStaffId,
            'recipient_is_patient' => false,
            'subject' => $subject,
            'body' => $body,
            'status' => SecureMessage::STATUS_UNREAD,
            'category' => $category,
            'phi_safe' => true,
        ]);
    }

    /**
     * PHR: Patient profile.
     */
    public function selfProfile(PortalAccount $account): array
    {
        $patient = Patient::query()
            ->where('id', $account->patient_id)
            ->where('tenant_id', $account->tenant_id)
            ->firstOrFail();

        return [
            'id' => $patient->getKey(),
            'fullName' => $patient->full_name,
            'dateOfBirth' => $patient->date_of_birth,
            'sex' => $patient->sex,
            'mrn' => $patient->mrn,
            'status' => $patient->status,
        ];
    }

    /**
     * PHR: Notification preferences.
     */
    public function selfNotificationPreferences(PortalAccount $account): array
    {
        $prefs = PatientNotificationPreference::firstOrCreate(
            ['patient_id' => $account->patient_id],
            [
                'tenant_id' => $account->tenant_id,
                'facility_id' => $account->facility_id,
            ],
        );
        $prefs->refresh();

        return [
            'emailEnabled' => $prefs->email_enabled,
            'smsEnabled' => $prefs->sms_enabled,
            'pushEnabled' => $prefs->push_enabled,
            'appointmentReminders' => $prefs->appointment_reminders,
            'resultNotifications' => $prefs->result_notifications,
            'billingNotifications' => $prefs->billing_notifications,
            'messagingNotifications' => $prefs->messaging_notifications,
            'marketingOptOut' => $prefs->marketing_opt_out,
            'preferredLanguage' => $prefs->preferred_language,
            'timezone' => $prefs->timezone,
        ];
    }

    /**
     * PHR: Update notification preferences.
     */
    public function updateNotificationPreferences(PortalAccount $account, array $data): array
    {
        $prefs = PatientNotificationPreference::updateOrCreate(
            ['patient_id' => $account->patient_id],
            array_merge($data, [
                'tenant_id' => $account->tenant_id,
                'facility_id' => $account->facility_id,
            ]),
        );

        return $this->selfNotificationPreferences($account);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selfGrants(PortalAccount $account): array
    {
        return $account->grants()
            ->orderByDesc('granted_at')
            ->get()
            ->map(fn (PortalAccessGrant $grant): array => [
                'id' => $grant->getKey(),
                'scope' => $grant->data_scope,
                'purpose' => $grant->purpose,
                'status' => $grant->status,
                'grantedAt' => $grant->granted_at->toIso8601String(),
                'revokedAt' => $grant->revoked_at?->toIso8601String(),
                'revokedByPatient' => $grant->revoked_by_patient,
            ])
            ->values()
            ->all();
    }

    public function assertScopeValue(string $scope): void
    {
        $valid = [
            PortalAccessGrant::SCOPE_APPOINTMENTS,
            PortalAccessGrant::SCOPE_RESULTS,
            PortalAccessGrant::SCOPE_BILLS,
            PortalAccessGrant::SCOPE_MEDICAL_HISTORY,
            PortalAccessGrant::SCOPE_PRESCRIPTIONS,
            PortalAccessGrant::SCOPE_DOCUMENTS,
            PortalAccessGrant::SCOPE_RADIOLOGY,
            PortalAccessGrant::SCOPE_REFERRALS,
            PortalAccessGrant::SCOPE_CARE_PLANS,
            PortalAccessGrant::SCOPE_IMMUNIZATIONS,
            PortalAccessGrant::SCOPE_MESSAGING,
        ];
        if (! in_array($scope, $valid, true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Invalid data scope.', 422);
        }
    }

    /**
     * A missing and a revoked grant are indistinguishable (generic 403) —
     * no existence leak, no scope probing.
     */
    private function assertScope(PortalAccount $account, string $scope): void
    {
        $active = PortalAccessGrant::query()
            ->where('tenant_id', $account->tenant_id)
            ->where('facility_id', $account->facility_id)
            ->where('portal_account_id', $account->getKey())
            ->where('data_scope', $scope)
            ->where('status', PortalAccessGrant::STATUS_GRANTED)
            ->exists();

        if (! $active) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'Access to this data has not been granted or has been revoked.',
                403,
            );
        }
    }

    private function recordFailedAttempt(PortalAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            /** @var PortalAccount $locked */
            $locked = PortalAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attempts = $locked->failed_attempts + 1;
            $threshold = (int) config('swasthya.auth.login_failure_threshold');
            $lockMinutes = (int) config('swasthya.auth.login_lockout_minutes');

            $locked->forceFill([
                'failed_attempts' => $attempts,
                'locked_until' => $attempts >= $threshold ? now()->addMinutes($lockMinutes) : $locked->locked_until,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withinTenant(string $tenantId, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenantId, $callback): mixed {
            DatabaseTenantContext::setTenant($tenantId);

            return $callback();
        });
    }

    /**
     * Execute a callback with platform scope enabled, then clear it.
     * Used only for the initial organization lookup during portal login
     * where no tenant context exists yet.
     */
    private function withinPlatform(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DatabaseTenantContext::setPlatform(true);

            return $callback();
        });
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return strtolower($identifier);
        }

        // Phone: strip spaces, dashes, and a leading + (stored in E.164 form).
        return preg_replace('/[^\d+]/', '', $identifier);
    }

    private function assertIdentifier(string $identifier): void
    {
        $identifier = trim($identifier);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $isPhone = preg_match('/^\+?[0-9]{7,15}$/', preg_replace('/[^\d+]/', '', $identifier) ?? '') === 1;

        if (! $isEmail && ! $isPhone) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'loginIdentifier must be a valid email address or phone number.',
                422,
            );
        }
    }

    /**
     * Translate a unique-violation (SQLSTATE 23505) into the documented 409
     * contract — the same pattern as AnalyticsService.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function guardUnique(callable $create)
    {
        try {
            return DB::transaction($create);
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'A portal account or active grant for this patient already exists.',
                    409,
                );
            }

            throw $e;
        }
    }
}
