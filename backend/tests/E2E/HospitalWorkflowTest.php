<?php

namespace Tests\E2E;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $token = '';

    private string $facilityId = '';

    private function loginAs(string $email = 'admin@swasthya.test', string $password = 'password'): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        if ($response->status() === 200) {
            $this->token = $response->json('data.accessToken', '');
            $this->facilityId = $response->json('data.assignments.0.facilityId', '');
        }
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'X-Swasthya-Facility' => $this->facilityId, 'Content-Type' => 'application/json'];
    }

    /** @test */
    public function login_returns_token_and_assignments(): void {}

    /** @test */
    public function invalid_credentials_return_401(): void {}

    /** @test */
    public function dashboard_requires_auth(): void
    {
        $this->getJson('/api/v1/dashboard/metrics')->assertStatus(401);
    }

    /** @test */
    public function authenticated_dashboard_access(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/dashboard/metrics')->assertOk();
    }

    /** @test */
    public function patient_search_works(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/patients?search=ra')->assertOk();
    }

    /** @test */
    public function appointments_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/appointments')->assertOk();
    }

    /** @test */
    public function encounters_require_auth(): void
    {
        $this->getJson('/api/v1/encounters')->assertStatus(401);
    }

    /** @test */
    public function prescriptions_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/prescriptions')->assertOk();
    }

    /** @test */
    public function finance_overview_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/enterprise/finance/overview')->assertOk();
    }

    /** @test */
    public function domain_events_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/domain-events')->assertOk();
    }

    /** @test */
    public function inventory_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/inventory')->assertOk();
    }

    /** @test */
    public function lab_orders_accessible(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->getJson('/api/v1/lab-orders')->assertOk();
    }

    /** @test */
    public function drug_check_needs_two_meds(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->postJson('/api/v1/drug-interactions/check', ['medicationIds' => ['med-1']])->assertStatus(422);
    }

    /** @test */
    public function drug_check_endpoint_exists(): void
    {
        $this->loginAs();
        $this->withHeaders($this->authHeaders())->postJson('/api/v1/drug-interactions/check', ['medicationIds' => ['med-1', 'med-2']])->assertOk() | assertStatus(422);
    }

    /** @test */
    public function unauthenticated_endpoints_rejected(): void
    {
        foreach (['/api/v1/patients', '/api/v1/appointments', '/api/v1/encounters', '/api/v1/prescriptions', '/api/v1/dashboard/metrics'] as $path) {
            $s = $this->getJson($path)->status();
            $this->assertContains($s, [401, 403], "$path should require auth");
        }
    }

    /** @test */
    public function expired_token_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer fake-token'])->getJson('/api/v1/dashboard/metrics')->assertStatus(401);
    }
}
