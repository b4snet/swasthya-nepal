<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Services\PartnerOauthService;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3 slice 23 — establishes the tenant context for an OAuth2 PARTNER
 * request (INTEROPERABILITY.md §11, DATABASE.md §3.42): the tenant is
 * DERIVED from the partner access token — never accepted from the client.
 *
 * The partner token is tenant-scoped (TENANT tier — no facility claim), so
 * the RLS projection is exactly `request.jwt.claims` with the tenant only:
 * a partner sees the tenant's rows across facilities (LIS/national
 * consumers are tenant-level), and can never cross into another tenant.
 * The token carries its own scopes; the FHIR controller checks the resource
 * scope AND the patient's active data-use consent at the boundary.
 *
 * DATABASE PROJECTION mirrors ResolveTenantContext: one transaction, LOCAL
 * GUCs that die with it, commit/rollback on success/error.
 */
final class ResolvePartnerContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::setCurrent(TenantContext::empty());

        $bearer = $request->bearerToken();
        $token = is_string($bearer) && $bearer !== ''
            ? app(PartnerOauthService::class)->resolveToken($bearer)
            : null;

        if ($token === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        DB::beginTransaction();

        try {
            DatabaseTenantContext::setTenant($token->tenant_id);
            DatabaseTenantContext::setPlatform(false);

            /** @var Organization|null $organization */
            $organization = Organization::query()->find($token->tenant_id);

            if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
                throw new ApiException(
                    ErrorCodes::TENANT_SUSPENDED,
                    'This organization is not active. Contact your administrator.',
                    403,
                );
            }

            TenantContext::setCurrent(new TenantContext(
                user: null,
                isPlatform: false,
                organization: $organization,
                facility: null,
                assignments: collect(),
            ));

            // The token travels on request attributes for the controller's
            // scope + consent checks (it is NOT a role principal).
            $request->attributes->set('partner_token', $token);

            $response = $next($request);

            DatabaseTenantContext::commitIfActive();
            DatabaseTenantContext::resetAll();

            return $response;
        } catch (\Throwable $exception) {
            DatabaseTenantContext::rollbackIfActive();
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
