<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PortalAccount;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3 slice 22 — establishes the tenant/facility/patient context for a
 * patient-portal request (PRODUCT REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * The patient identity is DERIVED from the authenticated portal account's
 * Sanctum token — never accepted from the client. The account is
 * TENANT_FACILITY scoped, so its own tenant/facility are projected onto the
 * RLS GUCs exactly like a staff request; the patient is loaded within that
 * context (RLS binds the app role). A portal principal carries NO role
 * permissions — self-only, consent-bound access is enforced by the portal
 * controllers on the derived patient, never by client-supplied ids.
 *
 * DATABASE PROJECTION mirrors ResolveTenantContext: one transaction, LOCAL
 * GUCs that die with it, commit/rollback on success/error.
 */
final class ResolvePortalContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::setCurrent(TenantContext::empty());

        $bearer = $request->bearerToken();
        $token = is_string($bearer) && $bearer !== ''
            ? PersonalAccessToken::findToken($bearer)
            : null;
        $account = $token?->tokenable instanceof PortalAccount ? $token->tokenable : null;

        if ($account === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        DB::beginTransaction();

        try {
            if ($account->status !== PortalAccount::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::FORBIDDEN, 'This portal account is not active.', 403);
            }
            if ($account->locked_until !== null && $account->locked_until->isFuture()) {
                throw new ApiException(ErrorCodes::RATE_LIMITED, 'This portal account is temporarily locked.', 429);
            }

            DatabaseTenantContext::setTenant($account->tenant_id);
            DatabaseTenantContext::setFacility($account->facility_id);
            DatabaseTenantContext::setPlatform(false);

            /** @var Organization|null $organization */
            $organization = Organization::query()
                ->whereHas('facilities', fn ($q) => $q->whereKey($account->facility_id))
                ->find($account->tenant_id);

            /** @var Facility|null $facility */
            $facility = Facility::query()->find($account->facility_id);
            /** @var Patient|null $patient */
            $patient = Patient::query()->find($account->patient_id);

            if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
                throw new ApiException(
                    ErrorCodes::TENANT_SUSPENDED,
                    'This organization is not active. Contact your administrator.',
                    403,
                );
            }
            if ($facility === null || $patient === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Portal account context is incomplete.', 404);
            }

            TenantContext::setCurrent(new TenantContext(
                user: null,
                isPlatform: false,
                organization: $organization,
                facility: $facility,
                assignments: collect(),
                portalAccount: $account,
                patient: $patient,
            ));

            $response = $next($request);

            DatabaseTenantContext::commitIfActive();
            DatabaseTenantContext::resetAll();

            return $response;
        } catch (\Throwable $exception) {
            DatabaseTenantContext::rollbackIfActive();
            // Best-effort GUC reset — the original exception is the one
            // that matters (an aborted transaction would mask it otherwise).
            try {
                DatabaseTenantContext::resetAll();
            } catch (\Throwable) {
                // ignore — the request transaction is already being unwound
            }

            throw $exception;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::setCurrent(null);
    }
}
