<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Facility;
use App\Models\Module;
use App\Models\ModuleEntitlement;
use App\Models\Organization;
use App\Models\User;
use App\Services\ModuleService;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Backend-authoritative module gate (Core Platform / SaaS Foundation).
 *
 * The frontend filters navigation by role and its local view of enabled
 * modules, but the backend remains authoritative: a request to a module that
 * is not enabled for the current tenant MUST be rejected regardless of what
 * the client asserted. Disabled modules preserve data but revoke access.
 */
class EnsureModuleEnabledTest extends TestCase
{
    use RefreshDatabase;

    private ModuleService $modules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modules = app(ModuleService::class);
        $this->seed([ModuleSeeder::class]);
    }

    private function handle(TenantContext $context, string $code): \Illuminate\Http\Response
    {
        TenantContext::setCurrent($context);

        $middleware = new EnsureModuleEnabled($this->modules);
        $next = fn () => response('ok');

        try {
            return $middleware->handle(Request::create('/test'), $next, $code);
        } finally {
            TenantContext::setCurrent(null);
        }
    }

    private function tenantContext(bool $isPlatform, ?Organization $org = null, ?Facility $facility = null): TenantContext
    {
        return new TenantContext(
            user: User::factory()->create(),
            isPlatform: $isPlatform,
            organization: $org,
            facility: $facility,
            assignments: collect(),
        );
    }

    public function test_platform_context_has_no_entitlement_check(): void
    {
        $this->assertEquals('ok', $this->handle($this->tenantContext(true), 'pharmacy')->getContent());
    }

    public function test_tenant_context_without_organization_is_rejected(): void
    {
        try {
            $this->handle($this->tenantContext(false), 'pharmacy');
            $this->fail('Expected ApiException was not thrown.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(ErrorCodes::TENANT_REQUIRED, $e->errorCode);
            $this->assertSame(403, $e->statusCode);
        }
    }

    public function test_disabled_module_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $context = $this->tenantContext(false, $org);

        try {
            $this->handle($context, 'pharmacy');
            $this->fail('Expected ApiException was not thrown.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(ErrorCodes::MODULE_DISABLED, $e->errorCode);
            $this->assertSame(403, $e->statusCode);
        }
    }

    public function test_enabled_module_passes_org_wide(): void
    {
        $org = Organization::factory()->create();
        $this->modules->enableModules($org->getKey(), ['pharmacy']);
        // org-wide entitlement (no facility) covers every facility.
        $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
        $context = $this->tenantContext(false, $org, $facility);

        $this->assertEquals('ok', $this->handle($context, 'pharmacy')->getContent());
    }

    public function test_facility_scoped_enablement_is_enforced_per_facility(): void
    {
        $org = Organization::factory()->create();
        $facA = Facility::factory()->create(['tenant_id' => $org->getKey()]);
        $facB = Facility::factory()->create(['tenant_id' => $org->getKey()]);

        $module = Module::where('code', 'pharmacy')->firstOrFail();
        ModuleEntitlement::create([
            'organization_id' => $org->getKey(),
            'facility_id' => $facA->getKey(),
            'module_id' => $module->getKey(),
            'status' => 'enabled',
            'activation_state' => 'active',
            'source' => 'onboarding',
            'activated_at' => now(),
        ]);

        // Enabled for facility A.
        $this->assertEquals('ok', $this->handle($this->tenantContext(false, $org, $facA), 'pharmacy')->getContent());

        // Not enabled for facility B.
        try {
            $this->handle($this->tenantContext(false, $org, $facB), 'pharmacy');
            $this->fail('Expected ApiException was not thrown.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(ErrorCodes::MODULE_DISABLED, $e->errorCode);
        }
    }

    public function test_unknown_module_is_rejected(): void
    {
        $org = Organization::factory()->create();

        try {
            $this->handle($this->tenantContext(false, $org), 'does-not-exist');
            $this->fail('Expected ApiException was not thrown.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame(ErrorCodes::MODULE_DISABLED, $e->errorCode);
            $this->assertSame(403, $e->statusCode);
        }
    }

    public function test_module_alias_enforces_through_http_kernel(): void
    {
        Route::middleware(['auth:sanctum', ResolveTenantContext::class, 'module:pharmacy'])
            ->get('/_test/module-gate', fn () => response()->json(['ok' => true]));

        $org = Organization::factory()->create();
        $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
        $user = User::factory()->create();
        Identity::assign($user, 'org_admin', $org, $facility);

        // Not enabled -> 403 MODULE_DISABLED through the real HTTP stack.
        $this->withToken(Identity::tokenFor($user))
            ->withHeader('X-Swasthya-Facility', $facility->getKey())
            ->getJson('/_test/module-gate')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ErrorCodes::MODULE_DISABLED);

        // Enable the module -> the request passes the gate.
        $this->modules->enableModules($org->getKey(), ['pharmacy'], $facility->getKey());
        $this->withToken(Identity::tokenFor($user))
            ->withHeader('X-Swasthya-Facility', $facility->getKey())
            ->getJson('/_test/module-gate')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
