<?php

use App\Exceptions\ApiExceptionMapper;
use App\Http\Middleware\AssignRequestIds;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\ResolvePartnerContext;
use App\Http\Middleware\ResolvePortalContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Versioned API root (API_CONTRACTS.md §2); literal here because the
        // config repository is not yet available at bootstrap time.
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Request-id → security headers → logging on EVERY request, including
        // unmatched routes and error paths (route-group middleware never runs
        // for those). Laravel's routing pipeline renders exceptions INSIDE the
        // middleware stack, so the error response flows back through this
        // post-`next` code exactly like a success response — no duplication.
        // (authn → authz → tenancy arrive Phases 3–4.)
        $middleware->append([
            AssignRequestIds::class,
            SecurityHeaders::class,
            LogRequest::class,
        ]);

        // Route-level authorization gate: `authorize:permission[,permission…]`
        // (MASTER_RULES.md §8–9). The decision runs against the resolved
        // TenantContext, never client input.
        $middleware->alias([
            'authorize' => EnsurePermission::class,
        ]);

        // Explicit middleware priority (SECURITY.md §17, TENANCY.md V2 §7, §10).
        // The framework default orders AuthenticatesRequests BEFORE
        // ThrottleRequests, which means an unauthenticated request to a
        // protected route fails at auth:sanctum and NEVER reaches the rate
        // limiter — a scanner could hammer protected endpoints without
        // consuming the per-IP budget. Reordering puts both ThrottleRequests
        // variants ahead of auth, so throttle:api counts every request that
        // reaches the API group, authenticated or not (a missing/invalid
        // token still yields 401, just after the limiter runs). ResolveTenantContext
        // stays before SubstituteBindings so tenant-scoped model binding
        // resolves inside the request's RLS context. Note: prependToPriorityList
        // cannot express this — it only inserts ABSENT entries, and
        // ThrottleRequests already exists in the framework default list.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            AuthenticatesSessions::class,
            ResolveTenantContext::class,
            ResolvePortalContext::class,
            ResolvePartnerContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            $response = ApiExceptionMapper::toResponse($exception, $request);

            if ($response === null) {
                return null;
            }

            // The routing pipeline converts exceptions to responses INSIDE
            // the middleware stack, so the response already flows back through
            // the middleware post-`next` code — ids, headers, and the request
            // log line are applied there exactly once. We only apply them
            // here when the request never reached the middleware at all
            // (request_id never assigned): a framework-level failure must
            // still be traceable and protected.
            if (! $request->attributes->has('request_id')) {
                $request->attributes->set('request_id', (string) Str::uuid());
                $request->attributes->set('correlation_id', (string) Str::uuid());
                $request->attributes->set('request_started_at', microtime(true));

                AssignRequestIds::addResponseHeaders($response, $request);
                SecurityHeaders::addTo($response);
                LogRequest::record($request, $response);
            }

            return $response;
        });
    })->create();
