<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can list form templates via API', function () {
    FormTemplate::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'TEST-001',
        'name' => 'Test Form',
        'slug' => 'test-form',
        'category' => 'clinical',
        'schema' => ['sections' => []],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    $response = $this->getJson('/api/v1/forms/templates?active_only=true');
    $response->assertOk();
});

it('form template has correct schema structure', function () {
    $template = FormTemplate::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'SCHEMA-001',
        'name' => 'Schema Test',
        'slug' => 'schema-test',
        'category' => 'consent',
        'schema' => [
            'sections' => [
                [
                    'title' => 'Consent',
                    'fields' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ],
                ],
            ],
        ],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    expect($template->schema['sections'])->toHaveCount(1);
    expect($template->schema['sections'][0]['fields'])->toHaveCount(1);
    expect($template->schema['sections'][0]['fields'][0]['key'])->toBe('name');
});

it('form template categories are valid', function () {
    $validCategories = FormTemplate::CATEGORIES;

    $template = FormTemplate::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'CAT-001',
        'name' => 'Category Test',
        'slug' => 'category-test',
        'category' => 'pharmacy',
        'schema' => ['sections' => []],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    expect($template->category)->toBeIn($validCategories);
});

it('form submission tracks document number', function () {
    $submission = FormSubmission::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'template_id' => '00000000-0000-0000-0000-000000000099',
        'template_version' => 1,
        'data' => ['chief_complaint' => 'Test'],
        'document_number' => 'FM-20260821-00001',
        'status' => 'draft',
        'submitted_by' => '00000000-0000-0000-0000-000000000050',
    ]);

    expect($submission->document_number)->toBe('FM-20260821-00001');
    expect($submission->status)->toBe('draft');
});

it('form submission status lifecycle works', function () {
    $submission = FormSubmission::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'template_id' => '00000000-0000-0000-0000-000000000099',
        'template_version' => 1,
        'data' => ['test' => true],
        'status' => 'draft',
        'submitted_by' => '00000000-0000-0000-0000-000000000050',
    ]);

    $submission->markSubmitted();
    expect($submission->status)->toBe('submitted');
    expect($submission->submitted_at)->not->toBeNull();

    $submission->markVerified('00000000-0000-0000-0000-000000000051');
    expect($submission->status)->toBe('verified');
    expect($submission->verified_by)->toBe('00000000-0000-0000-0000-000000000051');

    $submission->markApproved('00000000-0000-0000-0000-000000000052');
    expect($submission->status)->toBe('approved');
    expect($submission->approved_by)->toBe('00000000-0000-0000-0000-000000000052');
});

it('form submission cancellation records reason', function () {
    $submission = FormSubmission::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'template_id' => '00000000-0000-0000-0000-000000000099',
        'template_version' => 1,
        'data' => ['test' => true],
        'status' => 'draft',
        'submitted_by' => '00000000-0000-0000-0000-000000000050',
    ]);

    $submission->markCancelled('00000000-0000-0000-0000-000000000050', 'Patient request');
    expect($submission->status)->toBe('cancelled');
    expect($submission->cancellation_reason)->toBe('Patient request');
});

it('form template role access check works', function () {
    $template = FormTemplate::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'ROLE-001',
        'name' => 'Role Test',
        'slug' => 'role-test',
        'category' => 'clinical',
        'schema' => ['sections' => []],
        'allowed_roles' => ['doctor', 'nurse'],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    expect($template->isAccessibleByRole('doctor'))->toBeTrue();
    expect($template->isAccessibleByRole('nurse'))->toBeTrue();
    expect($template->isAccessibleByRole('pharmacist'))->toBeFalse();
});

it('form submission setField and getField work', function () {
    $submission = FormSubmission::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'template_id' => '00000000-0000-0000-0000-000000000099',
        'template_version' => 1,
        'data' => ['initial' => 'value'],
        'status' => 'draft',
        'submitted_by' => '00000000-0000-0000-0000-000000000050',
    ]);

    expect($submission->getField('initial'))->toBe('value');

    $submission->setField('new_field', 'new_value');
    expect($submission->getField('new_field'))->toBe('new_value');
});
