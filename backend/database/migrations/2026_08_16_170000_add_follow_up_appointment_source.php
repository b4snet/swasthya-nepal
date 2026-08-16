<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 9 — appointment auto-creation from follow-up plans
 * (PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.15/§3.17a): a follow-up plan
 * booked via `auto-book` creates the appointment itself (patient, provider,
 * facility, planned time, type follow_up/teleconsult) instead of linking a
 * separately-booked appointment.
 *
 * The appointment's `source` — where the booking originated — gains
 * 'follow_up': an auto-created follow-up appointment is neither counter,
 * portal, nor walk-in; it originates from the clinical follow-up workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table appointments drop constraint chk_appointments_source');
        DB::statement(
            "alter table appointments add constraint chk_appointments_source check (source in ('counter', 'portal', 'walk_in', 'follow_up'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table appointments drop constraint chk_appointments_source');
        DB::statement(
            "alter table appointments add constraint chk_appointments_source check (source in ('counter', 'portal', 'walk_in'))"
        );
    }
};
