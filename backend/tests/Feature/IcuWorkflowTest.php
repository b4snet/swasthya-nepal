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

class IcuWorkflowTest extends TestCase
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

    public function test_list_icu_beds(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);

        $bedResp = $this->postJson('/api/v1/icu-beds', ['bedCode' => 'LIST-'.uniqid()], $h);
        $bedResp->assertCreated();

        $resp = $this->getJson('/api/v1/icu-beds', $h);
        $resp->assertOk();
        $data = $resp->json('data') ?? $resp->json();
        $this->assertNotEmpty($data);
    }

    public function test_create_icu_bed(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);
        $code = 'NEW-'.uniqid();

        $resp = $this->postJson('/api/v1/icu-beds', ['bedCode' => $code], $h);
        $resp->assertCreated()
            ->assertJsonFragment(['bedCode' => $code]);
    }

    public function test_create_bed_requires_code(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);

        $resp = $this->postJson('/api/v1/icu-beds', [], $h);
        $this->assertNotEquals(201, $resp->status());
    }

    public function test_bed_list_returns_only_own_tenant(): void
    {
        $ctxA = $this->createUser();
        $ctxB = $this->createUser();

        $hA = $this->headers($ctxA);
        $codeA = 'ISO-'.uniqid().'-A';
        $this->postJson('/api/v1/icu-beds', ['bedCode' => $codeA], $hA)->assertCreated();

        $hB = $this->headers($ctxB);
        $resp = $this->getJson('/api/v1/icu-beds', $hB);
        $resp->assertOk();
        $beds = $resp->json('data') ?? $resp->json();
        $bedCodes = array_map(fn ($b) => $b['bedCode'] ?? '', is_array($beds) ? $beds : []);
        $this->assertNotContains($codeA, $bedCodes);
    }

    public function test_admit_requires_valid_patient(): void
    {
        $ctx = $this->createUser();
        $h = $this->headers($ctx);

        $bedResp = $this->postJson('/api/v1/icu-beds', ['bedCode' => 'ADM-'.uniqid()], $h);
        $bedId = $bedResp->json('id');

        // Non-existent patient UUID should fail
        $resp = $this->postJson('/api/v1/icu-admissions', [
            'patientId' => '00000000-0000-0000-0000-000000000000',
            'icuBedId' => $bedId,
            'source' => 'er',
            'acuity' => 'level_1',
        ], $h);

        // Should not be 201 if patient doesn't exist
        $this->assertNotEquals(201, $resp->status());
    }
}
