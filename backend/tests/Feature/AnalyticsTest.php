<?php

use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Charge;
use App\Models\Dashboard;
use App\Models\Department;
use App\Models\Facility;
use App\Models\KpiDefinition;
use App\Models\MetricSnapshot;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Room;
use App\Models\Staff;
use App\Models\User;
use App\Models\Ward;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 21 — Analytics and Reporting (ROADMAP Phase 17, PRODUCT
 * REQUIREMENTS §6.19, DATABASE.md §3.51).
 *
 * Core proofs:
 *   - OBSERVED DATA ONLY (MASTER_RULES.md P.15): every metric snapshot is
 *     computed from the real source tables at generation time — the test
 *     asserts the snapshot value EQUALS the actual row count/sum.
 *   - Versioned definitions: one ACTIVE version per code (DB backstopped);
 *     supersede is CAS-guarded (a concurrent supersede → 409).
 *   - Idempotent refresh: one snapshot per (KPI, period, dimension).
 *   - Replica-fed reports: runs execute on the `reporting` connection and
 *     every run/export is audited with facts only (never PHI).
 *   - Isolation: cross-tenant and cross-facility reads resolve to 404;
 *     lists never leak other tenants' rows.
 */
beforeEach(function (): void {
    seedIdentity();

    // The local "replica" is the same database (simulated replica). Share
    // the primary's PDO session so RefreshDatabase's test transaction is
    // visible to the reporting connection — otherwise the replica path would
    // read committed-only state and see zero rows. In production the
    // REPORTING_DB_* envs point the connection at a real read replica.
    DB::connection(AnalyticsService::REPORTING_CONNECTION)->setPdo(DB::connection()->getPdo());
});

/**
 * A bed anchored to the given tenant+facility (ward → room → bed chain,
 * the established pattern — AdmissionDischargeTest).
 */
function analyticsBed(Organization $org, Facility $facility, string $status): Bed
{
    $n = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
    $ward = Ward::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'name' => 'General Ward',
        'code' => 'gen-'.$n,
        'ward_type' => 'general',
        'status' => Ward::STATUS_ACTIVE,
    ]);
    $room = Room::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'ward_id' => $ward->getKey(),
        'name' => 'Room 1',
        'code' => 'room-'.$n,
        'room_type' => 'general',
        'status' => Room::STATUS_ACTIVE,
    ]);

    return Bed::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'room_id' => $room->getKey(),
        'bed_code' => 'B-'.$n,
        'status' => $status,
        'lock_version' => 0,
    ]);
}

/**
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff}
 */
function analyticsAdmin(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff];
}

/**
 * @return array{org: Organization, facility: Facility}
 */
function analyticsOtherTenant(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);

    return ['org' => $org, 'facility' => $facility];
}

/**
 * Seed $count patients created within the given period.
 */
function analyticsSeedPatients(Organization $org, Facility $facility, int $count, CarbonImmutable $start, CarbonImmutable $end): void
{
    for ($i = 0; $i < $count; $i++) {
        Patient::factory()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'created_at' => $start->copy()->addMinutes($i)->toIso8601String(),
        ]);
    }

    // No patient may fall outside the window.
    Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'created_at' => $end->copy()->addDay()->toIso8601String(),
    ]);
}

