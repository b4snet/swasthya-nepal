<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling enterprise hardening:
 *  1. appointments.rescheduled_from — preserves provenance when an
 *     appointment is rescheduled (the old appointment ID).
 *  2. resource_bookings overlap trigger — prevents two active bookings
 *     for the same resource from overlapping at the DB level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->uuid('rescheduled_from')->nullable()->after('lock_version');

            $table->foreign('rescheduled_from', 'fk_appointments_rescheduled_from')
                ->references('id')->on('appointments')
                ->onDelete('set null');
        });

        // Trigger-based overlap prevention for resource_bookings:
        // prevents two non-cancelled/non-completed bookings for the same
        // (resource_type, resource_id) from overlapping in time.
        DB::statement("
            CREATE OR REPLACE FUNCTION trg_resource_bookings_no_overlap()
            RETURNS TRIGGER AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM resource_bookings
                    WHERE tenant_id = NEW.tenant_id
                      AND resource_type = NEW.resource_type
                      AND resource_id = NEW.resource_id
                      AND id <> NEW.id
                      AND status NOT IN ('cancelled', 'completed')
                      AND tstzrange(starts_at, ends_at, '[]') &&
                          tstzrange(NEW.starts_at, NEW.ends_at, '[]')
                ) THEN
                    RAISE EXCEPTION 'Resource booking overlap: % % already booked for this time range',
                        NEW.resource_type, NEW.resource_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE TRIGGER trg_resource_bookings_overlap_check
            BEFORE INSERT OR UPDATE ON resource_bookings
            FOR EACH ROW
            EXECUTE FUNCTION trg_resource_bookings_no_overlap();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_resource_bookings_overlap_check ON resource_bookings');
        DB::statement('DROP FUNCTION IF EXISTS trg_resource_bookings_no_overlap()');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['rescheduled_from']);
            $table->dropColumn('rescheduled_from');
        });
    }
};
