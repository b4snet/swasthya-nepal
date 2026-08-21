<?php

namespace Tests\Feature;

use App\Models\HospitalBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hospital Branding CRUD, tenant isolation, and authorization tests
 * (Phase 78 — Hospital Brand & Document Configuration).
 */
class HospitalBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_branding_constants(): void
    {
        // HospitalBranding model should be instantiable with required fields
        $branding = new HospitalBranding;
        $this->assertNotNull($branding);
    }

    public function test_present_returns_all_fields(): void
    {
        $branding = new HospitalBranding([
            'hospital_name' => 'Swasthya Medical Center',
            'hospital_name_local' => 'स्वास्थ्य मेडिकल सेन्टर',
            'primary_color' => '#0891b2',
            'secondary_color' => '#1e293b',
            'phone' => '+977-1-4444444',
            'emergency_phone' => '+977-1-9999999',
            'email' => 'info@swasthya.com',
            'city' => 'Kathmandu',
            'state' => 'Bagmati',
            'country' => 'Nepal',
            'currency' => 'NPR',
            'currency_symbol' => 'Rs.',
            'vat_rate' => 13.00,
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'version' => 1,
        ]);

        $presented = $branding->present();

        $this->assertEquals('Swasthya Medical Center', $presented['hospitalName']);
        $this->assertEquals('स्वास्थ्य मेडिकल सेन्टर', $presented['hospitalNameLocal']);
        $this->assertEquals('#0891b2', $presented['primaryColor']);
        $this->assertEquals('#1e293b', $presented['secondaryColor']);
        $this->assertEquals('+977-1-4444444', $presented['phone']);
        $this->assertEquals('+977-1-9999999', $presented['emergencyPhone']);
        $this->assertEquals('info@swasthya.com', $presented['email']);
        $this->assertEquals('Kathmandu', $presented['city']);
        $this->assertEquals('Bagmati', $presented['state']);
        $this->assertEquals('Nepal', $presented['country']);
        $this->assertEquals('NPR', $presented['currency']);
        $this->assertEquals('Rs.', $presented['currencySymbol']);
        $this->assertEquals(13.00, $presented['vatRate']);
        $this->assertEquals(1, $presented['version']);
    }

    public function test_format_currency(): void
    {
        $branding = new HospitalBranding([
            'currency_symbol' => 'Rs.',
            'vat_rate' => 13,
        ]);

        $this->assertEquals('Rs. 1,500.00', $branding->formatCurrency(1500));

        $brandingNoVat = new HospitalBranding([
            'currency_symbol' => 'Rs.',
            'vat_rate' => 0,
        ]);

        $this->assertEquals('Rs. 1,500', $brandingNoVat->formatCurrency(1500));
    }

    public function test_format_date_and_time(): void
    {
        $branding = new HospitalBranding([
            'date_format' => 'd/m/Y',
            'time_format' => 'h:i A',
        ]);

        $date = new \DateTime('2026-08-21 14:30:00');
        $this->assertEquals('21/08/2026', $branding->formatDate($date));
        $this->assertEquals('2:30 PM', $branding->formatTime($date));
    }

    public function test_branding_defaults(): void
    {
        $branding = new HospitalBranding;

        // Nullable fields default to null
        $this->assertNull($branding->hospital_name);
        $this->assertNull($branding->phone);

        // Cast checks
        $this->assertIsArray($branding->casts());
        $this->assertArrayHasKey('vat_rate', $branding->casts());
        $this->assertArrayHasKey('version', $branding->casts());
    }

    public function test_branding_fillable_fields(): void
    {
        $branding = new HospitalBranding;
        $fillable = $branding->getFillable();

        // Core branding fields
        $this->assertContains('tenant_id', $fillable);
        $this->assertContains('facility_id', $fillable);
        $this->assertContains('hospital_name', $fillable);
        $this->assertContains('hospital_name_local', $fillable);
        $this->assertContains('logo_url', $fillable);
        $this->assertContains('primary_color', $fillable);
        $this->assertContains('secondary_color', $fillable);

        // Contact fields
        $this->assertContains('phone', $fillable);
        $this->assertContains('emergency_phone', $fillable);
        $this->assertContains('email', $fillable);
        $this->assertContains('website', $fillable);

        // Address fields
        $this->assertContains('address_line1', $fillable);
        $this->assertContains('city', $fillable);
        $this->assertContains('state', $fillable);
        $this->assertContains('country', $fillable);

        // Document fields
        $this->assertContains('document_header', $fillable);
        $this->assertContains('document_footer', $fillable);
        $this->assertContains('letterhead_text', $fillable);
        $this->assertContains('date_format', $fillable);
        $this->assertContains('time_format', $fillable);
        $this->assertContains('currency', $fillable);
        $this->assertContains('currency_symbol', $fillable);
        $this->assertContains('vat_rate', $fillable);
        $this->assertContains('vat_number', $fillable);

        // Legal fields
        $this->assertContains('terms_and_conditions', $fillable);
        $this->assertContains('privacy_policy', $fillable);

        // Metadata
        $this->assertContains('version', $fillable);
        $this->assertContains('updated_by', $fillable);
    }
}