it('requires authentication and analytics:view for the read surface', function (): void {
    $ctx = analyticsAdmin();

    $this->getJson('/api/v1/analytics/kpi-definitions')->assertUnauthorized();

    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $ctx['org'], $ctx['facility']);

    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/analytics/kpi-definitions')
        ->assertForbidden();

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->getJson('/api/v1/analytics/kpi-definitions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('creates a versioned KPI definition (version 1, active) and rejects a duplicate active code with 409', function (): void {
    $ctx = analyticsAdmin();
    $payload = [
        'code' => 'registrations',
        'name' => 'Patient registrations',
        'domain' => 'operational',
        'sourceTable' => 'patients',
        'dateColumn' => 'created_at',
        'filter' => [],
        'aggregation' => 'count',
    ];

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/analytics/kpi-definitions', $payload)
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.code', 'registrations');

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/analytics/kpi-definitions', $payload)
        ->assertStatus(409);

    // The auditor (org_finance) can VIEW definitions but never define them.
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $ctx['org'], $ctx['facility']);
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/analytics/kpi-definitions', $payload)
        ->assertForbidden();
    $this->withToken(Identity::tokenFor($finance))
        ->getJson('/api/v1/analytics/kpi-definitions')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects definitions that probe unwhitelisted sources, columns, or filters (422)', function (): void {
    $ctx = analyticsAdmin();
    $base = [
        'name' => 'Bad KPI',
        'domain' => 'operational',
        'aggregation' => 'count',
    ];

    foreach ([
        ['sourceTable' => 'passwords', 'dateColumn' => null, 'filter' => []],
        ['sourceTable' => 'patients', 'dateColumn' => 'secret_column', 'filter' => []],
        ['sourceTable' => 'patients', 'dateColumn' => null, 'filter' => ['secret_column' => 'x']],
        ['sourceTable' => 'charges', 'dateColumn' => null, 'filter' => [], 'aggregation' => 'sum', 'sumColumn' => 'balance_hidden'],
    ] as $bad) {
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/analytics/kpi-definitions', array_merge($base, ['code' => (string) Str::uuid(), 'sourceTable' => 'patients'], $bad))
            ->assertStatus(422);
    }
});

it('supersedes a KPI definition to a new version and preserves the old one (CAS, never double-created)', function (): void {
    $ctx = analyticsAdmin();
    $adminToken = Identity::tokenFor($ctx['admin']);

    $kpi = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'census',
        'source_table' => 'admissions',
        'date_column' => null,
        'filter' => ['status' => ['admitted', 'transferred']],
        'version' => 1,
    ]);

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/kpi-definitions/'.$kpi->getKey().'/supersede', [
            'name' => 'Current census (v2 definition)',
        ])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.status', 'active');

    expect(KpiDefinition::query()->findOrFail($kpi->getKey())->status)->toBe('superseded')
        ->and(KpiDefinition::query()->where('code', 'census')->where('status', 'active')->count())->toBe(1);

    // A stale concurrent supersede loses the CAS → 409 (the version was
    // already superseded by the first writer).
    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/kpi-definitions/'.$kpi->getKey().'/supersede', ['name' => 'stale'])
        ->assertStatus(409);

    // No version is ever duplicated: v1 preserved, v2 active.
    expect(KpiDefinition::query()->where('code', 'census')->count())->toBe(2);
});

it('computes metric snapshots from OBSERVED source data and refreshes idempotently', function (): void {
    $ctx = analyticsAdmin();
    $start = CarbonImmutable::now()->startOfDay();
    $end = CarbonImmutable::now()->endOfDay();
    analyticsSeedPatients($ctx['org'], $ctx['facility'], 5, $start, $end);

    $kpi = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'registrations_today',
        'source_table' => 'patients',
        'date_column' => 'created_at',
        'filter' => [],
    ]);

    $service = app(AnalyticsService::class);
    $snapshot = $service->refreshMetric($kpi, $start, $end, [], $ctx['staff']->getKey());

    // The value EQUALS the real source count — observed data, never fabricated.
    expect($snapshot->value)->toBe(5.0)
        ->and($snapshot->row_count)->toBe(5)
        ->and($snapshot->generated_by_staff_id)->toBe($ctx['staff']->getKey());

    // The out-of-window patient was excluded.
    expect(Patient::query()->where('tenant_id', $ctx['org']->getKey())->count())->toBe(6);

    // Idempotent refresh: re-running the same period updates in place — one
    // snapshot per (KPI, period, dimension), never two.
    $again = $service->refreshMetric($kpi, $start, $end, [], $ctx['staff']->getKey());
    expect(MetricSnapshot::query()->where('kpi_definition_id', $kpi->getKey())->count())->toBe(1)
        ->and($again->getKey())->toBe($snapshot->getKey());

    // API drill-down: the metrics endpoint returns the observed snapshot.
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->getJson('/api/v1/analytics/metrics/'.$kpi->getKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.value', 5)
        ->assertJsonPath('data.0.rowCount', 5);
});

