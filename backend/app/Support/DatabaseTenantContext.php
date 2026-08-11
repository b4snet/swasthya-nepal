<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The database-side tenant context (TENANCY.md V2 §5, §7; SECURITY.md §14).
 *
 * The PostgreSQL RLS policies read application-level GUCs (app.tenant_id,
 * app.facility_id, app.branch_id, app.user_id, app.is_platform). This helper
 * is the ONLY writer. Every value is set with set_config(..., is_local=true):
 * the setting is scoped to the current transaction, so it dies with the
 * transaction and can NEVER leak onto a reused connection, into a later
 * request, or from one queue job to another — the hard reset guarantee.
 *
 * The tenant-context middleware owns the lifecycle:
 *   begin transaction → set context → handle request → commit/rollback.
 * Long-running workers (queues, schedulers) must apply the same pattern per
 * job: wrap the job in a transaction and set the context the job carries
 * (TENANCY.md V2 §12). Artisan/CLI work with no context runs under empty
 * GUCs, which RLS treats as zero tenant access — a safe failure, never a
 * cross-tenant read.
 */
final class DatabaseTenantContext
{
    private const PREFIX = 'app.';

    public static function set(string $name, ?string $value): void
    {
        DB::statement('select set_config(?, ?, true)', [
            self::PREFIX.$name,
            $value ?? '',
        ]);
    }

    public static function setTenant(?string $tenantId): void
    {
        self::set('tenant_id', $tenantId);
    }

    public static function setFacility(?string $facilityId): void
    {
        self::set('facility_id', $facilityId);
    }

    public static function setBranch(?string $branchId): void
    {
        self::set('branch_id', $branchId);
    }

    public static function setUser(?string $userId): void
    {
        self::set('user_id', $userId);
    }

    public static function setPlatform(bool $isPlatform): void
    {
        self::set('is_platform', $isPlatform ? 'true' : 'false');
    }

    public static function current(string $name): string
    {
        $value = DB::selectOne('select current_setting(?, true) as value', [self::PREFIX.$name]);

        return (string) ($value->value ?? '');
    }

    /**
     * Reset every context GUC to its empty default. Called by the middleware
     * after commit/rollback: the LOCAL settings already died with the
     * transaction, but this guarantees a nested outer transaction (tests,
     * wrapping callers) cannot retain the settings either.
     */
    public static function resetAll(): void
    {
        foreach (['tenant_id', 'facility_id', 'branch_id', 'user_id', 'is_platform'] as $name) {
            DB::statement('select set_config(?, ?, false)', [self::PREFIX.$name, '']);
        }
    }

    /**
     * Commit the request transaction when one is active. Safe to call even
     * when an inner service already committed/rolled back to a savepoint.
     */
    public static function commitIfActive(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    /**
     * Roll back the request transaction when one is active (e.g. the
     * controller threw). Nested savepoints from inner DB::transaction calls
     * are unwound together with the request transaction.
     */
    public static function rollbackIfActive(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}
