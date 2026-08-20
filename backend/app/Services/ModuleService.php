<?php

namespace App\Services;

use App\Models\Module;
use App\Models\ModuleEntitlement;
use Illuminate\Support\Facades\DB;

class ModuleService
{
    /**
     * Get all active modules with their dependency info.
     */
    public function catalog(): array
    {
        return Module::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Module $m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'description' => $m->description,
                'domain' => $m->domain,
                'category' => $m->category,
                'is_core' => $m->is_core,
                'dependencies' => $m->dependencies,
                'required_permissions' => $m->required_permissions,
                'nav_config' => $m->nav_config,
            ])
            ->toArray();
    }

    /**
     * Get enabled modules for an organization/facility.
     */
    public function enabledFor(string $organizationId, ?string $facilityId = null): array
    {
        $query = ModuleEntitlement::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'enabled')
            ->where('activation_state', 'active')
            ->with('module');

        if ($facilityId) {
            $query->where(function ($q) use ($facilityId) {
                $q->where('facility_id', $facilityId)
                    ->orWhereNull('facility_id');
            });
        }

        return $query->get()
            ->filter(fn (ModuleEntitlement $e) => $e->module !== null)
            ->map(fn (ModuleEntitlement $e) => [
                'id' => $e->module->id,
                'code' => $e->module->code,
                'name' => $e->module->name,
                'configuration' => $e->configuration,
                'entitlement_id' => $e->id,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Check if a module is enabled for an organization/facility.
     */
    public function isEnabled(string $organizationId, string $moduleCode, ?string $facilityId = null): bool
    {
        $module = Module::where('code', $moduleCode)->first();
        if (! $module) {
            return false;
        }

        return ModuleEntitlement::query()
            ->where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->where('status', 'enabled')
            ->where('activation_state', 'active')
            ->where(function ($q) use ($facilityId) {
                if ($facilityId) {
                    $q->where('facility_id', $facilityId)
                        ->orWhereNull('facility_id');
                }
            })
            ->exists();
    }

    /**
     * Resolve dependencies: given a list of module codes, return all required modules.
     */
    public function resolveDependencies(array $moduleCodes): array
    {
        $all = [];
        $queue = $moduleCodes;

        while (! empty($queue)) {
            $code = array_shift($queue);
            if (in_array($code, $all)) {
                continue;
            }

            $module = Module::where('code', $code)->first();
            if (! $module) {
                continue;
            }

            $all[] = $code;
            foreach ($module->dependencies as $dep) {
                if (! in_array($dep, $all)) {
                    $queue[] = $dep;
                }
            }
        }

        return $all;
    }

    /**
     * Enable modules for an organization/facility (entitlement grant).
     */
    public function enableModules(
        string $organizationId,
        array $moduleCodes,
        ?string $facilityId = null,
        ?string $userId = null,
        array $configurations = []
    ): array {
        $resolved = $this->resolveDependencies($moduleCodes);
        $results = [];

        DB::transaction(function () use ($organizationId, $resolved, $facilityId, $userId, $configurations, &$results) {
            foreach ($resolved as $code) {
                $module = Module::where('code', $code)->first();
                if (! $module) {
                    continue;
                }

                $entitlement = ModuleEntitlement::updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'facility_id' => $facilityId,
                        'module_id' => $module->id,
                    ],
                    [
                        'status' => 'enabled',
                        'activation_state' => 'active',
                        'configuration' => $configurations[$code] ?? [],
                        'source' => 'onboarding',
                        'created_by' => $userId,
                        'activated_at' => now(),
                    ]
                );

                $results[] = [
                    'module' => $code,
                    'entitlement_id' => $entitlement->id,
                    'status' => 'enabled',
                ];
            }
        });

        return $results;
    }

    /**
     * Disable a module (preserves data, revokes access).
     */
    public function disableModule(
        string $organizationId,
        string $moduleCode,
        ?string $facilityId = null
    ): bool {
        $module = Module::where('code', $moduleCode)->first();
        if (! $module) {
            return false;
        }

        $entitlement = ModuleEntitlement::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->where(function ($q) use ($facilityId) {
                if ($facilityId) {
                    $q->where('facility_id', $facilityId);
                }
            })
            ->first();

        if (! $entitlement) {
            return false;
        }

        $entitlement->update([
            'status' => 'disabled',
            'activation_state' => 'inactive',
        ]);

        return true;
    }
}