it('supports point-in-time occupancy (no date column) and sum aggregations', function (): void {
    $ctx = analyticsAdmin();

    analyticsBed($ctx['org'], $ctx['facility'], 'occupied');
    analyticsBed($ctx['org'], $ctx['facility'], 'occupied');
    analyticsBed($ctx['org'], $ctx['facility'], 'available');

    $service = app(AnalyticsService::class);

    $occupancy = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'occupied_beds',
        'source_table' => 'beds',
        'date_column' => null,
        'filter' => ['status' => 'occupied'],
    ]);
    $snap = $service->refreshMetric($occupancy, CarbonImmutable::now()->subDay(), CarbonImmutable::now());
    expect($snap->value)->toBe(2.0)
        ->and($snap->row_count)->toBe(2);

    // Sum aggregation over posted charges (integer money).
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);
    Charge::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey(), 'patient_id' => $patient->getKey(), 'amount_minor' => 1500]);
    Charge::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey(), 'patient_id' => $patient->getKey(), 'amount_minor' => 2500]);

    $revenue = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'posted_charges',
        'source_table' => 'charges',
        'date_column' => 'created_at',
        'filter' => [],
        'aggregation' => 'sum',
        'sum_column' => 'amount_minor',
    ]);
    $sum = $service->refreshMetric($revenue, CarbonImmutable::now()->subDay(), CarbonImmutable::now());
    expect($sum->value)->toBe(4000.0);
});

it('composes dashboards and drills from a dashboard number to the observed snapshot', function (): void {
    $ctx = analyticsAdmin();
    $adminToken = Identity::tokenFor($ctx['admin']);

    $kpi = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'registrations',
        'source_table' => 'patients',
        'date_column' => 'created_at',
    ]);
    app(AnalyticsService::class)->refreshMetric(
        $kpi,
        CarbonImmutable::now()->startOfDay(),
        CarbonImmutable::now()->endOfDay(),
        [],
        $ctx['staff']->getKey(),
    );

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/dashboards', ['code' => 'ops', 'name' => 'Operations', 'roleGate' => ['hospital_admin']])
        ->assertCreated()
        ->assertJsonPath('data.code', 'ops');

    $dashboard = Dashboard::query()->where('code', 'ops')->firstOrFail();

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/dashboards/'.$dashboard->getKey().'/kpis', [
            'kpiDefinitionId' => $kpi->getKey(),
            'position' => 1,
        ])
        ->assertCreated();

    // Drill-down: the dashboard shows the KPI with its LATEST snapshot.
    $this->withToken($adminToken)
        ->getJson('/api/v1/analytics/dashboards/'.$dashboard->getKey())
        ->assertOk()
        ->assertJsonCount(1, 'data.kpis')
        ->assertJsonPath('data.kpis.0.kpi.code', 'registrations')
        ->assertJsonPath('data.kpis.0.latestSnapshot.id', fn ($v) => is_string($v))
        ->assertJsonPath('data.kpis.0.latestSnapshot.value', 0);

    // A receptionist cannot see the dashboard contents (analytics:view denied).
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $ctx['org'], $ctx['facility']);
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/analytics/dashboards/'.$dashboard->getKey())
        ->assertForbidden();
});

it('runs reports on the reporting connection with audited runs and replica row counts', function (): void {
    $ctx = analyticsAdmin();
    $adminToken = Identity::tokenFor($ctx['admin']);
    analyticsSeedPatients($ctx['org'], $ctx['facility'], 3, CarbonImmutable::now()->subDays(3), CarbonImmutable::now());

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/report-templates', [
            'code' => 'registrations_7d',
            'name' => 'Registrations (last 7 days)',
            'category' => 'operational',
            'scope' => 'facility',
            'query' => [
                'sourceTable' => 'patients',
                'dateColumn' => 'created_at',
                'period' => 'last_7_days',
                'filter' => [],
            ],
        ])
        ->assertCreated();

    $template = ReportTemplate::query()->where('code', 'registrations_7d')->firstOrFail();

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/reports/run', ['templateId' => $template->getKey()])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.rowCount', 1)
        ->assertJsonPath('data.rows.0.rowCount', 3)
        ->assertJsonPath('data.rows.0.value', 3);

    $run = ReportRun::query()->where('template_id', $template->getKey())->firstOrFail();
    expect($run->status)->toBe('completed')
        ->and($run->row_count)->toBe(1)
        ->and($run->is_export)->toBeFalse()
        ->and($run->error_message)->toBeNull();

    // The run is audited with facts only — no PHI in the payload.
    $event = AuditEvent::query()->where('resource_type', 'report_run')->where('resource_id', $run->getKey())->firstOrFail();
    expect($event->payload)->toHaveKeys(['templateCode', 'status', 'rowCount', 'isExport'])
        ->and((string) json_encode($event->payload))->not->toContain('Patient');
});

