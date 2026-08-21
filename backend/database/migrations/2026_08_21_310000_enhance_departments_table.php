<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enhance the departments table with configurable hospital structure fields.
 *
 * Adds:
 * - department_type: medical, supportive, surgical, administrative, etc.
 * - operating_hours: JSON schedule (day → open/close)
 * - appointment_availability: JSON (booking window, slot duration, etc.)
 * - queue_settings: JSON (token prefix, display order, max queue size)
 * - responsible_roles: JSON array of role codes
 * - sort_order: for manual ordering
 * - description: optional department description
 * - phone: department phone/extension
 * - location: physical location hint
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('department_type', 50)->default('medical')->after('status');
            $table->text('description')->nullable()->after('department_type');
            $table->string('phone', 50)->nullable()->after('description');
            $table->string('location', 200)->nullable()->after('phone');
            $table->json('operating_hours')->nullable()->after('location');
            $table->json('appointment_availability')->nullable()->after('operating_hours');
            $table->json('queue_settings')->nullable()->after('appointment_availability');
            $table->json('responsible_roles')->nullable()->after('queue_settings');
            $table->integer('sort_order')->default(0)->after('responsible_roles');
        });

        // Update the check constraint to include new department_type values
        DB::statement(
            'ALTER TABLE departments DROP CONSTRAINT IF EXISTS chk_departments_type'
        );
        DB::statement(
            'ALTER TABLE departments ADD CONSTRAINT chk_departments_type '
            ."CHECK (department_type IN ('medical', 'supportive', 'surgical', 'administrative', 'emergency', 'diagnostic', 'pharmacy', 'laboratory', 'radiology', 'other'))"
        );
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn([
                'department_type', 'description', 'phone', 'location',
                'operating_hours', 'appointment_availability', 'queue_settings',
                'responsible_roles', 'sort_order',
            ]);
        });
    }
};
