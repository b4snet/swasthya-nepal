<?php

use App\Console\Commands\ProcessOutbox;
use App\Models\DomainEvent;
use App\Services\Events\EventDispatcher;
use App\Support\Concerns\RunsInTenantContext;
use App\Support\DatabaseTenantContext;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TENANCY.md V2 §12 — background worker tenant context restoration.
 *
 * Proves the RunsInTenantContext trait (and the commands that use it)
 * establish the transaction-local DB GUCs so forced-PostgreSQL-RLS tables
 * remain reachable from worker code, and that context is torn down after
 * every job so nothing leaks onto a reused connection.
 */

it('runs a callback inside an active transaction with tenant GUCs set', function (): void {
    $tenantId = (string) Str::uuid();

    $anonymous = new class {
        use RunsInTenantContext;

        public function run(array $context, callable $cb): mixed
        {
            return $this->runInTenantContext($context, $cb);
        }
    };

    $observed = $anonymous->run(
        ['tenant_id' => $tenantId, 'facility_id' => 'fac-1', 'user_id' => 'user-1', 'is_platform' => false],
        function () {
            return [
                'in_tx' => DB::transactionLevel() > 0,
                'tenant' => DatabaseTenantContext::current('tenant_id'),
                'facility' => DatabaseTenantContext::current('facility_id'),
                'user' => DatabaseTenantContext::current('user_id'),
                'platform' => DatabaseTenantContext::current('is_platform'),
            ];
        }
    );

    expect($observed['in_tx'])->toBeTrue()
        ->and($observed['tenant'])->toBe($tenantId)
        ->and($observed['facility'])->toBe('fac-1')
        ->and($observed['user'])->toBe('user-1')
        ->and($observed['platform'])->toBe('false');
});

it('commits changes made inside the tenant-context transaction', function (): void {
    $id = (string) Str::uuid();

    $callbackRan = false;

    $anonymous = new class {
        use RunsInTenantContext;

        public function run(array $context, callable $cb): mixed
        {
            return $this->runInTenantContext($context, $cb);
        }
    };

    $anonymous->run([], function () use (&$callbackRan, $id) {
        DB::insert(
            'insert into domain_events (id, event_type, aggregate_type, aggregate_id, payload, status, attempt_count, max_attempts, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'test', 'test', $id, '{}', 'pending', 0, 5, now(), now()]
        );
        $callbackRan = true;
    });

    expect($callbackRan)->toBeTrue()
        ->and(DB::table('domain_events')->where('id', $id)->exists())->toBeTrue();
});

it('rolls back changes when the callback throws', function (): void {
    $id = (string) Str::uuid();

    $anonymous = new class {
        use RunsInTenantContext;

        public function run(array $context, callable $cb): mixed
        {
            return $this->runInTenantContext($context, $cb);
        }
    };

    try {
        $anonymous->run([], function () use ($id) {
            DB::insert(
                'insert into domain_events (id, event_type, aggregate_type, aggregate_id, payload, status, attempt_count, max_attempts, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, 'test', 'test', $id, '{}', 'pending', 0, 5, now(), now()]
            );
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {
        // expected
    }

    expect(DB::table('domain_events')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(1);
});

it('clears DB and in-memory tenant context after run', function (): void {
    $anonymous = new class {
        use RunsInTenantContext;

        public function run(): mixed
        {
            return $this->runInTenantContext(
                ['tenant_id' => 't-1', 'user_id' => 'u-1'],
                fn () => true
            );
        }
    };

    $anonymous->run();

    expect(DatabaseTenantContext::current('tenant_id'))->toBe('')
        ->and(DatabaseTenantContext::current('user_id'))->toBe('')
        ->and(TenantContext::current()->organization)->toBeNull()
        ->and(DB::transactionLevel())->toBe(1);
});

it('builds a context array from an event row', function (): void {
    $anonymous = new class {
        use RunsInTenantContext;

        public function build(array $event): array
        {
            return $this->contextFromEvent($event);
        }
    };

    expect($anonymous->build(['tenant_id' => 't-1', 'facility_id' => 'f-1']))
        ->toBe(['tenant_id' => 't-1', 'facility_id' => 'f-1', 'is_platform' => false])
        ->and($anonymous->build([]))
        ->toBe(['tenant_id' => null, 'facility_id' => null, 'is_platform' => false]);
});

it('processes an outbox event within its stored tenant context', function (): void {
    seedIdentity();

    $event = EventDispatcher::dispatch(
        eventType: 'unregistered.event',
        aggregateType: 'test',
        aggregateId: (string) Str::uuid(),
        tenantId: 'tenant-ctx-1',
    );

    $this->artisan(ProcessOutbox::class, ['--once' => true])
        ->assertExitCode(0);

    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

it('keeps tenant GUCs clean after processing a batch', function (): void {
    seedIdentity();

    $event = EventDispatcher::dispatch(
        eventType: 'unregistered.event',
        aggregateType: 'test',
        aggregateId: (string) Str::uuid(),
        tenantId: 'tenant-ctx-2',
    );

    $this->artisan(ProcessOutbox::class, ['--once' => true])
        ->assertExitCode(0);

    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED)
        ->and(DatabaseTenantContext::current('tenant_id'))->toBe('')
        ->and(DB::transactionLevel())->toBe(1);
});
