<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health endpoints (MASTER_RULES.md §20.2, ARCHITECTURE.md §20):
 *
 *  - GET /api/v1/health/live  — liveness: the process is up. Never depends
 *    on downstream services (an LB must not drain a healthy instance).
 *  - GET /api/v1/health/ready — readiness: the platform can serve traffic.
 *    Runs real dependency checks; returns 503 SERVICE_UNAVAILABLE listing
 *    the failing checks when not ready.
 *
 * Both are intentionally not rate-limited and never require authentication
 * (infrastructure probes).
 */
final class HealthController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        return Envelope::success(
            data: [
                'status' => 'ok',
                'time' => now()->toIso8601String(),
            ],
            request: $request,
        );
    }

    public function ready(Request $request): JsonResponse
    {
        $checks = [
            $this->checkDatabase(),
        ];

        $ready = collect($checks)->every(static fn (array $check): bool => $check['status'] === 'ok');

        if (! $ready) {
            return Envelope::error(
                code: ErrorCodes::SERVICE_UNAVAILABLE,
                message: 'Service is not ready: one or more dependencies are unavailable.',
                status: 503,
                details: $checks,
                request: $request,
            );
        }

        return Envelope::success(
            data: [
                'status' => 'ok',
                'checks' => $checks,
                'time' => now()->toIso8601String(),
            ],
            request: $request,
        );
    }

    /**
     * @return array{name: string, status: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['name' => 'database', 'status' => 'ok'];
        } catch (Throwable $exception) {
            report($exception);

            return ['name' => 'database', 'status' => 'fail'];
        }
    }
}
