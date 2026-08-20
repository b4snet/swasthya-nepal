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

class NursingWorkflowTest extends TestCase
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

    public function test_create_and_list_tasks(): void
    {
        $ctx = $this->createUser();
        $h = $this->h($ctx);
        $resp = $this->postJson('/api/v1/nursing/tasks', [
            'patientId' => $ctx['user']->id,
            'taskType' => 'vitals',
            'description' => 'Record morning vitals',
            'priority' => 'routine',
        ], $h);
        $resp->assertCreated();
        $list = $this->getJson('/api/v1/nursing/tasks', $h);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data') ?? $list->json());
    }

    public function test_complete_task(): void
    {
        $ctx = $this->createUser();
        $h = $this->h($ctx);
        $create = $this->postJson('/api/v1/nursing/tasks', [
            'patientId' => $ctx['user']->id,
            'taskType' => 'medication',
            'description' => 'Administer paracetamol',
            'priority' => 'urgent',
        ], $h);
        $taskId = $create->json('data.id');
        $complete = $this->postJson("/api/v1/nursing/tasks/{$taskId}/complete", [
            'completionNotes' => 'Administered at 08:00',
        ], $h);
        $complete->assertOk();
    }

    public function test_record_vital(): void
    {
        $ctx = $this->createUser();
        $h = $this->h($ctx);
        $resp = $this->postJson('/api/v1/nursing/vitals', [
            'patientId' => $ctx['user']->id,
            'recordedBy' => $ctx['user']->id,
            'observedAt' => now()->toIso8601String(),
            'temperatureCelsius' => 36.5,
            'heartRateBpm' => 72,
            'systolicBp' => 120,
            'diastolicBp' => 80,
            'spo2Percent' => 98,
        ], $h);
        $resp->assertCreated();
    }

    public function test_nursing_tenant_isolation(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $this->postJson('/api/v1/nursing/tasks', [
            'patientId' => $a['user']->id, 'taskType' => 'vitals', 'description' => 'Test A',
        ], $this->h($a))->assertCreated();
        $resp = $this->getJson('/api/v1/nursing/tasks', $this->h($b));
        $resp->assertOk();
        $tasks = $resp->json('data') ?? $resp->json();
        foreach ($tasks as $task) {
            $this->assertNotEquals($a['user']->id, $task['patient_id'] ?? null);
        }
    }
}
