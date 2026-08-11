<?php

use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RoleAssignment;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Platform administration boundaries (TENANCY.md V2 §8): a platform
 * administrator manages the platform — never tenant business data without an
 * explicit audited support session. No "bypass everything" permission exists.
 */
beforeEach(function (): void {
    seedIdentity();
});

function superadminUser(): User
{
    $super = Identity::user();
    Identity::assign($super, 'superadmin');

    return $super;
}

it('a platform administrator cannot reach tenant data without a support session', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $super = superadminUser();

    // Patient reads are tenant-scope: denied in platform context.
    $this->withToken(Identity::tokenFor($super))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/patients')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('a platform administrator cannot grant tenant roles through the org endpoint', function () {
    $org = Identity::organization();
    $target = Identity::user();
    $super = superadminUser();

    $this->withToken(Identity::tokenFor($super))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'org_admin',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('provisions a new tenant (facility + initial admin + org_admin) with one audited event', function () {
    $super = superadminUser();
    $org = Identity::organization();

    $response = $this->withToken(Identity::tokenFor($super))
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/provision', [
            'facilityName' => 'Central Hospital',
            'facilityCode' => 'central',
            'adminEmail' => 'first.admin@two.test',
            'adminPassword' => 'strong-initial-password-42',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    expect($response->json('data.status'))->toBe('provisioned')
        ->and(Facility::query()->where('tenant_id', $org->getKey())->count())->toBe(1)
        ->and(RoleAssignment::query()->where('tenant_id', $org->getKey())->where('status', 'active')->count())->toBe(1)
        ->and(RoleAssignment::query()->where('tenant_id', $org->getKey())->whereHas('role', fn ($q) => $q->where('code', 'org_admin'))->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'organization.provisioned')->where('tenant_id', $org->getKey())->count())->toBe(1);

    // A tenant user cannot provision.
    $admin = User::query()->where('email', 'first.admin@two.test')->firstOrFail();
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/provision', [
            'facilityName' => 'Sneaky',
            'facilityCode' => 'sneaky',
            'adminEmail' => 'x@two.test',
            'adminPassword' => 'strong-initial-password-42',
        ])
        ->assertStatus(403);

    // Re-provisioning the same org conflicts.
    $this->withToken(Identity::tokenFor($super))
        ->postJson('/api/v1/platform/organizations/'.$org->getKey().'/provision', [
            'facilityName' => 'Second',
            'facilityCode' => 'second',
            'adminEmail' => 'y@two.test',
            'adminPassword' => 'strong-initial-password-42',
        ])
        ->assertStatus(409);
});

it('opens a support session and gains read-only tenant access while it is active', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $super = superadminUser();
    $token = Identity::tokenFor($super);

    // Open the session: explicit target, reason, expiry; audited with tenant.
    $opened = $this->withToken($token)
        ->postJson('/api/v1/platform/support-sessions', [
            'organizationId' => $org->getKey(),
            'facilityId' => $facility->getKey(),
            'reason' => 'Investigating a reported registration incident.',
            'expiresInMinutes' => 60,
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    $sessionId = $opened->json('data.id');
    expect($opened->json('meta.context.tenantId'))->toBeNull() // opened in platform context
        ->and(AuditEvent::query()->where('action', 'support_session.opened')->where('tenant_id', $org->getKey())->count())->toBe(1);

    // With the active session, the platform admin becomes a read-only
    // support agent inside the tenant: patients are visible…
    $this->withToken($token)
        ->getJson('/api/v1/organizations/'.$org->getKey().'/patients')
        ->assertOk()
        ->assertJsonPath('meta.context.tenantId', $org->getKey())
        ->assertJsonPath('meta.context.facilityId', $facility->getKey());

    // …but writes are not: support_agent has no patient:register.
    $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'fullName' => 'Should Not Register',
            'dateOfBirth' => '1990-01-01',
            'sex' => 'female',
            'phone' => '+977-9800000000',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // Actions during the session are attributable to it.
    expect(AuditEvent::query()->where('support_session_id', $sessionId)->exists())->toBeTrue();

    // End the session: access disappears with it.
    $this->withToken($token)
        ->postJson('/api/v1/platform/support-sessions/'.$sessionId.'/end')
        ->assertStatus(204);

    $this->withToken($token)
        ->getJson('/api/v1/organizations/'.$org->getKey().'/patients')
        ->assertStatus(403);

    expect(AuditEvent::query()->where('action', 'support_session.ended')->where('tenant_id', $org->getKey())->count())->toBe(1);
});

it('treats an expired support session as no access', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $super = superadminUser();
    $token = Identity::tokenFor($super);

    $session = SupportSession::query()->create([
        'user_id' => $super->getKey(),
        'organization_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'reason' => 'Expired session fixture for testing.',
        'status' => SupportSession::STATUS_ACTIVE,
        'opened_at' => now()->subHours(2),
        'expires_at' => now()->subHour(), // already past
        'correlation_id' => (string) Str::uuid(),
    ]);

    $this->withToken($token)
        ->getJson('/api/v1/organizations/'.$org->getKey().'/patients')
        ->assertStatus(403);

    expect(SupportSession::query()->where('id', $session->getKey())->exists())->toBeTrue();
});

it('manages platform-scope role assignments through the platform endpoint only', function () {
    $org = Identity::organization();
    $super = superadminUser();
    $target = Identity::user();
    $token = Identity::tokenFor($super);

    $grant = $this->withToken($token)
        ->postJson('/api/v1/platform/users/'.$target->getKey().'/assignments', ['roleCode' => 'superadmin'])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    $assignmentId = $grant->json('data.id');
    expect(AuditEvent::query()->where('action', 'role_assignment.granted')->where('resource_id', $assignmentId)->count())->toBe(1);

    // A tenant user cannot use the platform endpoint.
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/platform/users/'.$target->getKey().'/assignments', ['roleCode' => 'superadmin'])
        ->assertStatus(403);

    // Only platform roles may be granted here.
    $this->withToken($token)
        ->postJson('/api/v1/platform/users/'.$target->getKey().'/assignments', ['roleCode' => 'org_admin'])
        ->assertStatus(422);

    $this->withToken($token)
        ->deleteJson('/api/v1/platform/users/'.$target->getKey().'/assignments/'.$assignmentId)
        ->assertStatus(204);

    expect(AuditEvent::query()->where('action', 'role_assignment.revoked')->where('resource_id', $assignmentId)->count())->toBe(1);
});
