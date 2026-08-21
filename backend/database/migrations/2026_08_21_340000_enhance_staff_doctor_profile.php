<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('specialty')->nullable()->after('designation')->comment('Medical specialty (e.g. Cardiology, Orthopedics)');
            $table->string('sub_specialty')->nullable()->after('specialty')->comment('Sub-specialty or focus area');
            $table->decimal('consultation_fee', 10, 2)->nullable()->after('sub_specialty')->comment('Standard consultation fee in facility currency');
            $table->integer('consultation_duration_minutes')->nullable()->default(15)->after('consultation_fee')->comment('Default slot duration in minutes');
            $table->text('bio')->nullable()->after('consultation_duration_minutes')->comment('Doctor biography / about text for patient portal');
            $table->boolean('accepts_new_patients')->default(true)->after('bio')->comment('Whether the doctor is accepting new patient registrations');
            $table->string('profile_image_url')->nullable()->after('accepts_new_patients')->comment('URL to doctor profile image');
            $table->json('available_days')->nullable()->after('profile_image_url')->comment('Array of day-of-week numbers (0=Sun..6=Sat) for quick reference');
            $table->json('consultation_types')->nullable()->after('available_days')->comment('Array of consultation type strings (opd, follow_up, teleconsult, procedure)');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropColumn([
                'specialty',
                'sub_specialty',
                'consultation_fee',
                'consultation_duration_minutes',
                'bio',
                'accepts_new_patients',
                'profile_image_url',
                'available_days',
                'consultation_types',
            ]);
        });
    }
};
