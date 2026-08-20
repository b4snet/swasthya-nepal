<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\RoleOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private RoleOnboardingService $onboarding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onboarding = app(RoleOnboardingService::class);
    }

    private function createUserWithRole(string $roleCode): array
    {
        $org = Organization::factory()->create();
        $fac = Facility::factory()->create(['tenant_id' => $org->id]);
        $role = Role::firstOrCreate(
            ['code' => $roleCode],
            ['name' => ucfirst(str_replace('_', ' ', $roleCode)), 'is_system' => true, 'scope_type' => 'organization']
        );
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'onboarding_complete' => false]);
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_id' => $org->id,
            'facility_id' => $fac->id,
            'scope_type' => 'organization',
            'status' => RoleAssignment::STATUS_ACTIVE,
            'granted_at' => now(),
        ]);

        return [$user, $org, $fac];
    }

    public function test_doctor_has_nmc_credential_step(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $steps = $this->onboarding->getStepsForUser($user);
        $keys = array_column($steps, 'key');
        $this->assertContains('credentials', $keys);
        $credStep = collect($steps)->firstWhere('key', 'credentials');
        $this->assertArrayHasKey('nmc_number', $credStep['fields']);
        $this->assertTrue($credStep['fields']['nmc_number']['required']);
    }

    public function test_nurse_has_nursing_registration_step(): void
    {
        [$user] = $this->createUserWithRole('nurse');
        $steps = $this->onboarding->getStepsForUser($user);
        $credStep = collect($steps)->firstWhere('key', 'credentials');
        $this->assertArrayHasKey('nursing_registration', $credStep['fields']);
        $this->assertArrayHasKey('shift_preference', $credStep['fields']);
    }

    public function test_pharmacist_has_pharmacy_license_step(): void
    {
        [$user] = $this->createUserWithRole('pharmacist');
        $steps = $this->onboarding->getStepsForUser($user);
        $credStep = collect($steps)->firstWhere('key', 'credentials');
        $this->assertArrayHasKey('pharmacy_license', $credStep['fields']);
    }

    public function test_lab_technician_has_lab_step(): void
    {
        [$user] = $this->createUserWithRole('lab_technician');
        $steps = $this->onboarding->getStepsForUser($user);
        $credStep = collect($steps)->firstWhere('key', 'credentials');
        $this->assertArrayHasKey('lab_registration', $credStep['fields']);
    }

    public function test_admin_has_administrative_step(): void
    {
        [$user] = $this->createUserWithRole('org_admin');
        $steps = $this->onboarding->getStepsForUser($user);
        $credStep = collect($steps)->firstWhere('key', 'credentials');
        $this->assertArrayHasKey('designation', $credStep['fields']);
    }

    public function test_save_step_stores_profile_data(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $user = $this->onboarding->saveStep($user, 'identity', [
            'full_name' => 'Dr. Ram Sharma',
            'phone' => '+977-9841234567',
        ]);
        $this->assertEquals('Dr. Ram Sharma', $user->profile_data['identity']['full_name']);
        $this->assertEquals('identity', $user->onboarding_step);
    }

    public function test_validate_step_rejects_missing_required(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $errors = $this->onboarding->validateStep($user, 'identity', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Full Legal Name', $errors[0]);
    }

    public function test_validate_step_passes_with_valid_data(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $errors = $this->onboarding->validateStep($user, 'identity', [
            'full_name' => 'Dr. Ram',
            'phone' => '+977-9841234567',
        ]);
        $this->assertEmpty($errors);
    }

    public function test_complete_onboarding_sets_flag(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $this->assertFalse($user->onboarding_complete);
        $user = $this->onboarding->saveStep($user, 'identity', ['full_name' => 'Dr. Ram', 'phone' => '123']);
        $user->update([
            'onboarding_complete' => true,
            'onboarding_completed_at' => now(),
            'onboarding_step' => null,
        ]);
        $user = $user->fresh();
        $this->assertTrue($user->onboarding_complete);
        $this->assertNotNull($user->onboarding_completed_at);
    }

    public function test_requires_onboarding_for_incomplete_user(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $this->assertTrue($user->requiresOnboarding());
    }

    public function test_does_not_require_onboarding_after_completion(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $user->onboarding_complete = true;
        $user->save();
        $this->assertFalse($user->requiresOnboarding());
    }

    public function test_can_access_app_after_onboarding(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $this->assertFalse($this->onboarding->canAccessApp($user));
        $user->onboarding_complete = true;
        $user->save();
        $this->assertTrue($this->onboarding->canAccessApp($user));
    }

    public function test_landing_path_differs_by_role(): void
    {
        [$doc] = $this->createUserWithRole('doctor');
        [$nurse] = $this->createUserWithRole('nurse');
        [$pharm] = $this->createUserWithRole('pharmacist');
        [$lab] = $this->createUserWithRole('lab_technician');

        $this->assertEquals('/dashboard', $this->onboarding->landingPath($doc));
        $this->assertEquals('/dashboard', $this->onboarding->landingPath($nurse));
        $this->assertEquals('/pharmacy', $this->onboarding->landingPath($pharm));
        $this->assertEquals('/laboratory', $this->onboarding->landingPath($lab));
    }

    public function test_profile_steps_include_identity_and_review(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $steps = $this->onboarding->getStepsForUser($user);
        $keys = array_column($steps, 'key');
        $this->assertEquals('identity', $keys[0]);
        $this->assertEquals('review', end($keys));
    }

    public function test_api_steps_endpoint(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $token = $user->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.explode('|', $token)[1])
            ->getJson('/api/v1/onboarding/profile/steps');
        $response->assertOk();
        $response->assertJsonStructure(['steps', 'complete', 'landing_path']);
    }

    public function test_api_save_step(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $token = $user->createToken('test')->plainTextToken;
        $h = ['Authorization' => 'Bearer '.explode('|', $token)[1]];
        $response = $this->withHeaders($h)->postJson('/api/v1/onboarding/profile/step/identity', [
            'full_name' => 'Dr. Test',
            'phone' => '+977-9841234567',
        ]);
        $response->assertOk();
        $response->assertJsonFragment(['saved' => true]);
    }

    public function test_api_complete_onboarding(): void
    {
        [$user] = $this->createUserWithRole('doctor');
        $token = $user->createToken('test')->plainTextToken;
        $h = ['Authorization' => 'Bearer '.explode('|', $token)[1]];
        $response = $this->withHeaders($h)->postJson('/api/v1/onboarding/profile/complete');
        $response->assertOk();
        $response->assertJsonFragment(['completed' => true]);
    }
}
