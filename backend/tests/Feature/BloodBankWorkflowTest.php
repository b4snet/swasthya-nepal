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

class BloodBankWorkflowTest extends TestCase
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
            'user_id' => $user->id, 'role_id' => $role->id,
            'tenant_id' => $org->id, 'facility_id' => $fac->id,
            'scope_type' => 'organization', 'status' => 'active', 'granted_at' => now(),
        ]);

        return ['user' => $user, 'org' => $org, 'fac' => $fac];
    }

    private function h(array $ctx): array
    {
        return [
            'Authorization' => 'Bearer '.$ctx['user']->createToken('test')->plainTextToken,
            'X-Tenant-Id' => $ctx['org']->id,
            'X-Facility-Id' => $ctx['fac']->id,
            'Accept' => 'application/json',
        ];
    }

    private function donorPayload(array $overrides = []): array
    {
        return array_merge([
            'donorNumber' => 'DN-'.uniqid(),
            'fullName' => 'Test Donor',
            'dateOfBirth' => '1990-01-01',
            'bloodGroup' => 'O',
            'rhFactor' => 'positive',
        ], $overrides);
    }

    public function test_register_donor(): void
    {
        $ctx = $this->createUser();
        $resp = $this->postJson('/api/v1/donors', $this->donorPayload(), $this->h($ctx));
        $resp->assertCreated()->assertJsonFragment(['bloodGroup' => 'O']);
    }

    public function test_list_donors(): void
    {
        $ctx = $this->createUser();
        $num = 'DN-'.uniqid();
        $this->postJson('/api/v1/donors', $this->donorPayload(['donorNumber' => $num]), $this->h($ctx))->assertCreated();
        $resp = $this->getJson('/api/v1/donors', $this->h($ctx));
        $resp->assertOk()->assertJsonFragment(['donorNumber' => $num]);
    }

    public function test_donor_tenant_isolation(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $num = 'ISO-'.uniqid();
        $this->postJson('/api/v1/donors', $this->donorPayload(['donorNumber' => $num]), $this->h($a))->assertCreated();
        $resp = $this->getJson('/api/v1/donors', $this->h($b));
        $resp->assertOk();
        $donors = $resp->json('data') ?? $resp->json();
        $nums = array_map(fn ($d) => $d['donorNumber'] ?? '', is_array($donors) ? $donors : []);
        $this->assertNotContains($num, $nums);
    }

    public function test_register_donor_requires_fields(): void
    {
        $ctx = $this->createUser();
        $resp = $this->postJson('/api/v1/donors', [], $this->h($ctx));
        $this->assertNotEquals(201, $resp->status());
    }
}
