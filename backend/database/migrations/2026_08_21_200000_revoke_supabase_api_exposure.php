<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase Security Advisor remediation.
 *
 * Removes unnecessary PostgREST / Data API exposure from:
 *
 *   Framework infrastructure (no sensitive data, not API-exposed):
 *     cache, cache_locks, jobs, job_batches, failed_jobs, migrations
 *
 *   Authentication infrastructure (login flow requires direct DB access;
 *   security boundary is application middleware, not Data API RLS):
 *     users, refresh_tokens, mfa_challenges, password_reset_tokens,
 *     personal_access_tokens
 *
 * Also tightens the RBAC shared-metadata tables (roles, permissions,
 * role_permissions) to read-only via the Data API.
 *
 * The swasthya_app runtime role connects via direct PostgreSQL (not
 * PostgREST), so REVOKEs from anon/authenticated do NOT affect
 * application functionality.
 *
 * @see SECURITY.md §16, Supabase Security Advisor findings
 */
return new class extends Migration
{
    /**
     * Tables that should NOT be reachable through the Data API at all.
     */
    private const HIDDEN_FROM_API = [
        // Framework infrastructure
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        // Auth infrastructure
        'users',
        'refresh_tokens',
        'mfa_challenges',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /**
     * RBAC metadata tables — readable via API (SELECT only), but writes
     * must go through the application backend (middleware-enforced).
     */
    private const READONLY_VIA_API = [
        'roles',
        'permissions',
        'role_permissions',
    ];

    public function up(): void
    {
        /*
         * ── Supabase API roles ──
         *
         * Supabase Data API (PostgREST) routes requests through:
         *   - anon        — for unauthenticated requests (anon key)
         *   - authenticated — for logged-in users (bearer token)
         *
         * The swasthya_app role is NOT a PostgREST role; it connects
         * directly via the application backend's database connection.
         * Revoking from anon/authenticated has zero effect on the
         * application — it only affects the Supabase REST/GraphQL API.
         */
        $apiRoles = ['anon', 'authenticated'];

        // ── Fully hidden from Data API ──
        foreach (self::HIDDEN_FROM_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("revoke all on public.\"{$table}\" from \"{$role}\"");
            }
        }

        // ── Read-only via Data API ──
        foreach (self::READONLY_VIA_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("revoke insert, update, delete on public.\"{$table}\" from \"{$role}\"");
            }
        }

        // ── Explicit column-level lockdown for the sensitive token value ──
        // personal_access_tokens.token is a Sanctum bearer-token hash.
        // Even though the whole table is hidden from PostgREST above,
        // add an explicit REVOKE on the column as defense-in-depth.
        foreach ($apiRoles as $role) {
            DB::statement("revoke select (token) on public.personal_access_tokens from \"{$role}\"");
        }
    }

    public function down(): void
    {
        $apiRoles = ['anon', 'authenticated'];

        // ── Re-grant full access to hidden tables ──
        foreach (self::HIDDEN_FROM_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("grant select, insert, update, delete on public.\"{$table}\" to \"{$role}\"");
            }
        }

        // ── Re-grant full access to RBAC tables ──
        foreach (self::READONLY_VIA_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("grant select, insert, update, delete on public.\"{$table}\" to \"{$role}\"");
            }
        }

        // ── Re-grant column access ──
        foreach ($apiRoles as $role) {
            DB::statement("grant select (token) on public.personal_access_tokens to \"{$role}\"");
        }
    }
};
