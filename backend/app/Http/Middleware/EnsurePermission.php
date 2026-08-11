<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level authorization gate (MASTER_RULES.md §8–9).
 *
 * Usage: `authorize:facility:create` (any one of several codes passes).
 * The decision is made against the request's resolved TenantContext — the
 * principal's active assignments, current tenant, and current facility —
 * never against anything the client asserted (SECURITY.md §10).
 *
 * Scope boundary rule (API_CONTRACTS.md §4): reads that miss scope return
 * 404 at the controller (existence is not leaked); this middleware denies
 * writes/actions with 403 SCOPE_DENIED.
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = TenantContext::current();

        foreach ($permissions as $permission) {
            if ($context->can($permission)) {
                return $next($request);
            }
        }

        throw new ApiException(
            ErrorCodes::SCOPE_DENIED,
            'You are not authorized to perform this action.',
            403,
        );
    }
}
