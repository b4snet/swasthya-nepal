<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single response envelope (API_CONTRACTS.md §7–8, MASTER_RULES.md §12.2).
 *
 * Success:  { data, meta: { context, ... }, links }
 * Error:    { error: { code, message, details?, correlationId } }
 *
 * Every response also carries X-Request-Id and X-Correlation-Id (Section 17),
 * read from the request attributes set by AssignRequestIds middleware.
 */
final class Envelope
{
    public const HEADER_REQUEST_ID = 'X-Request-Id';

    public const HEADER_CORRELATION_ID = 'X-Correlation-Id';

    /**
     * json_encode options for every API payload: hex-escape HTML-significant
     * characters (JSON_HEX_TAG/APOS/AMP/QUOT) so stored user text can never
     * become executable markup if a payload is ever embedded in an HTML
     * context, while JSON_UNESCAPED_UNICODE keeps non-ASCII data readable.
     * (Laravel's response()->json() defaults to options=0, which serializes
     * '<script>' raw.)
     */
    private const JSON_OPTIONS = \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE;

    public static function success(
        mixed $data,
        array $meta = [],
        array $links = [],
        int $status = 200,
        ?Request $request = null,
        array $headers = [],
    ): JsonResponse {
        return self::withIds(
            response()->json([
                'data' => $data,
                'meta' => array_replace_recursive(self::baseMeta(), $meta),
                'links' => (object) $links,
            ], $status, $headers, self::JSON_OPTIONS),
            $request,
        );
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
        ?Request $request = null,
        array $headers = [],
    ): JsonResponse {
        $payload = ['error' => [
            'code' => $code,
            'message' => $message,
            'correlationId' => $request?->attributes->get('correlation_id'),
        ]];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return self::withIds(response()->json($payload, $status, $headers, self::JSON_OPTIONS), $request);
    }

    /**
     * The context block echoed on every response so client state can never
     * drift from server truth (API_CONTRACTS.md §5, TENANCY.md §3).
     *
     * Populated from the request's resolved TenantContext (middleware) —
     * the server-derived fact, never client input. Unauthenticated or
     * uncontexted requests echo nulls.
     */
    public static function context(): array
    {
        $context = TenantContext::current();

        return [
            'tenantId' => $context->tenantId(),
            'facilityId' => $context->facilityId(),
            'branchId' => $context->branchId(),
            'timezone' => $context->timezone(),
        ];
    }

    private static function baseMeta(): array
    {
        return ['context' => self::context()];
    }

    private static function withIds(JsonResponse $response, ?Request $request): JsonResponse
    {
        if ($request !== null) {
            $response->headers->set(self::HEADER_REQUEST_ID, (string) $request->attributes->get('request_id', ''));
            $response->headers->set(self::HEADER_CORRELATION_ID, (string) $request->attributes->get('correlation_id', ''));
        }

        return $response;
    }
}
