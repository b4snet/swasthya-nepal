<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\Facility;
use App\Models\FormTemplate;
use App\Models\HospitalBranding;
use App\Models\ModuleEntitlement;
use App\Models\NotificationTemplate;
use App\Models\NumberingConfig;
use App\Models\RoleAssignment;
use App\Models\Service;
use App\Models\User;

/**
 * Validates hospital configuration completeness and computes a readiness
 * score for go-live activation.
 *
 * PHASE 90: Hospital Configuration and Onboarding Platform.
 *
 * Each category is assessed independently. A hospital is "ready" when all
 * mandatory categories pass. Categories with insufficient policy evidence
 * are marked REVIEW rather than pass/fail.
 */
final class ConfigurationValidationService
{
    /**
     * Compute an onboarding readiness score for the given organization/facility.
     *
     * @return array{
     *     score: int,
     *     max_score: int,
     *     percentage: float,
     *     ready: bool,
     *     categories: list<array{key: string, label: string, status: string, score: int, max: int, details: list<string>}>,
     * }
     */
    public function readinessScore(string $organizationId, ?string $facilityId = null): array
    {
        $categories = [
            $this->checkFacilities($organizationId),
            $this->checkDepartments($organizationId, $facilityId),
            $this->checkServices($organizationId, $facilityId),
            $this->checkStaff($organizationId, $facilityId),
            $this->checkRoles($organizationId, $facilityId),
            $this->checkModules($organizationId, $facilityId),
            $this->checkBranding($organizationId, $facilityId),
            $this->checkNumbering($organizationId),
            $this->checkForms($organizationId),
            $this->checkNotifications($organizationId),
        ];

        $score = array_sum(array_column($categories, 'score'));
        $max = array_sum(array_column($categories, 'max'));
        $failed = array_filter($categories, fn ($c) => $c['status'] === 'missing');

        return [
            'score' => $score,
            'max_score' => $max,
            'percentage' => $max > 0 ? round(($score / $max) * 100, 1) : 0.0,
            'ready' => empty($failed),
            'categories' => $categories,
        ];
    }

