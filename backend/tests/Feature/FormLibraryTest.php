<?php

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Identity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->org = Identity::organization();
    $this->facility = Identity::facility($this->org);
    $this->admin = Identity::user();
    Identity::assign($this->admin, 'hospital_admin', $this->org, $this->facility);
    $this->token = Identity::tokenFor($this->admin);
});

it('can list form templates via API', function () {
    FormTemplate::create([
        'tenant_id' => $this->org->getKey(),
        'code' => 'TEST-001',
        'name' => 'Test Form',
        'slug' => 'test-form',
        'category' => 'clinical',
        'schema' => ['sections' => []],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/forms/templates?active_only=true');
    $response->assertOk();
});

it('form template has correct schema structure', function () {
    $template = FormTemplate::create([
        'tenant_id' => $this->org->getKey(),
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

    $this->assertNotEmpty($template->schema['sections']);
    $this->assertCount(1, $template->schema['sections']);
    $this->assertEquals('Consent', $template->schema['sections'][0]['title']);
    $this->assertCount(1, $template->schema['sections'][0]['fields']);
    $this->assertEquals('name', $template->schema['sections'][0]['fields'][0]['key']);
});

it('form submission records data correctly', function () {
    $template = FormTemplate::create([
        'tenant_id' => $this->org->getKey(),
        'code' => 'SUB-001',
        'name' => 'Submission Test',
        'slug' => 'submission-test',
        'category' => 'clinical',
        'schema' => ['sections' => []],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    $submission = FormSubmission::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'template_id' => $template->getKey(),
        'template_version' => $template->version,
        'submitted_by' => $this->admin->getKey(),
        'data' => ['name' => 'Ram Thapa', 'age' => 35],
        'status' => 'submitted',
    ]);

    $this->assertEquals('Ram Thapa', $submission->data['name']);
    $this->assertEquals(35, $submission->data['age']);
    $this->assertEquals('submitted', $submission->status);
});

it('form template supports all field types', function () {
    $fieldTypes = ['text', 'number', 'select', 'checkbox', 'textarea', 'date', 'file'];

    $template = FormTemplate::create([
        'tenant_id' => $this->org->getKey(),
        'code' => 'TYPES-001',
        'name' => 'Field Types Test',
        'slug' => 'field-types-test',
        'category' => 'clinical',
        'schema' => [
            'sections' => [
                [
                    'title' => 'Fields',
                    'fields' => array_map(fn ($type) => [
                        'key' => $type.'_field',
                        'label' => ucfirst($type).' Field',
                        'type' => $type,
                    ], $fieldTypes),
                ],
            ],
        ],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    $this->assertCount(7, $template->schema['sections'][0]['fields']);
});

it('form submission setField works correctly', function () {
    $template = FormTemplate::create([
        'tenant_id' => $this->org->getKey(),
        'code' => 'SETFIELD-001',
        'name' => 'SetField Test',
        'slug' => 'setfield-test',
        'category' => 'clinical',
        'schema' => ['sections' => []],
        'version' => 1,
        'is_active' => true,
        'is_published' => true,
    ]);

    $submission = FormSubmission::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'template_id' => $template->getKey(),
        'template_version' => $template->version,
        'submitted_by' => $this->admin->getKey(),
        'data' => ['initial' => true],
        'status' => 'draft',
    ]);

    $submission->setField('nested.key', 'updated');
    $submission->save();
    $submission->refresh();

    $this->assertEquals('updated', $submission->data['nested']['key']);
    $this->assertTrue($submission->data['initial']);
});

it('form submission status values are correct', function () {
    $statuses = ['draft', 'submitted', 'reviewed', 'approved', 'rejected'];

    foreach ($statuses as $status) {
        $template = FormTemplate::create([
            'tenant_id' => $this->org->getKey(),
            'code' => 'STATUS-'. strtoupper($status),
            'name' => ucfirst($status).' Test',
            'slug' => $status.'-test',
            'category' => 'clinical',
            'schema' => ['sections' => []],
            'version' => 1,
            'is_active' => true,
            'is_published' => true,
        ]);

        $submission = FormSubmission::create([
            'tenant_id' => $this->org->getKey(),
            'facility_id' => $this->facility->getKey(),
            'template_id' => $template->getKey(),
            'template_version' => $template->version,
            'submitted_by' => $this->admin->getKey(),
            'data' => [],
            'status' => $status,
        ]);

        $this->assertEquals($status, $submission->status);
    }
});
