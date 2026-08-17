<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 25 — RPM: extend the consents type CHECK to allow
 * `device_monitoring` (the data-collection consent required before any
 * device is enrolled; CLINICAL_SAFETY.md §7 — no silent device data
 * collection). The CHECK is swapped atomically (drop + re-add with the
 * full list, mirroring the original migration's constraint name).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table consents drop constraint chk_consents_type');
        DB::statement(
            "alter table consents add constraint chk_consents_type check (consent_type in ('treatment', 'data_use', 'telehealth', 'device_monitoring', 'marketing', 'research'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table consents drop constraint chk_consents_type');
        DB::statement(
            "alter table consents add constraint chk_consents_type check (consent_type in ('treatment', 'data_use', 'telehealth', 'marketing', 'research'))"
        );
    }
};
