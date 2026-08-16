<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Interop\IssueTokenRequest;
use App\Http\Requests\Interop\RecordIntegrationStatusRequest;
use App\Http\Requests\Interop\RegisterPartnerRequest;
use App\Http\Requests\Interop\StoreEgressDestinationRequest;
use App\Http\Requests\Interop\StoreIntegrationRequest;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\EgressDestination;
use App\Models\Encounter;
use App\Models\Integration;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\OauthPartner;
use App\Models\OauthPartnerToken;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Services\FhirProjection;
use App\Services\IntegrationRegistryService;
use App\Services\PartnerOauthService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interoperability readiness (ROADMAP Phase 18, INTEROPERABILITY.md §13–14).
 *
 * Two distinct surfaces:
 *
 *  - STAFF (authorize:integration:view/manage): the integration registry
 *    with MEASURED status, the egress allowlist (SSRF guard), and OAuth2
 *    partner registration/revocation. Nothing here connects to a live
 *    system — it records and governs readiness truthfully.
 *
 *  - PARTNER (public token endpoint + ResolvePartnerContext FHIR reads):
 *    client_credentials issuance and scoped, consent-bound projections of
 *    the tenant's OWN data. Every projection requires the token's scope AND
 *    an ACTIVE data-use consent for the patient covering the resource
 *    (consent at the boundary, INTEROPERABILITY.md §10). The partner
 *    identity is derived from the token — never from client input.
 */
final class InteropController extends Controller
{
    public function __construct(
        private readonly IntegrationRegistryService $registry,
        private readonly PartnerOauthService $oauth,
        private readonly AuditLogger $audit,
    ) {}

    // ───────────────────────────── Staff ──────────────────────────────────

    public function indexIntegrations(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $integrations = Integration::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('type')
            ->get()
            ->map(fn (Integration $integration): array => $this->integrationPayload($integration));

        return Envelope::success(['integrations' => $integrations->values()]);
    }

    public function registerIntegration(StoreIntegrationRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->requireStaffId($context);

        $integration = $this->registry->register([
            'tenant_id' => $context->tenantId(),
            'type' => $request->validated('type'),
            'provider' => $request->validated('provider'),
            'purpose' => $request->validated('purpose'),
            'contract_version' => $request->validated('contractVersion'),
            'standards_version' => $request->validated('standardsVersion'),
            'mapping_version' => $request->validated('mappingVersion'),
            'owner_staff_id' => $request->validated('ownerStaffId'),
            'config_encrypted' => $request->validated('configEncrypted'),
        ], $staffId);

        $this->audit->record(
            'interop.integration_registered',
            'integration',
            $integration->getKey(),
            ['type' => $integration->type, 'provider' => $integration->provider],
            $request,
        );

        return Envelope::success(['integration' => $this->integrationPayload($integration)], [], [], 201);
    }

    public function recordIntegrationStatus(RecordIntegrationStatusRequest $request, Integration $integration): JsonResponse
    {
        // The integration is TENANT-tier: AccessCheck::tenantScoped is the
        // application mirror of RLS invisibility — a cross-tenant id is a
        // 404 for every method (existence never leaked, write unreachable).
        AccessCheck::tenantScoped($integration);

        $staffId = $this->requireStaffId(TenantContext::current());
        $updated = $this->registry->recordStatusCheck(
            $integration,
            (string) $request->validated('status'),
            $request->validated('health'),
            $staffId,
        );

        $this->audit->record(
            'interop.integration_status_updated',
            'integration',
            $updated->getKey(),
            ['status' => $updated->status, 'lastCheckedAt' => $updated->last_checked_at?->toIso8601String()],
            $request,
        );

        return Envelope::success(['integration' => $this->integrationPayload($updated)]);
    }

    public function setKillSwitch(Integration $integration, Request $request): JsonResponse
    {
        AccessCheck::tenantScoped($integration);
        $on = (bool) $request->input('killSwitched', true);
        $updated = $this->registry->setKillSwitch(
            $integration,
            $on,
            $this->requireStaffId(TenantContext::current()),
        );

        $this->audit->record(
            'interop.integration_kill_switched',
            'integration',
            $updated->getKey(),
            ['killSwitched' => $updated->kill_switched],
            $request,
        );

        return Envelope::success(['integration' => $this->integrationPayload($updated)]);
    }

    public function indexEgressAllowlist(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $destinations = EgressDestination::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('host')
            ->get()
            ->map(fn ($d): array => [
                'id' => $d->getKey(),
                'host' => $d->host,
                'port' => $d->port,
                'purpose' => $d->purpose,
                'isActive' => $d->is_active,
            ]);

        return Envelope::success(['destinations' => $destinations->values()]);
    }

    public function storeEgressDestination(StoreEgressDestinationRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $destination = $this->registry->addEgressDestination([
            'tenant_id' => $context->tenantId(),
            'integration_id' => $request->validated('integrationId'),
            'host' => $request->validated('host'),
            'port' => $request->validated('port'),
            'purpose' => $request->validated('purpose'),
        ], $this->requireStaffId($context));

        $this->audit->record(
            'interop.egress_allowed',
            'egress_destination',
            $destination->getKey(),
            ['host' => $destination->host, 'port' => $destination->port],
            $request,
        );

        return Envelope::success(['destination' => [
            'id' => $destination->getKey(),
            'host' => $destination->host,
            'port' => $destination->port,
            'purpose' => $destination->purpose,
            'isActive' => $destination->is_active,
        ]], [], [], 201);
    }

