<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The permission catalog (API_CONTRACTS.md §21.6) — read-only, grouped by
 * domain. Part of the versioned contract: codes are never renamed within a
 * version.
 */
final class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->orderBy('domain')
            ->orderBy('code')
            ->get()
            ->map(fn (Permission $permission): array => [
                'id' => $permission->getKey(),
                'code' => $permission->code,
                'domain' => $permission->domain,
                'description' => $permission->description,
            ])
            ->values();

        return Envelope::success(data: $permissions, request: $request);
    }
}
