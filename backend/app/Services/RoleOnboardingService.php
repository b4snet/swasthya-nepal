<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleOnboardingService
{
    /**
     * @return array<int, array{key: string, title: string, fields: array<string, array{label: string, type: string, required: bool, options?: array<int, string>}>}>
     */
    public function getStepsForUser(User $user): array
    {
        $steps = [
            [
                'key' => 'identity',
                'title' => 'Identity Information',
                'fields' => [
                    'full_name' => ['label' => 'Full Legal Name', 'type' => 'text', 'required' => true],
                    'preferred_name' => ['label' => 'Preferred Name', 'type' => 'text', 'required' => false],
                    'date_of_birth' => ['label' => 'Date of Birth', 'type' => 'date', 'required' => false],
                    'gender' => ['label' => 'Gender', 'type' => 'select', 'required' => false, 'options' => ['male', 'female', 'other', 'prefer_not_to_say']],
                    'phone' => ['label' => 'Phone Number', 'type' => 'tel', 'required' => true],
                    'address' => ['label' => 'Address', 'type' => 'text', 'required' => false],
                    'city' => ['label' => 'City', 'type' => 'text', 'required' => false],
                    'country' => ['label' => 'Country', 'type' => 'text', 'required' => false, 'default' => 'Nepal'],
                ],
            ],
            [
                'key' => 'contact',
                'title' => 'Contact Details',
                'fields' => [
                    'emergency_contact_name' => ['label' => 'Emergency Contact Name', 'type' => 'text', 'required' => false],
                    'emergency_contact_phone' => ['label' => 'Emergency Contact Phone', 'type' => 'tel', 'required' => false],
                    'preferred_language' => ['label' => 'Preferred Language', 'type' => 'select', 'required' => false, 'options' => ['en', 'ne']],
                    'timezone' => ['label' => 'Timezone', 'type' => 'text', 'required' => false, 'default' => 'Asia/Kathmandu'],
                ],
            ],
        ];

        if ($user->isDoctor()) {
            $steps[] = $this->doctorCredentialStep();
        } elseif ($user->isNurse()) {
            $steps[] = $this->nurseCredentialStep();
        } elseif ($user->isPharmacist()) {
            $steps[] = $this->pharmacistCredentialStep();
        } elseif ($user->isLabStaff()) {
            $steps[] = $this->labCredentialStep();
        } else {
            $steps[] = $this->adminCredentialStep();
        }

        $steps[] = [
            'key' => 'facility',
            'title' => 'Facility & Department',
            'fields' => [
                'department' => ['label' => 'Department', 'type' => 'text', 'required' => false],
                'ward' => ['label' => 'Ward / Unit', 'type' => 'text', 'required' => false],
            ],
        ];

        $steps[] = ['key' => 'review', 'title' => 'Review & Confirm', 'fields' => []];

        return $steps;
    }

    public function saveStep(User $user, string $stepKey, array $data): User
    {
        $profile = $user->profile_data ?? [];
        $profile[$stepKey] = $data;
        $user->profile_data = $profile;
        $user->onboarding_step = $stepKey;
        $user->save();

        return $user->fresh();
    }

    /**
     * @return array<int, string>
     */
    public function validateStep(User $user, string $stepKey, array $data): array
    {
        $steps = $this->getStepsForUser($user);
        $step = collect($steps)->firstWhere('key', $stepKey);

        if (! $step) {
            return ['Invalid onboarding step.'];
        }

        $errors = [];
        foreach ($step['fields'] as $fieldKey => $field) {
            if ($field['required'] && empty($data[$fieldKey])) {
                $errors[] = $field['label'].' is required.';
            }
        }

        return $errors;
    }

    public function completeOnboarding(User $user): User
    {
        $user->update([
            'onboarding_complete' => true,
            'onboarding_completed_at' => now(),
            'onboarding_step' => null,
        ]);

        // Audit the completion (best-effort — audit may fail if RLS claims are not set)
        try {
            $tenantId = $user->roleAssignments()->first()?->tenant_id;
            DB::table('audit_events')->insert([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'actor_id' => $user->id,
                'action' => 'onboarding.completed',
                'subject_type' => 'User',
                'subject_id' => $user->id,
                'payload' => json_encode([
                    'role' => $user->primaryRole(),
                    'steps_completed' => array_keys($user->profile_data ?? []),
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit logging is non-critical for onboarding completion
        }

        return $user->fresh();
    }

    public function canAccessApp(User $user): bool
    {
        return $user->status === User::STATUS_ACTIVE && $user->onboarding_complete;
    }

    public function landingPath(User $user): string
    {
        return match (true) {
            $user->isDoctor() => '/dashboard',
            $user->isNurse() => '/dashboard',
            $user->isPharmacist() => '/pharmacy',
            $user->isLabStaff() => '/laboratory',
            default => '/dashboard',
        };
    }

    private function doctorCredentialStep(): array
    {
        return [
            'key' => 'credentials',
            'title' => 'Professional Credentials',
            'fields' => [
                'specialty' => ['label' => 'Specialty', 'type' => 'text', 'required' => true],
                'sub_specialty' => ['label' => 'Sub-Specialty', 'type' => 'text', 'required' => false],
                'nmc_number' => ['label' => 'NMC License Number', 'type' => 'text', 'required' => true],
                'nmc_issuing_authority' => ['label' => 'License Issuing Authority', 'type' => 'text', 'required' => false],
                'qualification' => ['label' => 'Highest Qualification', 'type' => 'text', 'required' => true],
                'institution' => ['label' => 'Institution', 'type' => 'text', 'required' => false],
                'graduation_year' => ['label' => 'Graduation Year', 'type' => 'text', 'required' => false],
                'years_experience' => ['label' => 'Years of Experience', 'type' => 'number', 'required' => false],
                'consultation_fee' => ['label' => 'Default Consultation Fee', 'type' => 'number', 'required' => false],
            ],
        ];
    }

    private function nurseCredentialStep(): array
    {
        return [
            'key' => 'credentials',
            'title' => 'Nursing Credentials',
            'fields' => [
                'nursing_registration' => ['label' => 'Nursing Registration Number', 'type' => 'text', 'required' => true],
                'registration_authority' => ['label' => 'Registration Authority', 'type' => 'text', 'required' => false],
                'specialization' => ['label' => 'Specialization', 'type' => 'text', 'required' => false],
                'qualification' => ['label' => 'Qualification', 'type' => 'text', 'required' => true],
                'years_experience' => ['label' => 'Years of Experience', 'type' => 'number', 'required' => false],
                'shift_preference' => ['label' => 'Preferred Shift', 'type' => 'select', 'required' => false, 'options' => ['morning', 'evening', 'night', 'rotational']],
            ],
        ];
    }

    private function pharmacistCredentialStep(): array
    {
        return [
            'key' => 'credentials',
            'title' => 'Pharmacy Credentials',
            'fields' => [
                'pharmacy_license' => ['label' => 'Pharmacy License Number', 'type' => 'text', 'required' => true],
                'registration_authority' => ['label' => 'Registration Authority', 'type' => 'text', 'required' => false],
                'qualification' => ['label' => 'Qualification', 'type' => 'text', 'required' => true],
                'years_experience' => ['label' => 'Years of Experience', 'type' => 'number', 'required' => false],
            ],
        ];
    }

    private function labCredentialStep(): array
    {
        return [
            'key' => 'credentials',
            'title' => 'Laboratory Credentials',
            'fields' => [
                'lab_registration' => ['label' => 'Lab Registration Number', 'type' => 'text', 'required' => false],
                'specialization' => ['label' => 'Specialization', 'type' => 'text', 'required' => false],
                'qualification' => ['label' => 'Qualification', 'type' => 'text', 'required' => true],
                'years_experience' => ['label' => 'Years of Experience', 'type' => 'number', 'required' => false],
            ],
        ];
    }

    private function adminCredentialStep(): array
    {
        return [
            'key' => 'credentials',
            'title' => 'Administrative Information',
            'fields' => [
                'designation' => ['label' => 'Designation', 'type' => 'text', 'required' => true],
                'department' => ['label' => 'Department', 'type' => 'text', 'required' => false],
            ],
        ];
    }
}