    public function registerPartner(RegisterPartnerRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->requireStaffId($context);

        $result = $this->oauth->registerPartner(
            (string) $context->tenantId(),
            (string) $request->validated('name'),
            $request->validated('scopes'),
            (int) $request->validated('tokenTtlSeconds'),
            $request->validated('webhookUrl'),
            $request->validated('webhookSecret'),
            $staffId,
        );

        $this->audit->record(
            'interop.partner_registered',
            'oauth_partner',
            $result['partner']->getKey(),
            ['scopes' => $result['partner']->scopes],
            $request,
        );

        return Envelope::success([
            'partner' => $this->partnerPayload($result['partner']),
            // The ONLY time the client secret exists in plaintext.
            'clientSecret' => $result['clientSecret'],
            'clientId' => $result['partner']->client_id,
        ], [], [], 201);
    }

    public function revokePartner(OauthPartner $partner, Request $request): JsonResponse
    {
        AccessCheck::tenantScoped($partner);
        $revoked = $this->oauth->revokePartner($partner, $this->requireStaffId(TenantContext::current()));

        $this->audit->record(
            'interop.partner_revoked',
            'oauth_partner',
            $revoked->getKey(),
            [],
            $request,
        );

        return Envelope::success(['partner' => $this->partnerPayload($revoked)]);
    }

    public function indexPartners(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $partners = OauthPartner::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('name')
            ->get()
            ->map(fn (OauthPartner $partner): array => $this->partnerPayload($partner));

        return Envelope::success(['partners' => $partners->values()]);
    }

    // ───────────────────────────── Partner ────────────────────────────────

    public function issueToken(IssueTokenRequest $request): JsonResponse
    {
        $result = $this->oauth->issueToken(
            (string) $request->validated('clientId'),
            (string) $request->validated('clientSecret'),
            $request->validated('scope'),
        );

        $this->audit->record(
            'interop.partner_token_issued',
            'oauth_partner_token',
            $result['tokenRow']->getKey(),
            ['scopes' => $result['tokenRow']->scopes, 'ttlSeconds' => $result['tokenRow']->expires_at->diffInSeconds(now())],
            $request,
            tenantId: $result['tokenRow']->tenant_id,
            actorType: AuditEvent::ACTOR_INTEGRATION,
        );

        return Envelope::success([
            'accessToken' => $result['token'],
            'tokenType' => 'Bearer',
            'expiresIn' => $result['tokenRow']->expires_at->diffInSeconds(now()),
            'scope' => $result['tokenRow']->scopes,
        ], [], [], 201);
    }

    public function fhirPatient(Patient $patient, Request $request): JsonResponse
    {
        $token = $this->partnerToken($request);
        $this->oauth->assertScope($token, OauthPartner::SCOPE_FHIR_PATIENT);
        // Tenant-scope first: the partner projects only its own tenant — a
        // cross-tenant id resolves to 404 at the boundary (RLS mirror).
        AccessCheck::tenantScoped($patient);
        $this->assertBoundaryConsent($patient, OauthPartner::SCOPE_FHIR_PATIENT, $request);

        $this->audit->record(
            'interop.fhir_projected',
            'patient',
            $patient->getKey(),
            ['resourceType' => FhirProjection::RESOURCE_PATIENT, 'partnerId' => $token->oauth_partner_id, 'scope' => OauthPartner::SCOPE_FHIR_PATIENT],
            $request,
        );

        return Envelope::success(FhirProjection::patient($patient->only(['id', 'mrn', 'full_name', 'date_of_birth', 'sex'])));
    }

    public function fhirEncounter(Encounter $encounter, Request $request): JsonResponse
    {
        $token = $this->partnerToken($request);
        $this->oauth->assertScope($token, OauthPartner::SCOPE_FHIR_ENCOUNTER);
        AccessCheck::tenantScoped($encounter);
        $this->assertBoundaryConsentForPatient($encounter->patient_id, OauthPartner::SCOPE_FHIR_ENCOUNTER, $request);

        $this->audit->record(
            'interop.fhir_projected',
            'encounter',
            $encounter->getKey(),
            ['resourceType' => FhirProjection::RESOURCE_ENCOUNTER, 'partnerId' => $token->oauth_partner_id, 'scope' => OauthPartner::SCOPE_FHIR_ENCOUNTER],
            $request,
        );

        return Envelope::success(FhirProjection::encounter($encounter->only(['id', 'patient_id', 'type', 'status', 'started_at', 'ended_at'])));
    }

