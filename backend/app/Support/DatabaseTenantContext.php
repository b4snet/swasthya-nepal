<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
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

    /**
     * The five context keys, mirrored 1:1 into the Supabase-compatible
     * `request.jwt.claims` GUC (claim keys `app_*`) — the source the RLS
     * policies read after the Phase 2 re-key (2026_08_13_100200). Supabase
     * surfaces the signed JWT payload through this exact GUC, so the same
     * policies serve both the Laravel bridge and the native edge-function
     * signer without further changes.
     */
    private const KEYS = ['user_id', 'tenant_id', 'facility_id', 'branch_id', 'is_platform'];

    public static function set(string $name, ?string $value): void
    {
        DB::statement('select set_config(?, ?, true)', [
            self::PREFIX.$name,
            $value ?? '',
        ]);

        // Rebuild the claims JSON from the LIVE transaction-local GUCs so a
        // partial context update never leaves stale sibling claims behind.
        $claims = [];
        foreach (self::KEYS as $key) {
            $claims['app_'.$key] = self::current($key);
        }
        self::setClaims($claims);
    }

    /**
     * Write the `request.jwt.claims` GUC directly — the single entry point
     * for the Supabase-native path (Phase 3). Supabase's connection pooler
     * surfaces the verified JWT payload through exactly this GUC; an
     * edge-function signer's token lands here via JwtClaims::verify() +
     * AuthClaims::normalize(). Transaction-local like every other setting.
     *
     * Only the five RLS claim keys are written (AuthClaims::normalize); a
     * client can never smuggle extra context into the payload.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function setClaims(array $claims, ?ConnectionInterface $connection = null): void
    {
        $connection ??= DB::connection();
        $connection->statement('select set_config(?, ?, true)', [
            'request.jwt.claims',
            json_encode(AuthClaims::normalize($claims)),
        ]);
    }

    /**
     * Current claims payload (the five RLS keys), for tests and the audit
     * bridge. Returns the normalized form.
     *
     * @return array<string, string>
     */
    public static function claims(?ConnectionInterface $connection = null): array
    {
        $connection ??= DB::connection();
        $raw = (string) ($connection->selectOne('select current_setting(?, true) as value', ['request.jwt.claims'])?->value ?? '');
        $decoded = json_decode($raw, true);

        return AuthClaims::normalize(is_array($decoded) ? $decoded : []);
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
        foreach (self::KEYS as $name) {
            DB::statement('select set_config(?, ?, false)', [self::PREFIX.$name, '']);
        }
        // Empty (not absent) claims: the claim readers coalesce to '' and then
        // to NULL — zero access, never an error.
        DB::statement("select set_config('request.jwt.claims', '', false)");
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
