<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\CaptureConsentRequest;
use App\Http\Requests\Patient\RevokeConsentRequest;
use App\Models\Consent;
use App\Models\Patient;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient consents (DATABASE.md §3.39): versioned, auditable. One active
 * consent per (patient, type); a new capture expires the prior version;
 * revocation is a state change with a required reason. The patient's
 * consent_summary is recomputed after every change.
 */
final class ConsentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
    ) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $consents = $patient->consents()
            ->orderByDesc('version')
            ->get()
            ->map(fn (Consent $consent): array => self::present($consent))
            ->values();

        return Envelope::success(data: $consents, request: $request);
    }

    public function store(CaptureConsentRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();
        $type = (string) $request->validated('consentType');

        $consent = DB::transaction(function () use ($request, $patient, $context, $type): Consent {
            // A new capture expires the prior active version (one active per type).
            $patient->consents()
                ->where('consent_type', $type)
                ->where('status', Consent::STATUS_ACTIVE)
                ->update(['status' => Consent::STATUS_EXPIRED]);

            $nextVersion = (int) ($patient->consents()
                ->where('consent_type', $type)
                ->max('version') ?? 0) + 1;

            $consent = Consent::query()->create([
                'tenant_id' => $patient->tenant_id,
                'patient_id' => $patient->getKey(),
                'consent_type' => $type,
                'version' => $nextVersion,
                'status' => Consent::STATUS_ACTIVE,
                'scope' => $request->validated('scope', []),
                'given_by' => $context->user?->getKey(),
                'given_at' => $request->validated('givenAt') ?? now(),
            ]);

            $this->refreshSummary($patient);

            return $consent;
        });

        $this->audit->record(
            'patient.consent.captured',
            'consent',
            $consent->getKey(),
            ['patientId' => $patient->getKey(), 'consentType' => $type, 'version' => $consent->version],
            $request,
        );
        $this->timeline->record($patient, 'patient.consent_captured', ['consentType' => $type, 'version' => $consent->version], $request);

        return Envelope::success(data: self::present($consent), status: 201, request: $request);
    }

    public function revoke(RevokeConsentRequest $request, Consent $consent): JsonResponse
    {
        AccessCheck::patientChild($consent, write: true);

        if ($consent->status !== Consent::STATUS_ACTIVE) {
            return Envelope::error('CONFLICT', 'Only an active consent can be revoked.', 409, request: $request);
        }

        $context = TenantContext::current();
        $patient = $consent->patient;

        DB::transaction(function () use ($request, $consent, $context, $patient): void {
            $consent->status = Consent::STATUS_REVOKED;
            $consent->revoked_by = $context->user?->getKey();
            $consent->revoked_at = now();
            $consent->revocation_reason = $request->validated('reason');
            $consent->save();

            $this->refreshSummary($patient);
        });

        $this->audit->record(
            'patient.consent.revoked',
            'consent',
            $consent->getKey(),
            ['patientId' => $patient->getKey(), 'consentType' => $consent->consent_type, 'version' => $consent->version],
            $request,
        );
        $this->timeline->record($patient, 'patient.consent_revoked', ['consentType' => $consent->consent_type, 'version' => $consent->version], $request);

        return Envelope::success(data: self::present($consent), request: $request);
    }

    private function refreshSummary(Patient $patient): void
    {
        $summary = $patient->consents()
            ->orderBy('version')
            ->get()
            ->pluck('status', 'consent_type')
            ->all();

        $patient->consent_summary = $summary;
        $patient->save();
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Consent $consent): array
    {
        return [
            'id' => $consent->getKey(),
            'patientId' => $consent->patient_id,
            'consentType' => $consent->consent_type,
            'version' => $consent->version,
            'status' => $consent->status,
            'scope' => $consent->scope,
            'givenAt' => $consent->given_at?->toIso8601String(),
            'revokedAt' => $consent->revoked_at?->toIso8601String(),
            'revocationReason' => $consent->revocation_reason,
        ];
    }
}
