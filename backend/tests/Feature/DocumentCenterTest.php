<?php

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_types_defined(): void
    {
        $types = GeneratedDocument::types();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('lab_report', $types);
        $this->assertArrayHasKey('radiology_report', $types);
        $this->assertArrayHasKey('discharge_summary', $types);
        $this->assertArrayHasKey('invoice', $types);
        $this->assertArrayHasKey('receipt', $types);
        $this->assertArrayHasKey('prescription', $types);
        $this->assertArrayHasKey('referral', $types);
        $this->assertArrayHasKey('consent', $types);
        $this->assertArrayHasKey('form', $types);
        $this->assertArrayHasKey('clinical_note', $types);
        $this->assertArrayHasKey('other', $types);
        $this->assertCount(11, $types);
    }

    public function test_document_categories_defined(): void
    {
        $categories = GeneratedDocument::categories();

        $this->assertIsArray($categories);
        $this->assertArrayHasKey('clinical', $categories);
        $this->assertArrayHasKey('financial', $categories);
        $this->assertArrayHasKey('administrative', $categories);
        $this->assertArrayHasKey('operational', $categories);
        $this->assertArrayHasKey('compliance', $categories);
        $this->assertCount(5, $categories);
    }

    public function test_present_maps_fields(): void
    {
        $doc = GeneratedDocument::factory()->create([
            'document_number' => 'LAB-2026-00001',
            'document_type' => 'lab_report',
            'category' => 'clinical',
            'title' => 'Complete Blood Count',
            'status' => 'generated',
            'verified' => false,
            'signed' => false,
            'printable' => true,
            'pdf_capable' => true,
            'visibility' => 'staff',
            'shared_with_patient' => false,
        ]);

        $presented = $doc->present();

        $this->assertEquals($doc->getKey(), $presented['id']);
        $this->assertEquals('LAB-2026-00001', $presented['documentNumber']);
        $this->assertEquals('lab_report', $presented['documentType']);
        $this->assertEquals('clinical', $presented['category']);
        $this->assertEquals('Complete Blood Count', $presented['title']);
        $this->assertEquals('generated', $presented['status']);
        $this->assertFalse($presented['verified']);
        $this->assertFalse($presented['signed']);
        $this->assertTrue($presented['printable']);
        $this->assertTrue($presented['pdfCapable']);
        $this->assertFalse($presented['hasPdf']);
        $this->assertEquals('staff', $presented['visibility']);
        $this->assertFalse($presented['sharedWithPatient']);
    }

    public function test_present_includes_verified_fields(): void
    {
        $doc = GeneratedDocument::factory()->create([
            'verified' => true,
            'verified_at' => now(),
            'signed' => true,
            'signed_at' => now(),
            'shared_with_patient' => true,
            'shared_at' => now(),
        ]);

        $presented = $doc->present();

        $this->assertTrue($presented['verified']);
        $this->assertNotNull($presented['verifiedAt']);
        $this->assertTrue($presented['signed']);
        $this->assertNotNull($presented['signedAt']);
        $this->assertTrue($presented['sharedWithPatient']);
        $this->assertNotNull($presented['sharedAt']);
    }

    public function test_soft_deletes(): void
    {
        $doc = GeneratedDocument::factory()->create();
        $id = $doc->getKey();

        $doc->delete();

        $this->assertSoftDeleted('generated_documents', ['id' => $id]);
        $this->assertNull(GeneratedDocument::find($id));
        $this->assertNotNull(GeneratedDocument::withTrashed()->find($id));
    }

    public function test_fillable_fields(): void
    {
        $doc = new GeneratedDocument;

        $this->assertContains('tenant_id', $doc->getFillable());
        $this->assertContains('facility_id', $doc->getFillable());
        $this->assertContains('document_number', $doc->getFillable());
        $this->assertContains('document_type', $doc->getFillable());
        $this->assertContains('category', $doc->getFillable());
        $this->assertContains('title', $doc->getFillable());
        $this->assertContains('content_html', $doc->getFillable());
        $this->assertContains('status', $doc->getFillable());
        $this->assertContains('visibility', $doc->getFillable());
    }

    public function test_factory_creates_valid_document(): void
    {
        $doc = GeneratedDocument::factory()->create();

        $this->assertNotNull($doc->id);
        $this->assertNotNull($doc->tenant_id);
        $this->assertNotNull($doc->facility_id);
        $this->assertNotNull($doc->document_number);
        $this->assertNotNull($doc->document_type);
        $this->assertNotNull($doc->category);
        $this->assertNotNull($doc->title);
    }
}
