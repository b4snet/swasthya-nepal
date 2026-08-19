<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 — Expand the portal grant scope CHECK constraint to include
 * PHR data categories: medical_history, prescriptions, documents, radiology,
 * referrals, care_plans, immunizations, messaging.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE portal_access_grants DROP CONSTRAINT IF EXISTS chk_portal_grants_scope');
        DB::statement("ALTER TABLE portal_access_grants ADD CONSTRAINT chk_portal_grants_scope CHECK (data_scope IN ('appointments', 'results', 'bills', 'medical_history', 'prescriptions', 'documents', 'radiology', 'referrals', 'care_plans', 'immunizations', 'messaging'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE portal_access_grants DROP CONSTRAINT IF EXISTS chk_portal_grants_scope');
        DB::statement("ALTER TABLE portal_access_grants ADD CONSTRAINT chk_portal_grants_scope CHECK (data_scope IN ('appointments', 'results', 'bills'))");
    }
};
