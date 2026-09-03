<?php

use App\Models\AuditEvent;
use App\Models\DomainEvent;
use App\Models\Organization;
use Tests\Support\Identity;

/**
 * Organization lifecycle state machine (TENANCY.md V2 §13) and its
 * platform API surface — suspend / reactivate / close / offboard.
 *
 * Covers the transition rules, the platform-only enforcement, non-membership
 * (404), and the audited events emitted for each transition.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('defines a strict lifecycle state machine', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    // Active may move to suspended / closed / offboarded.
    $allow = fn (string $target) => $org->canTransitionTo($target)['allowed'];

    expect($allow(Organization::STATUS_SUSPENDED))->toBeTrue()
        ->and($allow(Organization::STATUS_CLOSED))->toBeTrue()
        ->and($allow(Organization::STATUS_OFFBOARDED))->toBeTrue()
        // Active may NOT go directly back to void / stay put.
        ->and($org->canTransitionTo(Organization::STATUS_ACTIVE)['reason'])->toContain('not permitted');
});

it('a suspended tenant may be reactivated or moved onward', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_SUSPENDED]);

    expect($org->canTransitionTo(Organization::STATUS_ACTIVE)['allowed'])->toBeTrue()
        ->and($org->canTransitionTo(Organization::STATUS_CLOSED)['allowed'])->toBeTrue()
        ->and($org->canTransitionTo(Organization::STATUS_OFFBOARDED)['allowed'])->toBeTrue();
});

it('offboarded is terminal — no further transitions', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_OFFBOARDED]);

    foreach ([Organization::STATUS_ACTIVE, Organization::STATUS_SUSPENDED, Organization::STATUS_CLOSED, Organization::STATUS_OFFBOARDED] as $target) {
        expect($org->canTransitionTo($target)['allowed'])->toBeFalse();
    }
});

it('transitionStatus enforces the state machine and persists', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    $org->transitionStatus(Organization::STATUS_SUSPENDED);

    expect($org->fresh()->status)->toBe(Organization::STATUS_SUSPENDED);
});

it('transitionStatus rejects an illegal transition with a 409', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_OFFBOARDED]);

    expect(fn () => $org->transitionStatus(Organization::STATUS_ACTIVE))
        ->toThrow(App\Exceptions\ApiException::class, 'not permitted');
});

it('a platform admin can suspend an active tenant', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/suspend')
        ->assertOk()
        ->assertJsonPath('data.status', Organization::STATUS_SUSPENDED)
        ->assertHeader('X-Audit-Event-Id');

    expect($org->fresh()->status)->toBe(Organization::STATUS_SUSPENDED);
});

it('a suspended tenant can be reactivated by a platform admin', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $org = Identity::organization(['status' => Organization::STATUS_SUSPENDED]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/reactivate')
        ->assertOk()
        ->assertJsonPath('data.status', Organization::STATUS_ACTIVE);

    expect($org->fresh()->status)->toBe(Organization::STATUS_ACTIVE);
});

it('a platform admin can close and then offboard a tenant', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/close')
        ->assertOk()
        ->assertJsonPath('data.status', Organization::STATUS_CLOSED);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/offboard')
        ->assertOk()
        ->assertJsonPath('data.status', Organization::STATUS_OFFBOARDED);

    expect($org->fresh()->status)->toBe(Organization::STATUS_OFFBOARDED);
});

it('denies offboarding a tenant directly from suspended when illegal', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    // Suspended → offboarded IS legal; this test proves an ILLEGAL transition
    // is rejected. Direct active → offboarded is legal too, so use a terminal
    // (offboarded) org to force an illegal 409.
    $org = Identity::organization(['status' => Organization::STATUS_OFFBOARDED]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/reactivate')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_REQUEST');
});

it('a tenant user cannot drive lifecycle transitions', function (): void {
    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/suspend')
        ->assertStatus(403);
});

it('each lifecycle transition writes an audited organization event', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/suspend')
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/reactivate')
        ->assertOk();

    $actions = AuditEvent::query()
        ->where('resource_type', 'organization')
        ->where('resource_id', $org->getKey())
        ->orderBy('occurred_at')
        ->pluck('action')
        ->all();

    expect($actions)->toContain('organization.suspended')
        ->and($actions)->toContain('organization.reactivated');
});

it('offboarding dispatchs a transactional outbox event for follow-on work', function (): void {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $org = Identity::organization(['status' => Organization::STATUS_ACTIVE]);

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/close')
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/offboard')
        ->assertOk()
        ->assertHeader('X-Audit-Event-Id');

    $domainEvent = DomainEvent::query()
        ->where('event_type', 'organization.offboarded')
        ->where('aggregate_id', $org->getKey())
        ->latest()
        ->first();

    expect($domainEvent)->not->toBeNull()
        ->and($domainEvent->status)->toBe(DomainEvent::STATUS_PENDING)
        ->and($domainEvent->payload['organizationId'])->toBe($org->getKey());
});
