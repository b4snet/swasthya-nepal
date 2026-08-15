<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROGRAM PHASE 1 hardening — FORCE ROW LEVEL SECURITY on the 37
 * tenant-scoped tables (SECURITY.md §8, TENANCY.md §6.2).
 *
 * The runtime role (`swasthya_app`) is NOBYPASSRLS and owns no tables, so it
 * is bound by RLS whether or not FORCE is set. FORCE additionally binds the
 * TABLE OWNER (the `swasthya` schema role locally, `postgres` on Supabase):
 * an owner session with no tenant claims reads zero tenant rows, so a leaked
 * owner credential, a misrouted maintenance script, or a plain pg_restore
 * can no longer silently bypass the tenant boundary. Legitimate
 * administrative operations that must read tenant data set the
 * `request.jwt.claims` GUC (the same server-issued context the middleware
 * writes per request) exactly like the application role.
 *
 * This migration only flips the FORCE flag on the existing scoped matrix —
 * it creates no tables, no columns, and no policies. The scoped set must
 * stay identical to the enable migration
 * (2026_08_11_100100_enable_row_level_security.php) and its re-key
 * (2026_08_13_100200_rekey_rls_to_jwt_claims.php): 14 tenant-only, 14
 * tenant+facility, 5 tenant+facility+branch, plus the four special-policy
 * tables (facilities, audit_events, role_assignments, support_sessions).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_ONLY_TABLES = [
        'payers',
        'mrn_counters',
        'patient_identifiers',
        'patient_contacts',
        'insurance_policies',
        'patient_documents',
        'consents',
        'patient_timeline_entries',
        'diagnoses',
        'clinical_notes',
        'prescriptions',
        'prescription_lines',
        'invoice_lines',
        'payment_allocations',
    ];

    /** @var list<string> */
    private const TENANT_FACILITY_TABLES = [
        'staff',
        'services',
        'facility_settings',
        'schedule_templates',
        'schedule_exceptions',
        'appointments',
        'token_counters',
        'encounters',
        'medications',
        'charges',
        'invoices',
        'payments',
        'branches',
        'patients',
    ];

    /** @var list<string> */
    private const TENANT_FACILITY_BRANCH_TABLES = [
        'departments',
        'locations',
        'wards',
        'rooms',
        'beds',
    ];

    /** @var list<string> */
    private const SPECIAL_TABLES = [
        'facilities',
        'audit_events',
        'role_assignments',
        'support_sessions',
    ];

    public function up(): void
    {
        foreach ($this->scopedTables() as $table) {
            DB::statement("alter table public.\"{$table}\" force row level security");
        }
    }

    public function down(): void
    {
        foreach ($this->scopedTables() as $table) {
            DB::statement("alter table public.\"{$table}\" no force row level security");
        }
    }

    /**
     * @return list<string>
     */
    private function scopedTables(): array
    {
        return array_merge(
            self::TENANT_ONLY_TABLES,
            self::TENANT_FACILITY_TABLES,
            self::TENANT_FACILITY_BRANCH_TABLES,
            self::SPECIAL_TABLES,
        );
    }
};
