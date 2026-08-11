<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request and correlation IDs (API_CONTRACTS.md §17–18, MASTER_RULES.md §18.2).
 *
 *  - X-Request-Id is always server-generated and never trusted from the
 *    client — it identifies the server-side request.
 *  - X-Correlation-Id may be proposed by the client (one per user gesture);
 *    the server validates and normalizes it, and echoes it so one patient
 *    action is one trace across requests, jobs, and integrations.
 *  - Both are bound into the log context for the whole request and set as
 *    response headers (including on error responses).
 */
final class AssignRequestIds
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $correlationId = $this->normalizeCorrelationId($request->header('X-Correlation-Id')) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('request_started_at', microtime(true));

        Log::withContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'service' => 'api',
        ]);

        $response = $next($request);

        self::addResponseHeaders($response, $request);

        return $response;
    }

    /**
     * Apply the id headers to any response. Normally the pipeline does this
     * via post-`next` code (error responses included); this helper is also
     * used from bootstrap/app.php for exceptions that escape before the
     * middleware stack runs.
     */
    public static function addResponseHeaders(Response $response, Request $request): void
    {
        $response->headers->set('X-Request-Id', (string) $request->attributes->get('request_id', ''));
        $response->headers->set('X-Correlation-Id', (string) $request->attributes->get('correlation_id', ''));
    }

    /**
     * Accept a client-proposed correlation id only if it is a bounded,
     * harmless identifier; anything else gets a fresh server id.
     */
    private function normalizeCorrelationId(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > 64) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
