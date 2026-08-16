<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 23 — RLS for the Interoperability readiness surface
 * (integrations, integration_events, egress_allowlist, oauth_partners,
 * oauth_partner_tokens).
 *
 * Every table is TENANT tier (tenant-scoped infrastructure with no
 * facility_id — DATABASE.md §3.42, INTEROPERABILITY.md §11: provider
 * credentials, outbox/inbox, allowlists, and partner tokens are
 * tenant-bound; a message cannot carry another tenant's context):
 *
 *   SELECT/UPDATE/DELETE using  tenant_id = <tenant>
 *   INSERT with check (true)    — the established, documented boundary
 *
 * The expressions read ONLY the Supabase-compatible `request.jwt.claims` GUC
 * through the stable helpers (2026_08_13_100200). The runtime role
 * (swasthya_app, NOBYPASSRLS) is bound; FORCE binds the table owner as well
 * (Phase 1 hardening). Partner-token requests project ONLY the tenant claim
 * (ResolvePartnerContext) — a partner can never cross into another tenant.
 *
 * Policy count added: 5 tables × 4 policies = 20 (448 → 468).
 * Scoped matrix: 113 → 118 tables (still 15 off).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'integrations', 'integration_events', 'egress_allowlist',
        'oauth_partners', 'oauth_partner_tokens',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            $using = 'tenant_id = public.swasthya_rls_tenant_id()';
            $this->createPolicies($table, $using);
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
            foreach (['select', 'insert', 'update', 'delete'] as $op) {
                DB::statement("drop policy if exists p_rls_{$table}_{$op} on {$table}");
            }
        }
    }

    private function createPolicies(string $table, string $using): void
    {
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");
    }
};
