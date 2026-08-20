<?php

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Services\ComplianceService;
use Tests\Support\Identity;

/**
 * Phase 18 — National Analytics Reporting & Compliance.
 *
 * Compliance report lifecycle, items, acknowledgment, subscriptions,
 * domain summaries, export, audit.
 */
beforeEach(function (): void {
    seedIdentity();
});

function complianceSetup(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::query()->firstOrCreate(
        ['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'code' => 'ADMIN'],
        ['name' => 'Administration', 'status' => 'active']
    );

    return [$org, $facility, $admin, $department];
}

// ── Compliance Reports (via HTTP) ──

it('creates a compliance report', function () {
    [$org, $facility, $admin] = complianceSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/analytics/compliance-reports', [
            'reportCode' => 'PRIV-Q1-2026',
            'title' => 'Q1 2026 Privacy Compliance',
            'category' => 'privacy',
            'scope' => 'facility',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.category', 'privacy')
        ->assertJsonPath('data.scope', 'facility');
});

it('adds items to a compliance report', function () {
    [$org, $facility, $admin] = complianceSetup();

    $reportId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/analytics/compliance-reports', [
            'reportCode' => 'SEC-001',
            'title' => 'Security Audit',
            'category' => 'security',
            'scope' => 'facility',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/compliance-reports/{$reportId}/items", [
            'ruleCode' => 'SEC-RLS-001',
            'ruleName' => 'RLS enforcement',
            'severity' => 'critical',
            'status' => 'pass',
            'description' => 'RLS enforced on all tenant tables',
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/compliance-reports/{$reportId}/items", [
            'ruleCode' => 'SEC-AUTH-002',
            'ruleName' => 'Authentication enforcement',
            'severity' => 'high',
            'status' => 'fail',
            'description' => 'Some endpoints lack auth middleware',
        ])
        ->assertCreated();

    $response = $this->withToken(Identity::tokenFor($admin))
        ->getJson("/api/v1/compliance-reports/{$reportId}")
        ->assertOk();

    expect($response->json('data.summary.total_items'))->toBe(2);
    expect($response->json('data.summary.fail_count'))->toBe(1);
});

it('publishes a compliance report', function () {
    [$org, $facility, $admin] = complianceSetup();

    $reportId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/analytics/compliance-reports', [
            'reportCode' => 'PUB-001',
            'title' => 'Published Report',
            'category' => 'operational_governance',
            'scope' => 'organization',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/compliance-reports/{$reportId}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonStructure(['data' => ['publishedAt']]);
});

// ── Acknowledgment via Service Layer (avoids middleware transaction issue) ──

it('acknowledges a published report via service', function () {
    [$org, $facility, $admin] = complianceSetup();

    $service = app(ComplianceService::class);

    $report = $service->createComplianceReport(
        $org->getKey(), $facility->getKey(),
        'ACK-SVC-001', 'Service Ack Test', 'clinical_quality', 'facility', [], null,
    );
    $report = $service->publishReport($report);

    $ack = $service->acknowledgeReport($report, null, 'acknowledged', 'Reviewed');
    expect($ack->action)->toBe('acknowledged');
    expect($ack->notes)->toBe('Reviewed');

    $report->refresh();
    expect($report->status)->toBe('acknowledged');
    expect($report->acknowledged_at)->not->toBeNull();
});

it('rejects duplicate acknowledgment via service', function () {
    [$org, $facility, $admin] = complianceSetup();

    $service = app(ComplianceService::class);

    $report = $service->createComplianceReport(
        $org->getKey(), $facility->getKey(),
        'ACK-DUP-001', 'Dup Test', 'security', 'facility', [], null,
    );
    $report = $service->publishReport($report);

    $service->acknowledgeReport($report, null, 'acknowledged');

    $this->expectException(ApiException::class);
    $service->acknowledgeReport($report, null, 'acknowledged');
});

// ── Subscription via Service Layer ──

it('creates and cancels a report subscription via service', function () {
    [$org, $facility, $admin] = complianceSetup();

    $service = app(ComplianceService::class);

    $sub = $service->subscribeToReport(
        $org->getKey(), $facility->getKey(), null, null, null, 'weekly', 'in_app',
    );
    expect($sub->frequency)->toBe('weekly');
    expect($sub->status)->toBe('active');

    $service->cancelSubscription($sub);
    $sub->refresh();
    expect($sub->status)->toBe('cancelled');
});

// ── Domain Summary (via HTTP) ──

it('returns operational domain summary with real data', function () {
    [$org, $facility, $admin] = complianceSetup();

    $response = $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/analytics/domain-summary/operational')
        ->assertOk();

    expect($response->json('data'))->toHaveKeys([
        'patientsRegisteredToday', 'appointmentsToday',
        'activeEncounters', 'bedOccupancy',
    ]);
    expect($response->json('data.bedOccupancy'))->toHaveKeys(['occupied', 'total']);
});

it('returns financial domain summary', function () {
    [$org, $facility, $admin] = complianceSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/analytics/domain-summary/financial')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'revenueTodayMinor', 'outstandingMinor',
            'pendingRefunds', 'chargesToday',
        ]]);
});

it('returns pharmacy domain summary', function () {
    [$org, $facility, $admin] = complianceSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/analytics/domain-summary/pharmacy')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'dispensedToday', 'lowStockItems', 'totalReturns',
        ]]);
});

// ── Export (via HTTP) ──

it('exports a compliance report', function () {
    [$org, $facility, $admin] = complianceSetup();

    $reportId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/analytics/compliance-reports', [
            'reportCode' => 'EXP-001',
            'title' => 'Export Test',
            'category' => 'privacy',
            'scope' => 'facility',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/compliance-reports/{$reportId}/items", [
            'ruleCode' => 'PRIV-001',
            'ruleName' => 'Data encryption',
            'severity' => 'critical',
            'status' => 'pass',
            'description' => 'PHI encrypted at rest and in transit',
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/analytics/compliance-reports/{$reportId}/export")
        ->assertOk()
        ->assertJsonStructure(['data' => ['report', 'items', 'format']]);
});

// ── Audit (via HTTP) ──

it('records audit events for compliance operations', function () {
    [$org, $facility, $admin] = complianceSetup();

    $reportId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/analytics/compliance-reports', [
            'reportCode' => 'AUD-001',
            'title' => 'Audit Test',
            'category' => 'financial_controls',
            'scope' => 'organization',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/compliance-reports/{$reportId}/publish")
        ->assertOk();

    $audit = AuditEvent::query()
        ->where('action', 'compliance.report_published')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->tenant_id)->toBe($org->getKey());
});
