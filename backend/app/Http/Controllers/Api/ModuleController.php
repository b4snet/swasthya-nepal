<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ModuleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    public function __construct(protected ModuleService $moduleService) {}

    public function catalog(): JsonResponse
    {
        return response()->json([
            'modules' => $this->moduleService->catalog(),
        ]);
    }

    public function enabled(): JsonResponse
    {
        $ctx = TenantContext::current();

        return response()->json([
            'modules' => $this->moduleService->enabledFor($ctx->tenantId(), $ctx->facilityId()),
        ]);
    }

    public function check(string $moduleCode): JsonResponse
    {
        $ctx = TenantContext::current();

        return response()->json([
            'module' => $moduleCode,
            'enabled' => $this->moduleService->isEnabled($ctx->tenantId(), $moduleCode, $ctx->facilityId()),
        ]);
    }
}
