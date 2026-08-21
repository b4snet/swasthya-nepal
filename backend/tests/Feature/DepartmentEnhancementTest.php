<?php

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('department has correct types defined', function () {
    $types = Department::TYPES;
    expect($types)->toContain('medical');
    expect($types)->toContain('surgical');
    expect($types)->toContain('supportive');
    expect($types)->toContain('emergency');
    expect($types)->toContain('diagnostic');
    expect($types)->toContain('administrative');
    expect($types)->toContain('pharmacy');
    expect($types)->toContain('laboratory');
    expect($types)->toContain('radiology');
    expect($types)->toContain('other');
});

it('department has medical departments catalog', function () {
    $medical = Department::MEDICAL_DEPARTMENTS;
    expect($medical)->toHaveKey('gynecology_obstetrics');
    expect($medical)->toHaveKey('psychiatry');
    expect($medical)->toHaveKey('neurology');
    expect($medical)->toHaveKey('cardiology');
    expect($medical)->toHaveKey('pediatrics');
    expect($medical)->toHaveKey('internal_medicine');
    expect($medical)->toHaveKey('dermatology');
    expect($medical)->toHaveKey('nephrology');
    expect($medical)->toHaveKey('gastroenterology');
    expect($medical)->toHaveKey('endocrinology');
});

it('department has surgical departments catalog', function () {
    $surgical = Department::SURGICAL_DEPARTMENTS;
    expect($surgical)->toHaveKey('general_surgery');
    expect($surgical)->toHaveKey('cardiovascular_surgery');
    expect($surgical)->toHaveKey('neurosurgery');
    expect($surgical)->toHaveKey('orthopedic_surgery');
    expect($surgical)->toHaveKey('dental');
});

it('department has supportive departments catalog', function () {
    $supportive = Department::SUPPORTIVE_DEPARTMENTS;
    expect($supportive)->toHaveKey('radiology_imaging');
    expect($supportive)->toHaveKey('physiotherapy');
    expect($supportive)->toHaveKey('laboratory');
    expect($supportive)->toHaveKey('pharmacy');
});

it('department model accepts new fields', function () {
    $dept = Department::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'facility_id' => '00000000-0000-0000-0000-000000000010',
        'name' => 'Enhanced Cardiology',
        'code' => 'enh-card',
        'status' => 'active',
        'department_type' => 'medical',
        'description' => 'Heart and cardiovascular care',
        'phone' => 'ext-1234',
        'location' => 'Building A, Floor 3',
        'operating_hours' => [
            ['day' => 'monday', 'open' => '09:00', 'close' => '17:00'],
            ['day' => 'tuesday', 'open' => '09:00', 'close' => '17:00'],
        ],
        'responsible_roles' => ['doctor', 'nurse'],
        'sort_order' => 1,
    ]);

    expect($dept->department_type)->toBe('medical');
    expect($dept->description)->toBe('Heart and cardiovascular care');
    expect($dept->phone)->toBe('ext-1234');
    expect($dept->location)->toBe('Building A, Floor 3');
    expect($dept->operating_hours)->toHaveCount(2);
    expect($dept->responsible_roles)->toContain('doctor');
    expect($dept->responsible_roles)->toContain('nurse');
    expect($dept->sort_order)->toBe(1);
});

it('department defaults to medical type', function () {
    $dept = Department::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'facility_id' => '00000000-0000-0000-0000-000000000010',
        'name' => 'Default Dept',
        'code' => 'def-dept',
        'status' => 'active',
    ]);

    expect($dept->department_type)->toBe('medical');
    expect($dept->sort_order)->toBe(0);
});

it('department cast arrays work correctly', function () {
    $dept = Department::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'facility_id' => '00000000-0000-0000-0000-000000000010',
        'name' => 'Array Test',
        'code' => 'arr-test',
        'status' => 'active',
        'operating_hours' => [['day' => 'monday', 'open' => '08:00', 'close' => '16:00']],
        'queue_settings' => ['tokenPrefix' => 'CARD', 'maxQueue' => 50],
        'appointment_availability' => ['slotDuration' => 30, 'bookingWindow' => 14],
    ]);

    expect($dept->operating_hours)->toBeArray();
    expect($dept->queue_settings)->toBeArray();
    expect($dept->queue_settings['tokenPrefix'])->toBe('CARD');
    expect($dept->appointment_availability)->toBeArray();
    expect($dept->appointment_availability['slotDuration'])->toBe(30);
});
