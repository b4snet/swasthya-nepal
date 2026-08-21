<?php

namespace Tests\Feature;

use App\Models\CommunicationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Communication template tests (Phase 81).
 */
class CommunicationTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_categories(): void
    {
        $categories = CommunicationTemplate::categories();

        $this->assertIsArray($categories);
        $this->assertArrayHasKey('appointment', $categories);
        $this->assertArrayHasKey('followup', $categories);
        $this->assertArrayHasKey('result', $categories);
        $this->assertArrayHasKey('billing', $categories);
        $this->assertArrayHasKey('discharge', $categories);
        $this->assertArrayHasKey('portal', $categories);
        $this->assertArrayHasKey('general', $categories);
    }

    public function test_template_types(): void
    {
        $types = CommunicationTemplate::types();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('confirmation', $types);
        $this->assertArrayHasKey('reminder', $types);
        $this->assertArrayHasKey('missed', $types);
        $this->assertArrayHasKey('invitation', $types);
        $this->assertArrayHasKey('notification', $types);
        $this->assertArrayHasKey('alert', $types);
    }

    public function test_render_replaces_variables(): void
    {
        $template = new CommunicationTemplate([
            'body_template' => 'Dear {{patient_name}}, your appointment with {{doctor_name}} is on {{date}}.',
            'subject' => 'Appointment {{date}}',
            'sms_message' => 'Hi {{patient_name}}, appt on {{date}}.',
            'whatsapp_message' => 'Hello {{patient_name}}, see you on {{date}}.',
        ]);

        $rendered = $template->render([
            'patient_name' => 'Ram Bahadur',
            'doctor_name' => 'Dr. Sharma',
            'date' => '2026-08-25',
        ]);

        $this->assertStringContainsString('Ram Bahadur', $rendered['body']);
        $this->assertStringContainsString('Dr. Sharma', $rendered['body']);
        $this->assertStringContainsString('2026-08-25', $rendered['body']);
        $this->assertStringContainsString('2026-08-25', $rendered['subject']);
        $this->assertStringContainsString('Ram Bahadur', $rendered['sms']);
        $this->assertStringContainsString('Ram Bahadur', $rendered['whatsapp']);
    }

    public function test_render_returns_null_for_missing_channel_content(): void
    {
        $template = new CommunicationTemplate([
            'body_template' => 'Hello {{name}}',
            'sms_message' => null,
            'whatsapp_message' => null,
        ]);

        $rendered = $template->render(['name' => 'Test']);

        $this->assertNull($rendered['sms']);
        $this->assertNull($rendered['whatsapp']);
    }

    public function test_present_returns_all_fields(): void
    {
        $template = new CommunicationTemplate([
            'id' => 'test-id',
            'tenant_id' => 'tenant-1',
            'code' => 'appt_reminder',
            'name' => 'Appointment Reminder',
            'category' => 'appointment',
            'type' => 'reminder',
            'channel_in_app' => true,
            'channel_email' => true,
            'channel_sms' => false,
            'channel_whatsapp' => false,
            'subject' => 'Reminder: {{date}}',
            'body_template' => 'Dear {{patient_name}}, reminder for {{date}}.',
            'variables' => [['name' => 'patient_name', 'label' => 'Patient', 'type' => 'string', 'required' => true, 'example' => 'Ram']],
            'retry_count' => 3,
            'retry_delay_minutes' => 60,
            'enabled' => true,
            'locale' => 'en',
        ]);

        $presented = $template->present();

        $this->assertEquals('appt_reminder', $presented['code']);
        $this->assertEquals('Appointment Reminder', $presented['name']);
        $this->assertEquals('appointment', $presented['category']);
        $this->assertEquals('reminder', $presented['type']);
        $this->assertTrue($presented['channels']['inApp']);
        $this->assertTrue($presented['channels']['email']);
        $this->assertFalse($presented['channels']['sms']);
        $this->assertTrue($presented['enabled']);
        $this->assertEquals(3, $presented['retryCount']);
        $this->assertIsArray($presented['variables']);
    }

    public function test_template_fillable_fields(): void
    {
        $template = new CommunicationTemplate;
        $fillable = $template->getFillable();

        $this->assertContains('tenant_id', $fillable);
        $this->assertContains('code', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('category', $fillable);
        $this->assertContains('type', $fillable);
        $this->assertContains('channel_in_app', $fillable);
        $this->assertContains('channel_email', $fillable);
        $this->assertContains('channel_sms', $fillable);
        $this->assertContains('channel_whatsapp', $fillable);
        $this->assertContains('subject', $fillable);
        $this->assertContains('body_template', $fillable);
        $this->assertContains('whatsapp_message', $fillable);
        $this->assertContains('sms_message', $fillable);
        $this->assertContains('variables', $fillable);
        $this->assertContains('retry_count', $fillable);
        $this->assertContains('retry_delay_minutes', $fillable);
        $this->assertContains('enabled', $fillable);
        $this->assertContains('locale', $fillable);
    }

    public function test_template_casts(): void
    {
        $template = new CommunicationTemplate;
        $casts = $template->casts();

        $this->assertArrayHasKey('channel_in_app', $casts);
        $this->assertEquals('boolean', $casts['channel_in_app']);
        $this->assertArrayHasKey('variables', $casts);
        $this->assertEquals('array', $casts['variables']);
        $this->assertArrayHasKey('retry_count', $casts);
        $this->assertEquals('integer', $casts['retry_count']);
    }
}
