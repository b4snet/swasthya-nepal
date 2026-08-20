<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function createUser(): array
    {
        $org = Organization::factory()->create();
        $fac = Facility::factory()->create(['tenant_id' => $org->id]);
        $user = User::factory()->create(['status' => 'active']);
        $role = Role::where('code', 'hospital_admin')->first();
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'tenant_id' => $org->id,
            'facility_id' => $fac->id,
            'scope_type' => 'organization',
            'status' => 'active',
            'granted_at' => now(),
        ]);

        return ['user' => $user, 'org' => $org, 'fac' => $fac];
    }

    private function headers(array $ctx): array
    {
        return [
            'Authorization' => 'Bearer '.$ctx['user']->createToken('test')->plainTextToken,
            'X-Tenant-Id' => $ctx['org']->id,
            'X-Facility-Id' => $ctx['fac']->id,
            'Accept' => 'application/json',
        ];
    }

    public function test_list_theatres(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);
        $code = 'OT-'.uniqid();
        $this->postJson('/api/v1/theatres', ['code' => $code, 'name' => 'Main OT'], $h)->assertCreated();
        $resp = $this->getJson('/api/v1/theatres', $h);
        $resp->assertOk()->assertJsonFragment(['code' => $code]);
    }

    public function test_create_theatre(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);
        $code = 'OT-'.uniqid();
        $resp = $this->postJson('/api/v1/theatres', ['code' => $code, 'name' => 'Surgery 1'], $h);
        $resp->assertCreated()->assertJsonFragment(['code' => $code]);
    }

    public function test_create_theatre_requires_code_and_name(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);
        $resp = $this->postJson('/api/v1/theatres', [], $h);
        $this->assertNotEquals(201, $resp->status());
    }

    public function test_theatre_tenant_isolation(): void
    {
        $ctxA = $this->createUser();
        $ctxB = $this->createUser();
        $hA = $this->headers($ctxA);
        $hB = $this->headers($ctxB);
        $codeA = 'ISO-'.uniqid();
        $this->postJson('/api/v1/theatres', ['code' => $codeA, 'name' => 'A Theatre'], $hA)->assertCreated();
        $resp = $this->getJson('/api/v1/theatres', $hB);
        $resp->assertOk();
        $theatres = $resp->json('data') ?? $resp->json();
        $codes = array_map(fn ($t) => $t['code'] ?? '', is_array($theatres) ? $theatres : []);
        $this->assertNotContains($codeA, $codes);
    }

    public function test_list_procedure_requests(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);
        $resp = $this->getJson('/api/v1/procedure-requests', $h);
        $resp->assertOk();
    }
}
