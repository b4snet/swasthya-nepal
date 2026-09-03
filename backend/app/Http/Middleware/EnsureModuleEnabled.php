<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\ModuleService;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend-authoritative module gate (MASTER_RULES.md, Core Platform).
 *
 * The frontend filters navigation by role and by its local view of enabled
 * modules, but the backend remains authoritative: a request to a module that
 * is not enabled for the current tenant MUST be rejected here, regardless of
 * what the client asserted. Disabled modules preserve data but revoke access.
 *
 * Usage: `module:pharmacy` — resolves the module by code and checks the
 * current tenant's entitlement (module-level entitlement; org-level when no
 * facility scope applies, otherwise facility-specific or fallback org-level).
 *
 * Core modules (and platform context with no tenant) pass through: the
 * catalog's core modules are always enabled by definition.
 */
final class EnsureModuleEnabled
{
    public function __construct(protected ModuleService $modules) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $context = TenantContext::current();

        // Platform context has no tenant — there is no entitlement to check,
        // and platform administration is not gated by tenant module state.
        if ($context->isPlatform) {
            return $next($request);
        }

        $organizationId = $context->tenantId();
        if ($organizationId === null) {
            throw new ApiException(
                ErrorCodes::TENANT_REQUIRED,
                'A tenant context is required to access this module.',
                403,
            );
        }

        if (! $this->modules->isEnabled($organizationId, $moduleCode, $context->facilityId())) {
            throw new ApiException(
                ErrorCodes::MODULE_DISABLED,
                "The '{$moduleCode}' module is not enabled for this organization.",
                403,
            );
        }

        return $next($request);
    }
}
