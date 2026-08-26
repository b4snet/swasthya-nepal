<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stub controller — Phase 7 specialty framework (routes registered, implementation pending). */
final class SpecialtyController extends Controller
{
    public function listProfiles(Request $r): JsonResponse
    {
        return Envelope::success(data: [], request: $r);
    }

    public function storeProfile(Request $r): JsonResponse
    {
        return Envelope::success(data: ['id' => 'stub'], request: $r);
    }

    public function showProfile(Request $r, string $id): JsonResponse
    {
        return Envelope::success(data: ['id' => $id], request: $r);
    }

    public function storeAssessment(Request $r, string $id): JsonResponse
    {
        return Envelope::success(data: ['id' => 'stub'], request: $r);
    }

    public function storeCarePlan(Request $r, string $id): JsonResponse
    {
        return Envelope::success(data: ['id' => 'stub'], request: $r);
    }

    public function activateCarePlan(Request $r, string $id): JsonResponse
    {
        return Envelope::success(data: ['id' => $id, 'status' => 'active'], request: $r);
    }

    public function completeCarePlan(Request $r, string $id): JsonResponse
    {
        return Envelope::success(data: ['id' => $id, 'status' => 'completed'], request: $r);
    }
}
