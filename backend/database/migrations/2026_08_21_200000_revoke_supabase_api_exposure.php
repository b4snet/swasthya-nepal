<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase Security Advisor remediation.
 *
 * Removes unnecessary PostgREST / Data API exposure from framework and auth tables.
 * Only executes REVOKE when the target role exists (Supabase-specific roles).
 *
 * @see SECURITY.md §16, Supabase Security Advisor findings
 */
return new class extends Migration
{
    private const HIDDEN_FROM_API = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'users', 'refresh_tokens', 'mfa_challenges', 'password_reset_tokens',
        'personal_access_tokens',
    ];

    private const READONLY_VIA_API = [
        'roles', 'permissions', 'role_permissions',
    ];

    public function up(): void
    {
        $apiRoles = $this->getExistingApiRoles();
        if (empty($apiRoles)) {
            return;
        }

        foreach (self::HIDDEN_FROM_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("REVOKE ALL ON public.\"{$table}\" FROM \"{$role}\"");
            }
        }

        foreach (self::READONLY_VIA_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("REVOKE INSERT, UPDATE, DELETE ON public.\"{$table}\" FROM \"{$role}\"");
            }
        }

        foreach ($apiRoles as $role) {
            DB::statement("REVOKE SELECT (token) ON public.personal_access_tokens FROM \"{$role}\"");
        }
    }

    public function down(): void
    {
        $apiRoles = $this->getExistingApiRoles();
        if (empty($apiRoles)) {
            return;
        }

        foreach (self::HIDDEN_FROM_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON public.\"{$table}\" TO \"{$role}\"");
            }
        }

        foreach (self::READONLY_VIA_API as $table) {
            foreach ($apiRoles as $role) {
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON public.\"{$table}\" TO \"{$role}\"");
            }
        }

        foreach ($apiRoles as $role) {
            DB::statement("GRANT SELECT (token) ON public.personal_access_tokens TO \"{$role}\"");
        }
    }

    private function getExistingApiRoles(): array
    {
        $candidates = ['anon', 'authenticated'];
        $existing = [];

        foreach ($candidates as $role) {
            $result = DB::select('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]);
            if (! empty($result)) {
                $existing[] = $role;
            }
        }

        return $existing;
    }
};