    /**
     * Validate that all mandatory configuration is in place before activation.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateForActivation(string $organizationId, ?string $facilityId = null): array
    {
        $errors = [];
        $warnings = [];

        // ── Mandatory: at least one facility ──────────────────────────
        $facilityCount = Facility::where('tenant_id', $organizationId)
            ->where('status', 'active')
            ->count();

        if ($facilityCount === 0) {
            $errors[] = 'At least one active facility is required.';
        }

        // ── Mandatory: at least one department per facility ───────────
        if ($facilityCount > 0) {
            $facilities = Facility::where('tenant_id', $organizationId)
                ->where('status', 'active')
                ->get();

            foreach ($facilities as $facility) {
                $deptCount = Department::where('tenant_id', $organizationId)
                    ->where('facility_id', $facility->id)
                    ->count();
                if ($deptCount === 0) {
                    $errors[] = "Facility '{$facility->name}' has no departments.";
                }
            }
        }

        // ── Mandatory: at least one service ───────────────────────────
        $serviceCount = Service::where('tenant_id', $organizationId)
            ->where('status', 'active')
            ->count();

        if ($serviceCount === 0) {
            $errors[] = 'At least one active service is required.';
        }

        // ── Mandatory: at least one user with a role ─────────────────
        $userCount = User::where('tenant_id', $organizationId)->count();
        if ($userCount === 0) {
            $errors[] = 'At least one user must be onboarded.';
        }

        $roleAssignmentCount = RoleAssignment::where('tenant_id', $organizationId)
            ->where('status', 'active')
            ->count();
        if ($roleAssignmentCount === 0) {
            $warnings[] = 'No role assignments found. Users may not have appropriate access.';
        }

        // ── Mandatory: at least one module enabled ───────────────────
        $moduleCount = ModuleEntitlement::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        if ($moduleCount === 0) {
            $errors[] = 'At least one module must be enabled.';
        }

        // ── Warning: branding not configured ──────────────────────────
        $hasBranding = HospitalBranding::where('facility_id', $facilityId ?? '')
            ->exists();

        if (! $hasBranding && $facilityId) {
            $warnings[] = 'Hospital branding is not configured. Default branding will be used.';
        }

        // ── Warning: numbering not configured ─────────────────────────
        $hasNumbering = NumberingConfig::where('tenant_id', $organizationId)
            ->exists();

        if (! $hasNumbering) {
            $warnings[] = 'Numbering configuration not found. Default numbering will be used.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    // ─────────────────── Private category checkers ───────────────────

    private function checkFacilities(string $orgId): array
    {
        $count = Facility::where('tenant_id', $orgId)
            ->where('status', 'active')
            ->count();

        return [
            'key' => 'facilities',
            'label' => 'Facilities',
            'status' => $count > 0 ? 'pass' : 'missing',
            'score' => min($count, 1),
            'max' => 1,
            'details' => $count > 0
                ? ["{$count} active facility(ies) configured"]
                : ['No active facilities configured'],
        ];
    }

    private function checkDepartments(string $orgId, ?string $facId): array
    {
        $query = Department::where('tenant_id', $orgId);
        if ($facId) {
            $query->where('facility_id', $facId);
        }
        $count = $query->count();

        $minimum = 3; // At least OPD, Emergency, and one more
        $score = (int) ($count >= $minimum);
        $status = $score ? 'pass' : ($count > 0 ? 'partial' : 'missing');

        return [
            'key' => 'departments',
            'label' => 'Departments',
            'status' => $status,
            'score' => $score,
            'max' => 1,
            'details' => ["{$count} department(s) configured (recommended: {$minimum}+)"],
        ];
    }

    private function checkServices(string $orgId, ?string $facId): array
    {
        $query = Service::where('tenant_id', $orgId)->where('status', 'active');
        if ($facId) {
            $query->where('facility_id', $facId);
        }
        $count = $query->count();

        $minimum = 3;
        $score = (int) ($count >= $minimum);
        $status = $score ? 'pass' : ($count > 0 ? 'partial' : 'missing');

        return [
            'key' => 'services',
            'label' => 'Services',
            'status' => $status,
            'score' => $score,
            'max' => 1,
            'details' => ["{$count} active service(s) (recommended: {$minimum}+)"],
        ];
    }

    private function checkStaff(string $orgId, ?string $facId): array
    {
        $query = User::where('tenant_id', $orgId);
        if ($facId) {
            $query->where('facility_id', $facId);
        }
        $count = $query->count();

        $score = (int) ($count > 0);

        return [
            'key' => 'staff',
            'label' => 'Staff & Users',
            'status' => $score ? 'pass' : 'missing',
            'score' => $score,
            'max' => 1,
            'details' => ["{$count} user(s) onboarded"],
        ];
    }

    private function checkRoles(string $orgId, ?string $facId): array
    {
        $query = RoleAssignment::where('tenant_id', $orgId)->where('status', 'active');
        if ($facId) {
            $query->where('facility_id', $facId);
        }
        $count = $query->count();

        $score = (int) ($count > 0);

        return [
            'key' => 'roles',
            'label' => 'Roles & Permissions',
            'status' => $score ? 'pass' : 'missing',
            'score' => $score,
            'max' => 1,
            'details' => ["{$count} active role assignment(s)"],
        ];
    }

    private function checkModules(string $orgId, ?string $facId): array
    {
        $query = ModuleEntitlement::where('organization_id', $orgId)->where('status', 'active');
        if ($facId) {
            $query->where('facility_id', $facId);
        }
        $count = $query->count();

        $score = (int) ($count > 0);

        return [
            'key' => 'modules',
            'label' => 'Modules',
            'status' => $score ? 'pass' : 'missing',
            'score' => $score,
            'max' => 1,
            'details' => ["{$count} module(s) enabled"],
        ];
    }

    private function checkBranding(string $orgId, ?string $facId): array
    {
        if (! $facId) {
            return [
                'key' => 'branding',
                'label' => 'Branding',
                'status' => 'skip',
                'score' => 1,
                'max' => 1,
                'details' => ['No facility selected; branding check skipped'],
            ];
        }

        $has = HospitalBranding::where('facility_id', $facId)->exists();

        return [
            'key' => 'branding',
            'label' => 'Branding',
            'status' => $has ? 'pass' : 'partial',
            'score' => $has ? 1 : 0,
            'max' => 1,
            'details' => $has
                ? ['Hospital branding configured']
                : ['Using default branding (optional)'],
        ];
    }

    private function checkNumbering(string $orgId): array
    {
        $count = NumberingConfig::where('tenant_id', $orgId)->count();

        return [
            'key' => 'numbering',
            'label' => 'Numbering',
            'status' => $count > 0 ? 'pass' : 'partial',
            'score' => $count > 0 ? 1 : 0,
            'max' => 1,
            'details' => $count > 0
                ? ["{$count} numbering config(s) defined"]
                : ['Using default numbering (optional)'],
        ];
    }

    private function checkForms(string $orgId): array
    {
        $count = FormTemplate::where('tenant_id', $orgId)
            ->where('is_active', true)
            ->count();

        return [
            'key' => 'forms',
            'label' => 'Forms',
            'status' => $count > 0 ? 'pass' : 'partial',
            'score' => $count > 0 ? 1 : 0,
            'max' => 1,
            'details' => $count > 0
                ? ["{$count} active form template(s)"]
                : ['No custom forms configured (optional)'],
        ];
    }

    private function checkNotifications(string $orgId): array
    {
        $count = NotificationTemplate::where('tenant_id', $orgId)
            ->where('status', 'active')
            ->count();

        return [
            'key' => 'notifications',
            'label' => 'Notifications',
            'status' => $count > 0 ? 'pass' : 'partial',
            'score' => $count > 0 ? 1 : 0,
            'max' => 1,
            'details' => $count > 0
                ? ["{$count} active notification template(s)"]
                : ['No notification templates configured (optional)'],
        ];
    }
}
