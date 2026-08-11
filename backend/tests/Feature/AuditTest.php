<?php

use App\Models\AuditEvent;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Audit foundation (MASTER_RULES.md §19, DATABASE.md §3.36, TESTING_STRATEGY
 * §4.9): every mutation carries X-Audit-Event-Id; events record their full
 * context; the hash chain is append-only and tamper-evident; reads are
 * tenant-scoped.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('every mutation returns X-Audit-Event-Id and writes a contextual event', function () {
    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'Central',
            'code' => 'central',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    $event = AuditEvent::query()->findOrFail($response->headers->get('X-Audit-Event-Id'));

    expect($event->action)->toBe('facility.created')
        ->and($event->resource_type)->toBe('facility')
        ->and($event->tenant_id)->toBe($org->getKey())
        ->and($event->actor_id)->toBe($admin->getKey())
        ->and($event->actor_email)->toBe($admin->email)
        ->and($event->correlation_id)->not->toBeNull()
        ->and($event->ip_address)->not->toBeNull();
});

it('the audit chain is linear and verifies clean after normal writes', function () {
    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    app(AuditLogger::class)->record('test.first', 'probe', null, ['n' => 1]);
    app(AuditLogger::class)->record('test.second', 'probe', null, ['n' => 2]);
    app(AuditLogger::class)->record('test.third', 'probe', null, ['n' => 3]);

    $events = AuditEvent::query()->orderBy('occurred_at')->get();

    expect($events)->toHaveCount(3)
        ->and($events[0]->prev_hash)->toBeNull()
        ->and($events[1]->prev_hash)->toBe($events[0]->event_hash)
        ->and($events[2]->prev_hash)->toBe($events[1]->event_hash)
        ->and(AuditEvent::verifyChain())->toBe([]);
});

it('any tampering with a stored event breaks the chain', function () {
    app(AuditLogger::class)->record('test.first', 'probe', null, ['n' => 1]);
    app(AuditLogger::class)->record('test.second', 'probe', null, ['n' => 2]);

    $first = AuditEvent::query()->orderBy('occurred_at')->firstOrFail();

    // Simulate an in-database modification: payload rewritten, actor changed.
    DB::table('audit_events')
        ->where('id', $first->getKey())
        ->update([
            'payload' => json_encode(['n' => 999], JSON_THROW_ON_ERROR),
            'actor_email' => 'attacker@example.test',
        ]);

    $broken = AuditEvent::verifyChain();

    expect($broken)->not->toBeEmpty();
});

it('the chain is global and verifies across interleaved tenants', function () {
    $orgA = Identity::organization();
    $orgB = Identity::organization();

    TenantContext::setCurrent(new TenantContext(Identity::user(), false, $orgA, null, collect()));
    app(AuditLogger::class)->record('test.a', 'probe', null, []);
    TenantContext::setCurrent(new TenantContext(Identity::user(), false, $orgB, null, collect()));
    app(AuditLogger::class)->record('test.b', 'probe', null, []);
    TenantContext::setCurrent(new TenantContext(Identity::user(), false, $orgA, null, collect()));
    app(AuditLogger::class)->record('test.c', 'probe', null, []);

    // The chain is GLOBAL by nature (each row covers its global predecessor;
    // TENANCY.md §16 — backups are global too). Interleaving tenants must not
    // break it, and each event carries its correct tenant for scoped reads.
    expect(AuditEvent::verifyChain())->toBe([])
        ->and(AuditEvent::query()->where('tenant_id', $orgA->getKey())->count())->toBe(2)
        ->and(AuditEvent::query()->where('tenant_id', $orgB->getKey())->count())->toBe(1);
});

it('audit event payloads never carry secrets or credentials', function () {
    $org = Identity::organization();
    $admin = Identity::user(['email' => 'nophi@one.test']);
    Identity::assign($admin, 'org_admin', $org);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nophi@one.test',
        'password' => Identity::PASSWORD,
    ])->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'Central',
            'code' => 'central',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertCreated();

    foreach (AuditEvent::query()->get() as $event) {
        expect($event->payload)->not->toHaveKeys(['password', 'password_hash', 'token', 'accessToken', 'refreshToken'])
            ->and(json_encode($event->payload))->not->toContain(Identity::PASSWORD);
    }
});

it('login failures and successes are audited with actor context', function () {
    $org = Identity::organization();
    $admin = Identity::user(['email' => 'audit@one.test']);
    Identity::assign($admin, 'org_admin', $org);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'audit@one.test',
        'password' => 'wrong-password-value',
    ])->assertStatus(401);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'audit@one.test',
        'password' => Identity::PASSWORD,
    ])->assertOk();

    $failed = AuditEvent::query()->where('action', 'auth.login_failed')->firstOrFail();
    expect($failed->actor_email)->toBe('audit@one.test')
        ->and($failed->actor_id)->toBeNull();

    $success = AuditEvent::query()->where('action', 'auth.login')->firstOrFail();
    expect($success->actor_id)->toBe($admin->getKey())
        ->and($success->tenant_id)->toBe($org->getKey());
});
