<?php

use App\Exceptions\ApiExceptionMapper;
use App\Http\Middleware\AssignRequestIds;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Str;

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

        // Tenant context MUST be established BEFORE Laravel's implicit route
        // model binding (SubstituteBindings lives in the framework 'api'
        // group and otherwise runs ahead of our route-level middleware).
        // Route-bound tenant-scoped models (departments, staff, patients, …)
        // are resolved by querying the database; under RLS those queries are
        // empty until app.tenant_id / app.facility_id are set, which would
        // 404 every {model} route. Ordering the context middleware before
        // SubstituteBindings makes every bound model resolve inside the
        // request's RLS context (TENANCY.md V2 §7, §10).
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenantContext::class,
        );
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
