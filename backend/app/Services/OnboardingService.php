<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\Module;
use App\Models\OnboardingSession;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    protected ModuleService $moduleService;

    public function __construct(ModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
    }

    /**
     * Create a new onboarding session.
     */
    public function createSession(string $userId, array $data = []): OnboardingSession
    {
        return OnboardingSession::create([
            'created_by' => $userId,
            'status' => 'draft',
            'current_step' => 1,
            'total_steps' => 5,
            'organization_data' => $data['organization'] ?? [],
            'facility_data' => $data['facility'] ?? [],
            'selected_modules' => $data['modules'] ?? [],
            'module_configurations' => $data['configurations'] ?? [],
        ]);
    }

    /**
     * Get a session by ID (with ownership check).
     */
    public function getSession(string $sessionId, string $userId): ?OnboardingSession
    {
        return OnboardingSession::where('id', $sessionId)
            ->where('created_by', $userId)
            ->first();
    }

    /**
     * Update session step data.
     */
    public function updateStep(
        string $sessionId,
        string $userId,
        int $step,
        array $stepData
    ): ?OnboardingSession {
        $session = $this->getSession($sessionId, $userId);
        if (! $session) {
            return null;
        }

        $updateFields = ['current_step' => $step];

        match ($step) {
            1 => $updateFields['organization_data'] = $stepData,
            2 => $updateFields['facility_data'] = $stepData,
            3 => $updateFields['selected_modules'] = $stepData['modules'] ?? [],
            4 => $updateFields['module_configurations'] = $stepData['configurations'] ?? [],
            default => null,
        };

        $session->update($updateFields);

        return $session->fresh();
    }

    /**
     * Finalize and activate an onboarding session.
     */
    public function activate(string $sessionId, string $userId): array
    {
        $session = $this->getSession($sessionId, $userId);
        if (! $session) {
            throw new \RuntimeException('Onboarding session not found.');
        }

        if ($session->status !== 'draft') {
            throw new \RuntimeException('Session is not in draft state.');
        }

        $selectedModules = $session->selected_modules ?? [];
        if (empty($selectedModules)) {
            throw new \RuntimeException('No modules selected.');
        }

        DB::transaction(function () use ($session, $userId, $selectedModules) {
            // Create organization if needed
            $orgData = $session->organization_data ?? [];
            $orgId = $session->organization_id;

            if (! $orgId && ! empty($orgData['name'])) {
                $org = Organization::create([
                    'name' => $orgData['name'],
                    'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
                    'timezone' => $orgData['timezone'] ?? 'Asia/Kathmandu',
                    'default_currency' => $orgData['currency'] ?? 'NPR',
                ]);
                $orgId = $org->id;
                $session->update(['organization_id' => $orgId]);
            }

            // Create facility if needed
            $facData = $session->facility_data ?? [];
            $facId = $session->facility_id;
            if (! $facId && ! empty($facData['name']) && $orgId) {
                $fac = Facility::create([
                    'tenant_id' => $orgId,
                    'name' => $facData['name'],
                    'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
                    'status' => Facility::STATUS_ACTIVE,
                    'timezone' => $orgData['timezone'] ?? 'Asia/Kathmandu',
                    'address' => [],
                    'settings' => [],
                ]);
                $facId = $fac->id;
                $session->update(['facility_id' => $facId]);
            }

            // Enable selected modules
            $this->moduleService->enableModules(
                $orgId,
                $selectedModules,
                $facId,
                $userId,
                $session->module_configurations ?? []
            );

            // Mark session as activated
            $session->update([
                'status' => 'activated',
                'activated_at' => now(),
            ]);
        });

        return [
            'session_id' => $session->id,
            'status' => 'activated',
            'organization_id' => $session->organization_id,
            'facility_id' => $session->facility_id,
            'modules_enabled' => $session->selected_modules,
        ];
    }

    /**
     * Get enabled modules for a user's organization/facility.
     */
    public function getEnabledModules(string $organizationId, ?string $facilityId = null): array
    {
        return $this->moduleService->enabledFor($organizationId, $facilityId);
    }

    /**
     * Check if a specific module is enabled.
     */
    public function isModuleEnabled(string $organizationId, string $moduleCode, ?string $facilityId = null): bool
    {
        return $this->moduleService->isEnabled($organizationId, $moduleCode, $facilityId);
    }
}
