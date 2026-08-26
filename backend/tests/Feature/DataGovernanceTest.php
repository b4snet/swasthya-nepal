<?php

use App\Services\DataGovernanceService;
use Tests\Support\Identity;

/**
 * PHASE 91 — Data Governance and Records Lifecycle.
 *
 * Tests the data governance model: classification, retention,
 * correction workflows, export authorization, and tenant offboarding.
 */
it('returns the data classification matrix', function () {
    $service = new DataGovernanceService;
    $matrix = $service->classificationMatrix();

    expect($matrix)->toBeArray();
    expect($matrix)->toHaveKeys([
        'patient_identity', 'clinical_record', 'medication',
        'diagnostics', 'documents', 'finance', 'staff',
        'security', 'audit', 'ai', 'configuration',
    ]);

    // Each entry must have required fields
    foreach ($matrix as $key => $entry) {
        expect($entry)->toHaveKeys([
            'classification', 'owner', 'retention_years',
            'correction_method', 'exportable', 'auditable', 'description',
        ]);
    }
});

it('classifies patient identity as CONFIDENTIAL_PHI', function () {
    $service = new DataGovernanceService;
    $matrix = $service->classificationMatrix();

    expect($matrix['patient_identity']['classification'])->toBe('CONFIDENTIAL_PHI');
    expect($matrix['patient_identity']['exportable'])->toBeTrue();
    expect($matrix['patient_identity']['auditable'])->toBeTrue();
});

it('classifies audit records as append-only', function () {
    $service = new DataGovernanceService;
    $matrix = $service->classificationMatrix();

    expect($matrix['audit']['correction_method'])->toBe('append_only');
    expect($matrix['audit']['exportable'])->toBeFalse();
});

it('returns retention eligibility requiring hospital policy', function () {
    $service = new DataGovernanceService;
    $result = $service->retentionEligibility('patient_identity');

    expect($result['eligible'])->toBeFalse();
    expect($result['requires_policy'])->toBeTrue();
    expect($result['retention_years'])->toBeNull();
});

it('returns retention eligibility with hospital override', function () {
    $service = new DataGovernanceService;
    $result = $service->retentionEligibility('patient_identity', 10);

    expect($result['eligible'])->toBeTrue();
    expect($result['retention_years'])->toBe(10);
    expect($result['requires_policy'])->toBeFalse();
});

it('returns unknown classification for undefined record class', function () {
    $service = new DataGovernanceService;
    $result = $service->retentionEligibility('nonexistent_record');

    expect($result['eligible'])->toBeFalse();
    expect($result['classification'])->toBe('UNKNOWN');
});

it('checks tenant offboarding readiness', function () {
    $service = new DataGovernanceService;
    $hospital = Identity::organization(['name' => 'Test Hospital']);

    $result = $service->offboardingReadiness($hospital->id);

    expect($result['ready'])->toBeTrue();
    expect($result['blockers'])->toBeEmpty();
    expect($result['data_summary'])->toBeArray();
});

it('generates export manifest for a hospital', function () {
    $service = new DataGovernanceService;
    $hospital = Identity::organization(['name' => 'Test Hospital']);

    $manifest = $service->exportManifest($hospital->id);

    expect($manifest['exportable_categories'])->toBeArray();
    expect($manifest['exportable_categories'])->not->toBeEmpty();

    // Each category should have required fields
    foreach ($manifest['exportable_categories'] as $category) {
        expect($category)->toHaveKeys([
            'category', 'record_count', 'classification', 'requires_authorization',
        ]);
    }
});

it('authorizes export with required permissions', function () {
    $service = new DataGovernanceService;
    $result = $service->authorizeExport('user-1', 'patient');

    expect($result['authorized'])->toBeTrue();
    expect($result['required_permissions'])->toContain('patient:export');
});
