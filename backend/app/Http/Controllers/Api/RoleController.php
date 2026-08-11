<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The role catalog (API_CONTRACTS.md §21.5) — platform-provided, read-only
 * to tenants. Optional ?filter[scopeType]=facility narrows by scope.
 */
final class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->with('permissions')->orderBy('name');

        $scopeType = $request->input('filter.scopeType');

        if (is_string($scopeType) && $scopeType !== '') {
            $query->where('scope_type', $scopeType);
        }

        $roles = $query->get()->map(fn (Role $role): array => [
            'id' => $role->getKey(),
            'code' => $role->code,
            'name' => $role->name,
            'scopeType' => $role->scope_type,
            'permissions' => $role->permissions
                ->map(fn ($permission): array => [
                    'id' => $permission->getKey(),
                    'code' => $permission->code,
                ])
                ->values(),
        ])->values();

        return Envelope::success(data: $roles, request: $request);
    }
}
