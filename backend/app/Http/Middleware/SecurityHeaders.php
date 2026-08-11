<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (SECURITY.md §23, MASTER_RULES.md §6.1).
 *
 * Header coverage is verified by a regression test that fails the build if a
 * header is missing or weakened (TESTING_STRATEGY.md §3.7).
 *
 * Note: telehealth surfaces (Phase 3) may scope camera/microphone in
 * Permissions-Policy per SECURITY.md §23 — never globally.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::addTo($response);

        return $response;
    }

    /**
     * Apply the header set to any response. Normally the pipeline does this
     * via post-`next` code (error responses included); this helper is also
     * used from bootstrap/app.php for exceptions that escape before the
     * middleware stack runs.
     */
    public static function addTo(Response $response): void
    {
        $response->headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
