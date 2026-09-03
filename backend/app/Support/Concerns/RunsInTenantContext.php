<?php

namespace App\Support\Concerns;

use App\Support\DatabaseTenantContext;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * TNENANCY.md V2 §12 — per-job tenant context restoration for background workers.
 *
 * Long-running processes (queues, schedulers, outbox pollers) do NOT run inside
 * the HTTP request middleware that normally establishes the DB tenant GUCs. Under
 * FORCED PostgreSQL RLS, workers running with empty GUCs get zero tenant access —
 * a safe failure but a functional blocker for any tenant-scoped work.
 *
 * This trait implements the documented pattern: wrap the payload in a transaction,
 * set the transaction-local GUCs the job carries (tenant_id, facility_id, user_id,
 * is_platform), run, then commit/rollback. Because set_config(...) is
 * is_local=true, settings die with the transaction and can never leak onto a
 * reused connection or into a later job.
 */
trait RunsInTenantContext
{
    /**
     * Execute $callback inside a transaction with the given database tenant context.
     *
     * @param  callable(): mixed  $callback
     * @param  array{tenant_id?: string|null, facility_id?: string|null, branch_id?: string|null, user_id?: string|null, is_platform?: bool|null}  $context
     */
    protected function runInTenantContext(array $context, callable $callback, ?callable $onCommit = null): mixed
    {
        $transaction = DB::beginTransaction();

        try {
            DatabaseTenantContext::setTenant($context['tenant_id'] ?? null);
            DatabaseTenantContext::setFacility($context['facility_id'] ?? null);
            DatabaseTenantContext::setBranch($context['branch_id'] ?? null);
            DatabaseTenantContext::setUser($context['user_id'] ?? null);
            DatabaseTenantContext::setPlatform($context['is_platform'] ?? false);

            $result = $callback();

            DB::commit();
            $onCommit?->call($this);

            return $result;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        } finally {
            DatabaseTenantContext::resetAll();
            TenantContext::setCurrent(null);
        }
    }

    /**
     * Restore context from a domain event's stored tenant/facility columns.
     *
     * @param  array{tenant_id?: string|null, facility_id?: string|null}  $event
     */
    protected function contextFromEvent(array $event): array
    {
        return [
            'tenant_id' => $event['tenant_id'] ?? null,
            'facility_id' => $event['facility_id'] ?? null,
            'is_platform' => false,
        ];
    }
}
