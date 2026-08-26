<?php

use App\Services\ScaleEngineeringService;

/**
 * PHASE 93 — Scale and Capacity Engineering.
 *
 * Tests scale measurement: database capacity, RLS overhead,
 * index audit, connection capacity, and hospital capacity model.
 */
it('measures database capacity correctly', function () {
    $service = new ScaleEngineeringService;
    $capacity = $service->databaseCapacity();

    expect($capacity['tables'])->toBeGreaterThan(200);
    expect($capacity['rls_policies'])->toBeGreaterThan(700);
    expect($capacity['rls_enabled_tables'])->toBeGreaterThan(100);
    expect($capacity['indexes'])->toBeGreaterThan(800);
    expect($capacity['helper_functions'])->toBe(6);
    expect($capacity['postgresql_settings']['max_connections'])->toBeGreaterThanOrEqual(100);
});

it('provides RLS policy inventory', function () {
    $service = new ScaleEngineeringService;
    $inventory = $service->rlsInventory();

    expect($inventory)->not->toBeEmpty();

    // Each table should have at least one policy
    foreach ($inventory as $entry) {
        expect($entry['table'])->not->toBeEmpty();
        expect($entry['policy_count'])->toBeGreaterThan(0);
        expect($entry['commands'])->not->toBeEmpty();
    }
});

it('audits indexes on major tables', function () {
    $service = new ScaleEngineeringService;
    $audit = $service->indexAudit();

    expect($audit)->not->toBeEmpty();

    // Patients table should have indexes
    $patientsIndex = collect($audit)->firstWhere('table', 'patients');
    expect($patientsIndex)->not->toBeNull();
    expect($patientsIndex['index_count'])->toBeGreaterThan(0);
});

it('analyzes connection capacity', function () {
    $service = new ScaleEngineeringService;
    $connections = $service->connectionCapacity();

    expect($connections['max_connections'])->toBeGreaterThanOrEqual(100);
    expect($connections['current_active'])->toBeGreaterThanOrEqual(0);
    expect($connections['current_idle'])->toBeGreaterThanOrEqual(0);
    expect($connections['utilization_pct'])->toBeGreaterThanOrEqual(0);
    expect($connections['utilization_pct'])->toBeLessThanOrEqual(100);
    expect($connections['recommendation'])->not->toBeEmpty();
});

it('provides hospital capacity model', function () {
    $service = new ScaleEngineeringService;
    $model = $service->hospitalCapacityModel();

    expect($model)->toHaveKeys(['small_hospital', 'medium_hospital', 'large_hospital', 'multi_facility']);
    expect($model['small_hospital']['beds'])->toBeLessThan($model['medium_hospital']['beds']);
    expect($model['medium_hospital']['beds'])->toBeLessThan($model['large_hospital']['beds']);
});

it('estimates RLS overhead', function () {
    $service = new ScaleEngineeringService;
    $overhead = $service->rlsOverheadEstimate();

    expect($overhead['total_policies'])->toBeGreaterThan(700);
    expect($overhead['avg_policies_per_table'])->toBeGreaterThan(2);
    expect($overhead['estimated_overhead_pct'])->toBeGreaterThan(0);
    expect($overhead['estimated_overhead_pct'])->toBeLessThanOrEqual(20);
    expect($overhead['overhead_assessment'])->not->toBeEmpty();
});

it('performs tenant skew analysis', function () {
    $service = new ScaleEngineeringService;
    $skew = $service->tenantSkewAnalysis();

    // Should return empty or a list
    expect($skew)->toBeArray();

    // If there are tenants, each should have required fields
    foreach ($skew as $tenant) {
        expect($tenant)->toHaveKeys(['tenant_id', 'record_count', 'percentage', 'risk']);
        expect($tenant['percentage'])->toBeGreaterThanOrEqual(0);
        expect($tenant['percentage'])->toBeLessThanOrEqual(100);
    }
});

it('provides patient search performance baseline', function () {
    $service = new ScaleEngineeringService;
    $perf = $service->patientSearchPerformance();

    expect($perf['patient_count'])->toBeGreaterThanOrEqual(0);
    expect($perf['search_method'])->not->toBeEmpty();
    expect($perf['estimated_latency_ms'])->toBeGreaterThanOrEqual(0);
    expect($perf['recommendation'])->not->toBeEmpty();
});

it('generates full scale summary', function () {
    $service = new ScaleEngineeringService;
    $summary = $service->fullScaleSummary();

    expect($summary)->toHaveKeys(['database', 'rls', 'search', 'connections', 'capacity', 'skew']);
    expect($summary['database']['tables'])->toBeGreaterThan(200);
    expect($summary['rls']['total_policies'])->toBeGreaterThan(700);
    expect($summary['capacity']['small_hospital']['beds'])->toBe(75);
});
