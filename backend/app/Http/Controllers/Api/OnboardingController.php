<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(protected OnboardingService $onboardingService) {}

    public function store(Request $request): JsonResponse
    {
        $session = $this->onboardingService->createSession(
            $request->user()->id,
            $request->only(['organization', 'facility', 'modules', 'configurations'])
        );

        return response()->json(['session' => $session], 201);
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $session = $this->onboardingService->getSession($id, $request->user()->id);
        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json(['session' => $session]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $session = $this->onboardingService->updateStep(
            $id,
            $request->user()->id,
            $request->input('step', 1),
            $request->input('data', [])
        );

        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json(['session' => $session]);
    }

    public function activate(string $id, Request $request): JsonResponse
    {
        try {
            $result = $this->onboardingService->activate($id, $request->user()->id);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function modules(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        return response()->json([
            'modules' => $this->onboardingService->getEnabledModules($ctx->tenantId(), $ctx->facilityId()),
        ]);
    }

    public function checkModule(Request $request, string $moduleCode): JsonResponse
    {
        $ctx = TenantContext::current();

        return response()->json([
            'module' => $moduleCode,
            'enabled' => $this->onboardingService->isModuleEnabled($ctx->tenantId(), $moduleCode, $ctx->facilityId()),
        ]);
    }
}
