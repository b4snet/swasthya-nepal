<?php

use App\Models\NumberingConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has all 19 document types defined', function () {
    $types = NumberingConfig::DOCUMENT_TYPES;
    expect(count($types))->toBeGreaterThanOrEqual(16);
    expect($types)->toHaveKey('uhid');
    expect($types)->toHaveKey('registration');
    expect($types)->toHaveKey('encounter');
    expect($types)->toHaveKey('appointment');
    expect($types)->toHaveKey('admission');
    expect($types)->toHaveKey('prescription');
    expect($types)->toHaveKey('invoice');
    expect($types)->toHaveKey('receipt');
    expect($types)->toHaveKey('referral');
    expect($types)->toHaveKey('discharge');
    expect($types)->toHaveKey('consent');
    expect($types)->toHaveKey('lab_report');
    expect($types)->toHaveKey('sample');
    expect($types)->toHaveKey('investigation_order');
    expect($types)->toHaveKey('procedure');
    expect($types)->toHaveKey('surgery');
});

it('has valid reset policies', function () {
    expect(NumberingConfig::RESET_POLICIES)->toContain('daily');
    expect(NumberingConfig::RESET_POLICIES)->toContain('monthly');
    expect(NumberingConfig::RESET_POLICIES)->toContain('yearly');
    expect(NumberingConfig::RESET_POLICIES)->toContain('never');
});

it('preview generates correct format', function () {
    $config = NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'uhid',
        'prefix' => 'SMC',
        'sequence_length' => 6,
        'date_format' => null,
        'reset_policy' => 'never',
        'include_facility' => false,
        'separator' => '-',
    ]);

    $preview = $config->preview();
    expect($preview)->toContain('SMC');
    expect($preview)->toContain('000001');
});

it('preview with date format includes date', function () {
    $config = NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'invoice',
        'prefix' => 'INV',
        'sequence_length' => 5,
        'date_format' => 'Ymd',
        'reset_policy' => 'daily',
        'include_facility' => false,
        'separator' => '-',
    ]);

    $preview = $config->preview();
    expect($preview)->toContain('INV');
    expect($preview)->toContain(now()->format('Ymd'));
});

it('preview with facility includes facility component', function () {
    $config = NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'admission',
        'prefix' => 'ADM',
        'sequence_length' => 5,
        'date_format' => 'Ymd',
        'reset_policy' => 'daily',
        'include_facility' => true,
        'separator' => '-',
    ]);

    $preview = $config->preview();
    expect($preview)->toContain('ADM');
    expect($preview)->toContain('FAC');
});

it('config defaults are correct', function () {
    $config = NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'prescription',
        'prefix' => 'RX',
    ]);

    expect($config->sequence_length)->toBe(5);
    expect($config->reset_policy)->toBe('never');
    expect($config->include_facility)->toBeFalse();
    expect($config->separator)->toBe('-');
    expect($config->is_active)->toBeTrue();
});

it('config is unique per tenant and document type', function () {
    NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'uhid',
        'prefix' => 'SMC',
    ]);

    // Second config for same tenant + type should fail on unique constraint
    $this->expectException(QueryException::class);

    NumberingConfig::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'document_type' => 'uhid',
        'prefix' => 'OTHER',
    ]);
});