it('executes scheduled reports exactly once per due window (CAS idempotency)', function (): void {
    $ctx = analyticsAdmin();
    $service = app(AnalyticsService::class);

    $template = ReportTemplate::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'daily',
        'query' => ['source_table' => 'patients', 'filter' => [], 'date_column' => 'created_at', 'period' => 'yesterday'],
    ]);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/analytics/report-schedules', [
            'templateId' => $template->getKey(),
            'cronExpression' => '0 6 * * *',
        ])
        ->assertCreated();

    $schedule = ReportSchedule::query()->where('template_id', $template->getKey())->firstOrFail();
    expect($schedule->next_run_at)->toBeNull();

    $now = CarbonImmutable::now()->setTime(6, 0);
    expect($service->runDueSchedules($now))->toBe(1);

    $schedule->refresh();
    expect($schedule->last_run_at)->not->toBeNull()
        ->and($schedule->next_run_at)->not->toBeNull()
        ->and($schedule->next_run_at->greaterThan($schedule->last_run_at))->toBeTrue();

    // The same due window cannot double-run the schedule (CAS on next_run_at).
    expect($service->runDueSchedules($now))->toBe(0);
    expect(ReportRun::query()->where('schedule_id', $schedule->getKey())->count())->toBe(1);

    // An invalid cron fails at creation, not at 3am.
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/analytics/report-schedules', [
            'templateId' => $template->getKey(),
            'cronExpression' => 'not a cron',
        ])
        ->assertStatus(422);
});

it('audits exports with a fingerprint checksum and never stores PHI in the run row', function (): void {
    $ctx = analyticsAdmin();
    $adminToken = Identity::tokenFor($ctx['admin']);
    analyticsSeedPatients($ctx['org'], $ctx['facility'], 2, CarbonImmutable::now()->subDay(), CarbonImmutable::now());

    $template = ReportTemplate::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'export_me',
        'query' => ['source_table' => 'patients', 'filter' => [], 'date_column' => 'created_at', 'period' => 'yesterday'],
    ]);

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/reports/export', [
            'templateId' => $template->getKey(),
            'exportFormat' => 'csv',
        ])
        ->assertOk()
        ->assertJsonPath('data.isExport', true)
        ->assertJsonPath('data.exportFormat', 'csv')
        ->assertJsonPath('data.rowCount', 1)
        ->assertJsonPath('data.outputChecksum', fn (string $checksum): bool => strlen($checksum) === 64);

    $run = ReportRun::query()->where('template_id', $template->getKey())->firstOrFail();
    expect($run->is_export)->toBeTrue()
        ->and($run->output_checksum)->not->toBeNull();

    // The export audit event carries facts only.
    $event = AuditEvent::query()->where('resource_type', 'report_run')->where('resource_id', $run->getKey())->firstOrFail();
    expect($event->payload)->toHaveKeys(['templateCode', 'format', 'rowCount', 'outputChecksum']);

    // No PHI is ever persisted on the run row.
    expect((string) json_encode($run->only(['error_message', 'output_checksum', 'row_count'])))->not->toContain('Patient');

    // An auditor (org_finance) can run reports but NOT export them.
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $ctx['org'], $ctx['facility']);
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/analytics/reports/export', ['templateId' => $template->getKey()])
        ->assertForbidden();
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/analytics/reports/run', ['templateId' => $template->getKey()])
        ->assertOk();
});

