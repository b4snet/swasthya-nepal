<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Module;
use App\Models\ModuleEntitlement;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\OnboardingService;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private ModuleService $moduleService;

    private OnboardingService $onboardingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleService = app(ModuleService::class);
        $this->onboardingService = app(OnboardingService::class);
    }

    private function seedDb(): void
    {
        if (Module::count() === 0) {
            $this->seed([ModuleSeeder::class]);
        }
    }

    private function createUser(): array
    {
        $org = Organization::factory()->create();
        $fac = Facility::factory()->create(['tenant_id' => $org->id]);
        $role = Role::firstOrCreate(
            ['code' => 'org_admin'],
            ['name' => 'Organization Admin', 'is_system' => true, 'scope_type' => 'organization']
        );
        $user = User::factory()->create();
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

    private function authHeaders(User $user): array
    {
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => 'Bearer '.explode('|', $token)[1]];
    }

    public function test_catalog_returns_modules(): void
    {
        $this->seedDb();
        $catalog = $this->moduleService->catalog();
        $this->assertGreaterThanOrEqual(10, count($catalog));
    }

    public function test_catalog_excludes_inactive(): void
    {
        $this->seedDb();
        Module::where('code', 'patients')->update(['is_active' => false]);
        $codes = array_column($this->moduleService->catalog(), 'code');
        $this->assertNotContains('patients', $codes);
    }

    public function test_resolve_dependencies_transitive(): void
    {
        $this->seedDb();
        $resolved = $this->moduleService->resolveDependencies(['billing']);
        $this->assertContains('billing', $resolved);
        $this->assertContains('patients', $resolved);
    }

    public function test_enable_creates_entitlements(): void
    {
        $this->seedDb();
        $org = Organization::factory()->create();
        $results = $this->moduleService->enableModules($org->id, ['pharmacy', 'laboratory']);
        // Dependencies are resolved, so more than 2 entitlements may be created
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertEquals('enabled', $results[0]['status']);
    }

    public function test_enable_resolves_deps(): void
    {
        $this->seedDb();
        $org = Organization::factory()->create();
        $this->moduleService->enableModules($org->id, ['billing']);
        $this->assertTrue($this->moduleService->isEnabled($org->id, 'patients'));
        $this->assertTrue($this->moduleService->isEnabled($org->id, 'billing'));
    }

    public function test_disable_revokes(): void
    {
        $this->seedDb();
        $org = Organization::factory()->create();
        $this->moduleService->enableModules($org->id, ['pharmacy']);
        $this->assertTrue($this->moduleService->isEnabled($org->id, 'pharmacy'));
        $this->moduleService->disableModule($org->id, 'pharmacy');
        $this->assertFalse($this->moduleService->isEnabled($org->id, 'pharmacy'));
    }

    public function test_tenant_isolation(): void
    {
        $this->seedDb();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $this->moduleService->enableModules($orgA->id, ['pharmacy']);
        $this->assertTrue($this->moduleService->isEnabled($orgA->id, 'pharmacy'));
        $this->assertFalse($this->moduleService->isEnabled($orgB->id, 'pharmacy'));
    }

    public function test_onboarding_create(): void
    {
        $this->seedDb();
        $user = User::factory()->create();
        $session = $this->onboardingService->createSession($user->id, ['modules' => ['pharmacy']]);
        $this->assertEquals('draft', $session->status);
    }

    public function test_onboarding_update_requires_ownership(): void
    {
        $uA = User::factory()->create();
        $uB = User::factory()->create();
        $session = $this->onboardingService->createSession($uA->id);
        $this->assertNull($this->onboardingService->updateStep($session->id, $uB->id, 2, []));
    }

    public function test_onboarding_activate(): void
    {
        $this->seedDb();
        $user = User::factory()->create();
        $session = $this->onboardingService->createSession($user->id, [
            'organization' => ['name' => 'Test Hospital', 'timezone' => 'Asia/Kathmandu'],
            'facility' => ['name' => 'Main Campus'],
            'modules' => ['pharmacy', 'laboratory'],
        ]);
        $result = $this->onboardingService->activate($session->id, $user->id);
        $this->assertEquals('activated', $result['status']);
        $this->assertTrue($this->moduleService->isEnabled($result['organization_id'], 'pharmacy'));
        $this->assertTrue($this->moduleService->isEnabled($result['organization_id'], 'patients'));
    }

    public function test_activate_requires_modules(): void
    {
        $this->seedDb();
        $user = User::factory()->create();
        $session = $this->onboardingService->createSession($user->id, ['modules' => []]);
        $this->expectException(\RuntimeException::class);
        $this->onboardingService->activate($session->id, $user->id);
    }

    public function test_commercial_not_in_catalog(): void
    {
        $this->seedDb();
        $org = Organization::factory()->create();
        $results = $this->moduleService->enableModules($org->id, ['pharmacy']);
        $e = ModuleEntitlement::find($results[0]['entitlement_id']);
        $this->assertIsArray($e->internal_commercial);
        foreach ($this->moduleService->catalog() as $item) {
            $this->assertArrayNotHasKey('internal_commercial', $item);
        }
    }

    public function test_api_catalog(): void
    {
        $this->seedDb();
        [$user] = $this->createUser();
        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/v1/modules/catalog')->assertOk()
            ->assertJsonStructure(['modules' => [['code', 'name']]]);
    }

    public function test_api_enabled(): void
    {
        $this->seedDb();
        [$user] = $this->createUser();
        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/v1/modules/enabled')->assertOk();
    }

    public function test_api_check(): void
    {
        [$user] = $this->createUser();
        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/v1/modules/pharmacy/check')->assertOk()
            ->assertJsonFragment(['module' => 'pharmacy', 'enabled' => false]);
    }

    public function test_api_onboarding_flow(): void
    {
        $this->seedDb();
        [$user] = $this->createUser();
        $h = $this->authHeaders($user);
        $r = $this->withHeaders($h)->postJson('/api/v1/onboarding', [
            'modules' => ['pharmacy'],
            'organization' => ['name' => 'API Hospital'],
            'facility' => ['name' => 'Campus'],
        ]);
        $r->assertCreated();
        $id = $r->json('session.id');
        $this->withHeaders($h)->getJson("/api/v1/onboarding/$id")->assertOk();
        $this->withHeaders($h)->postJson("/api/v1/onboarding/$id/activate")->assertOk()
            ->assertJsonFragment(['status' => 'activated']);
    }
}