    public function fhirMedicationRequest(Prescription $prescription, Request $request): JsonResponse
    {
        $token = $this->partnerToken($request);
        $this->oauth->assertScope($token, OauthPartner::SCOPE_FHIR_MEDICATION_REQUEST);
        AccessCheck::tenantScoped($prescription);
        $this->assertBoundaryConsentForPatient($prescription->patient_id, OauthPartner::SCOPE_FHIR_MEDICATION_REQUEST, $request);

        $lines = PrescriptionLine::query()
            ->where('prescription_id', $prescription->getKey())
            ->orderBy('line_no')
            ->get()
            ->map(fn (PrescriptionLine $line): array => [
                'dose' => $line->dose,
                'route' => $line->route,
                'frequency' => $line->frequency,
                'duration' => $line->duration,
                'medication_name' => $line->medication?->name ?? '',
            ])
            ->all();

        $this->audit->record(
            'interop.fhir_projected',
            'prescription',
            $prescription->getKey(),
            ['resourceType' => FhirProjection::RESOURCE_MEDICATION_REQUEST, 'partnerId' => $token->oauth_partner_id, 'scope' => OauthPartner::SCOPE_FHIR_MEDICATION_REQUEST],
            $request,
        );

        return Envelope::success(FhirProjection::medicationRequest(
            $prescription->only(['id', 'patient_id', 'status', 'created_at']),
            $lines,
        ));
    }

    public function fhirDiagnosticReport(LabOrder $labOrder, Request $request): JsonResponse
    {
        $token = $this->partnerToken($request);
        $this->oauth->assertScope($token, OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT);
        AccessCheck::tenantScoped($labOrder);

        // Only a REPORTED order is releasable — anything else resolves to
        // 404 (no existence leak for unreleased clinical content).
        if ($labOrder->status !== LabOrder::STATUS_REPORTED) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Not found.', 404);
        }

        $this->assertBoundaryConsentForPatient($labOrder->patient_id, OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT, $request);

        $items = LabOrderItem::query()
            ->where('lab_order_id', $labOrder->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (LabOrderItem $item): array => [
                'id' => $item->getKey(),
                'test_name' => $item->test?->name ?? '',
                'result_value' => $item->result_value,
                'result_unit' => $item->result_unit,
                'reference_range' => $item->reference_range,
                'verified_at' => $item->verified_at,
            ])
            ->all();

        $this->audit->record(
            'interop.fhir_projected',
            'lab_order',
            $labOrder->getKey(),
            ['resourceType' => FhirProjection::RESOURCE_DIAGNOSTIC_REPORT, 'partnerId' => $token->oauth_partner_id, 'scope' => OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT],
            $request,
        );

        return Envelope::success(FhirProjection::diagnosticReport(
            $labOrder->only(['id', 'patient_id', 'reported_at']),
            $items,
        ));
    }

    // ───────────────────────────── Helpers ────────────────────────────────

    private function partnerToken(Request $request): OauthPartnerToken
    {
        /** @var OauthPartnerToken|null $token */
        $token = $request->attributes->get('partner_token');

        if ($token === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        return $token;
    }

    private function assertBoundaryConsent(Patient $patient, string $scope, Request $request): void
    {
        $this->assertBoundaryConsentForPatient($patient->getKey(), $scope, $request);
    }

    /**
     * Consent at the boundary (INTEROPERABILITY.md §10, MASTER_RULES.md §10):
     * an outbound projection carrying patient data requires an ACTIVE
     * data-use consent whose scope covers the requested FHIR resource. The
     * query runs under the partner's tenant claim, so RLS scopes it.
     */
    private function assertBoundaryConsentForPatient(string $patientId, string $scope, Request $request): void
    {
        $consented = Consent::query()
            ->where('patient_id', $patientId)
            ->where('consent_type', Consent::TYPE_DATA_USE)
            ->where('status', Consent::STATUS_ACTIVE)
            ->whereJsonContains('scope', $scope)
            ->exists();

        if (! $consented) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'No active data-use consent covers this projection.',
                403,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationPayload(Integration $integration): array
    {
        return [
            'id' => $integration->getKey(),
            'type' => $integration->type,
            'provider' => $integration->provider,
            'status' => $integration->status,
            'purpose' => $integration->purpose,
            'contractVersion' => $integration->contract_version,
            'standardsVersion' => $integration->standards_version,
            'mappingVersion' => $integration->mapping_version,
            'killSwitched' => $integration->kill_switched,
            'lastCheckedAt' => $integration->last_checked_at?->toIso8601String(),
            'health' => $integration->health,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(OauthPartner $partner): array
    {
        return [
            'id' => $partner->getKey(),
            'name' => $partner->name,
            'clientId' => $partner->client_id,
            'scopes' => $partner->scopes,
            'status' => $partner->status,
            'tokenTtlSeconds' => $partner->token_ttl_seconds,
            'webhookUrl' => $partner->webhook_url,
            'hasWebhookSecret' => $partner->webhook_secret_hash !== null,
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

    /**
     * Staff writes require an identified staff profile in the request
     * context — a null staff id would become an empty-string UUID on the
     * row (a database-level 500, never a silent success).
     */
    private function requireStaffId(TenantContext $context): string
    {
        $staffId = $this->currentStaffId($context);

        if ($staffId === null) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'A staff profile in this organization and facility is required for this action.',
                403,
            );
        }

        return $staffId;
    }
}
