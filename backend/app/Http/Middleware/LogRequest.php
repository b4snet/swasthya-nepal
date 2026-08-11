<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured request logging (MASTER_RULES.md §18, OBSERVABILITY.md §2).
 *
 * One JSON line per completed request: method, path, status, duration,
 * source IP, user agent — joined to the request/correlation ids already in
 * the log context (AssignRequestIds). Deliberately logged:
 *   - path only, never the query string (query parameters can carry data);
 *   - no request body, no response body, no headers (no PHI, no tokens,
 *     no credentials — MASTER_RULES.md §18.4, OBSERVABILITY.md §17).
 */
final class LogRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::record($request, $response);

        return $response;
    }

    /**
     * Record the structured request line for any response. Normally the
     * pipeline does this via post-`next` code (error responses included);
     * this helper is also used from bootstrap/app.php for exceptions that
     * escape before the middleware stack runs — a request that disappears
     * from logs is a defect (MASTER_RULES.md §20).
     */
    public static function record(Request $request, Response $response): void
    {
        $startedAt = (float) $request->attributes->get('request_started_at', microtime(true));
        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);

        Log::info('request.completed', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
        ]);
    }
}
