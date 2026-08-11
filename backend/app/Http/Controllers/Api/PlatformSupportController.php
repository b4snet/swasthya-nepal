<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\OpenSupportSessionRequest;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\SupportSession;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\DatabaseTenantContext;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Support sessions (TENANCY.md V2 §8) — the ONLY mechanism through which a
 * platform administrator may enter a tenant: explicit target organization
 * (and optionally facility), mandatory reason, expiry ≤ 24h, full audit.
 * While active, the session resolves as a read-only tenant context; opening
 * and ending are themselves audited events attributable to the session.
 */
final class PlatformSupportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $sessions = SupportSession::query()
            ->where('user_id', $context->user?->getKey())
            ->orderByDesc('opened_at')
            ->get(['id', 'organization_id', 'facility_id', 'reason', 'status', 'opened_at', 'expires_at', 'ended_at'])
            ->map(fn (SupportSession $session): array => [
                'id' => $session->getKey(),
                'organizationId' => $session->organization_id,
                'facilityId' => $session->facility_id,
                'reason' => $session->reason,
                'status' => $session->status,
                'openedAt' => $session->opened_at?->toIso8601String(),
                'expiresAt' => $session->expires_at?->toIso8601String(),
                'endedAt' => $session->ended_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $sessions, request: $request);
    }

    public function store(OpenSupportSessionRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $organization = AccessCheck::organization($request->validated('organizationId'), write: false);

        if ($organization->status !== Organization::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::TENANT_SUSPENDED,
                'This organization is not active. Contact your administrator.',
                403,
            );
        }

        // The action is explicitly scoped to the session target: validate
        // the proposed facility against the target tenant with the tenant
        // context projected server-side (TENANCY.md V2 §7 — the client's
        // facilityId is a proposal, never authoritative).
        $facility = null;
        $facilityId = $request->validated('facilityId');

        if ($facilityId !== null) {
            DatabaseTenantContext::setTenant($organization->getKey());

            /** @var Facility|null $facility */
            $facility = Facility::query()->find($facilityId);

            if ($facility === null || $facility->tenant_id !== $organization->getKey()) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    'The facility does not belong to this organization.',
                    422,
                );
            }
        }

        $expiresInMinutes = (int) $request->validated('expiresInMinutes');
        $session = SupportSession::query()->create([
            'user_id' => $context->user?->getKey(),
            'organization_id' => $organization->getKey(),
            'facility_id' => $facility?->getKey(),
            'reason' => $request->validated('reason'),
            'status' => SupportSession::STATUS_ACTIVE,
            'opened_at' => now(),
            'expires_at' => now()->addMinutes($expiresInMinutes),
            'correlation_id' => $request->attributes->get('correlation_id') ?? (string) Str::uuid(),
        ]);

        $event = $this->audit->record(
            'support_session.opened',
            'support_session',
            $session->getKey(),
            [
                'organizationId' => $organization->getKey(),
                'facilityId' => $facility?->getKey(),
                'expiresAt' => $session->expires_at?->toIso8601String(),
            ],
            $request,
            tenantId: $organization->getKey(),
            facilityId: $facility?->getKey(),
            supportSessionId: $session->getKey(),
        );

        return Envelope::success(
            data: [
                'id' => $session->getKey(),
                'organizationId' => $organization->getKey(),
                'facilityId' => $facility?->getKey(),
                'status' => $session->status,
                'expiresAt' => $session->expires_at?->toIso8601String(),
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }

    public function end(Request $request, SupportSession $session): JsonResponse
    {
        $context = TenantContext::current();

        if ($session->user_id !== $context->user?->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Support session not found.', 404);
        }

        if ($session->status !== SupportSession::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This support session is not active.', 409);
        }

        $session->update([
            'status' => SupportSession::STATUS_ENDED,
            'ended_at' => now(),
            'ended_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'support_session.ended',
            'support_session',
            $session->getKey(),
            ['organizationId' => $session->organization_id, 'facilityId' => $session->facility_id],
            $request,
            tenantId: $session->organization_id,
            facilityId: $session->facility_id,
            supportSessionId: $session->getKey(),
        );

        return response()->json(null, 204, ['X-Audit-Event-Id' => (string) $event->getKey()]);
    }
}
