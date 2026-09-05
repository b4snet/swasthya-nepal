<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE er_events DROP CONSTRAINT chk_er_events_type");
        DB::statement("ALTER TABLE er_events ADD CONSTRAINT chk_er_events_type CHECK (event_type IN ('arrived', 'registered', 'triaged', 'reassessed', 'seen_by_doctor', 'treatment_started', 'lab_ordered', 'medication_administered', 'procedure', 'observation_started', 'disposition', 'transferred_out', 'discharged', 'immediate_treatment', 'identity_reconciled', 'other'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE er_events DROP CONSTRAINT chk_er_events_type");
        DB::statement("ALTER TABLE er_events ADD CONSTRAINT chk_er_events_type CHECK (event_type IN ('arrived', 'registered', 'triaged', 'reassessed', 'seen_by_doctor', 'treatment_started', 'lab_ordered', 'medication_administered', 'procedure', 'observation_started', 'disposition', 'transferred_out', 'discharged', 'other'))");
    }
};
