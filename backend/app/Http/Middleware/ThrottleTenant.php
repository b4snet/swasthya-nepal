<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-tenant request cap (TENANCY.md V2 §7, SECURITY.md §17).
 *
 * A per-IP limiter (throttle:api) lets one tenant burn a shared budget and
 * starve every other tenant behind the same IP/NAT. `throttle:tenant` keys on
 * the RESOLVED tenant id instead, so each tenant gets its own budget.
 *
 * WHY NOT a stock `throttle:` named limiter: bootstrap/app.php force-prioritizes
 * every ThrottleRequests instance (including named throttles) AHEAD of
 * ResolveTenantContext, so a named limiter can never see the resolved tenant.
 * This middleware is deliberately NOT ThrottleRequests — it runs explicitly
 * AFTER ResolveTenantContext in the route chain (throttle:api → auth:sanctum →
 * ResolveTenantContext → ThrottleTenant), where TenantContext is resolved and
 * the tenant gate can key on it. Where no tenant is resolved (platform context,
 * support-less platform admin) it falls back to the client IP.
 *
 * The limit is read from config per request (swasthya.rate_limits.tenant),
 * so tests can lower it without real request volume. A non-positive limit
 * (default) disables the per-tenant gate entirely.
 */
final class ThrottleTenant
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts = (int) config('swasthya.rate_limits.tenant');

        // Disabled by default; the per-IP api/writes limiters still apply.
        if ($maxAttempts < 1) {
            return $next($request);
        }

        $decaySeconds = 60;
        $key = $this->resolveKey($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            throw $this->buildException($key, $maxAttempts);
        }

        $this->limiter->hit($key, $decaySeconds);

        $response = $next($request);

        $response->headers->set(
            'X-RateLimit-Limit',
            (string) $maxAttempts,
        );
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) max(0, $maxAttempts - $this->limiter->attempts($key)),
        );

        return $response;
    }

    private function resolveKey(Request $request): string
    {
        $tenantId = TenantContext::current()->tenantId();

        return $tenantId !== null
            ? 'tenant:'.$tenantId
            : 'tenant:ip:'.$request->ip();
    }

    private function buildException(string $key, int $maxAttempts): ThrottleRequestsException
    {
        $retryAfter = $this->limiter->availableIn($key);

        return new ThrottleRequestsException(
            'Too Many Requests',
            null,
            [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ],
        );
    }
}
