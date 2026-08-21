<?php

namespace Tests\Feature;

use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doctor schedule and physician profile tests (Phase 79).
 */
class DoctorScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_model_accepts_doctor_fields(): void
    {
        $staff = new Staff([
            'specialty' => 'Cardiology',
            'sub_specialty' => 'Interventional Cardiology',
            'consultation_fee' => 1500.00,
            'consultation_duration_minutes' => 30,
            'bio' => 'Experienced cardiologist',
            'accepts_new_patients' => true,
            'available_days' => [1, 2, 3, 4, 5],
            'consultation_types' => ['opd', 'follow_up', 'teleconsult'],
        ]);

        $this->assertEquals('Cardiology', $staff->specialty);
        $this->assertEquals('Interventional Cardiology', $staff->sub_specialty);
        $this->assertEquals(1500.00, $staff->consultation_fee);
        $this->assertEquals(30, $staff->consultation_duration_minutes);
        $this->assertEquals('Experienced cardiologist', $staff->bio);
        $this->assertTrue($staff->accepts_new_patients);
        $this->assertEquals([1, 2, 3, 4, 5], $staff->available_days);
        $this->assertEquals(['opd', 'follow_up', 'teleconsult'], $staff->consultation_types);
    }

    public function test_staff_fillable_includes_doctor_fields(): void
    {
        $staff = new Staff;
        $fillable = $staff->getFillable();

        $this->assertContains('specialty', $fillable);
        $this->assertContains('sub_specialty', $fillable);
        $this->assertContains('consultation_fee', $fillable);
        $this->assertContains('consultation_duration_minutes', $fillable);
        $this->assertContains('bio', $fillable);
        $this->assertContains('accepts_new_patients', $fillable);
        $this->assertContains('profile_image_url', $fillable);
        $this->assertContains('available_days', $fillable);
        $this->assertContains('consultation_types', $fillable);
    }

    public function test_staff_casts_array_fields(): void
    {
        $staff = new Staff;
        $casts = $staff->casts();

        $this->assertArrayHasKey('available_days', $casts);
        $this->assertEquals('array', $casts['available_days']);
        $this->assertArrayHasKey('consultation_types', $casts);
        $this->assertEquals('array', $casts['consultation_types']);
        $this->assertArrayHasKey('consultation_fee', $casts);
        $this->assertArrayHasKey('consultation_duration_minutes', $casts);
        $this->assertArrayHasKey('accepts_new_patients', $casts);
    }

    public function test_doctor_schedule_constants(): void
    {
        // Verify Staff status constants still work
        $this->assertEquals('active', Staff::STATUS_ACTIVE);
        $this->assertEquals('on_leave', Staff::STATUS_ON_LEAVE);
        $this->assertEquals('departed', Staff::STATUS_DEPARTED);
    }

    public function test_schedule_template_model_supports_doctor_scheduling(): void
    {
        $template = new ScheduleTemplate;
        $this->assertNotNull($template);

        // Template can be created with required fields
        $this->assertContains('staff_id', $template->getFillable());
        $this->assertContains('day_of_week', $template->getFillable());
        $this->assertContains('starts_at', $template->getFillable());
        $this->assertContains('ends_at', $template->getFillable());
        $this->assertContains('capacity', $template->getFillable());
        $this->assertContains('slot_minutes', $template->getFillable());
        $this->assertEquals('active', ScheduleTemplate::STATUS_ACTIVE);
        $this->assertEquals('inactive', ScheduleTemplate::STATUS_INACTIVE);
    }

    public function test_schedule_exception_model_supports_leave_holidays(): void
    {
        $exception = new ScheduleException;
        $this->assertNotNull($exception);

        $this->assertContains('staff_id', $exception->getFillable());
        $this->assertContains('exception_date', $exception->getFillable());
        $this->assertContains('reason', $exception->getFillable());
        $this->assertEquals('active', ScheduleException::STATUS_ACTIVE);
        $this->assertEquals('cancelled', ScheduleException::STATUS_CANCELLED);
    }
}