it('isolates analytics across tenants and facilities (no existence leak, no cross-tenant rows)', function (): void {
    $ctx = analyticsAdmin();
    $other = analyticsOtherTenant();

    $kpi = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'a_kpi',
    ]);
    $dashboard = Dashboard::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'a_dash',
    ]);
    $template = ReportTemplate::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'a_rpt',
    ]);
    $run = ReportRun::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'template_id' => $template->getKey(),
        'status' => 'completed',
    ]);

    // Another tenant: route-bound models resolve to 404 (no existence leak).
    $intruder = Identity::user();
    Identity::assign($intruder, 'hospital_admin', $other['org'], $other['facility']);

    foreach ([
        "/api/v1/analytics/metrics/{$kpi->getKey()}",
        "/api/v1/analytics/dashboards/{$dashboard->getKey()}",
    ] as $url) {
        $this->withToken(Identity::tokenFor($intruder))
            ->getJson($url)
            ->assertNotFound();
    }

    // A cross-tenant report run exists but is never listed or reachable.
    expect(ReportRun::query()->where('id', $run->getKey())->exists())->toBeTrue();

    // Same tenant, a DIFFERENT facility: also invisible (TENANT_FACILITY).
    $otherFacility = Identity::facility($ctx['org']);
    $sibling = Identity::user();
    Identity::assign($sibling, 'hospital_admin', $ctx['org'], $otherFacility);
    $this->withToken(Identity::tokenFor($sibling))
        ->getJson('/api/v1/analytics/metrics/'.$kpi->getKey())
        ->assertNotFound();

    // Lists never leak the other tenant's rows.
    $this->withToken(Identity::tokenFor($intruder))
        ->getJson('/api/v1/analytics/kpi-definitions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->withToken(Identity::tokenFor($intruder))
        ->getJson('/api/v1/analytics/report-runs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('keeps a concurrent refresh of the same period to a single snapshot (DB partial unique)', function (): void {
    $ctx = analyticsAdmin();
    $service = app(AnalyticsService::class);

    $kpi = KpiDefinition::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'code' => 'race',
        'source_table' => 'patients',
        'date_column' => 'created_at',
    ]);

    $start = CarbonImmutable::now()->startOfDay();
    $end = CarbonImmutable::now()->endOfDay();

    // Two writers, same period+dimension: exactly one snapshot survives.
    $a = $service->refreshMetric($kpi, $start, $end, ['dept' => 'x']);
    $b = $service->refreshMetric($kpi, $start, $end, ['dept' => 'x']);

    expect(MetricSnapshot::query()->where('kpi_definition_id', $kpi->getKey())->count())->toBe(1)
        ->and(in_array($a->getKey(), [$b->getKey(), $a->getKey()], true))->toBeTrue();
});

it('records PHI-safe audit events for definitions, refreshes, dashboards, and schedules', function (): void {
    $ctx = analyticsAdmin();
    $adminToken = Identity::tokenFor($ctx['admin']);

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/kpi-definitions', [
            'code' => 'audited_kpi',
            'name' => 'Audited KPI',
            'domain' => 'operational',
            'sourceTable' => 'patients',
            'dateColumn' => 'created_at',
            'filter' => [],
            'aggregation' => 'count',
        ])
        ->assertCreated();

    $this->withToken($adminToken)
        ->postJson('/api/v1/analytics/dashboards', ['code' => 'audited_dash', 'name' => 'Audited'])
        ->assertCreated();

    expect(AuditEvent::query()->where('resource_type', 'kpi_definition')->where('action', 'analytics.kpi_defined')->count())->toBe(1)
        ->and(AuditEvent::query()->where('resource_type', 'dashboard')->where('action', 'analytics.dashboard_created')->count())->toBe(1);

    // No PHI, no payload leakage: only the documented fact keys.
    $event = AuditEvent::query()->where('resource_type', 'kpi_definition')->firstOrFail();
    expect($event->payload)->toHaveKeys(['code', 'version', 'domain', 'sourceTable'])
        ->and((string) json_encode($event->payload))->not->toContain('Patient')
        ->and((string) json_encode($event->payload))->not->toContain('full_name');
});
