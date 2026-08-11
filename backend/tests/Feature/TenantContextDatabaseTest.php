<?php

use App\Support\DatabaseTenantContext;
use Tests\Support\Identity;

/**
 * The database context lifecycle (TENANCY.md V2 §7): every request runs in
 * one transaction with LOCAL RLS GUCs that die with the transaction. After a
 * request the settings are gone — a reused connection, a pooled worker, or a
 * later request can never observe another request's tenant.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('resets the tenant GUC after a request completes', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations')
        ->assertOk();

    // The middleware committed its transaction; the LOCAL GUC died with it.
    expect(DatabaseTenantContext::current('tenant_id'))->toBe('');
});

it('does not leak tenant context across sequential requests of different tenants', function () {
    $orgA = Identity::organization();
    $orgB = Identity::organization();
    $adminA = Identity::user(['email' => 'a@ctx.test']);
    $adminB = Identity::user(['email' => 'b@ctx.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    Identity::assign($adminB, 'org_admin', $orgB);

    $this->withToken(Identity::tokenFor($adminA))->getJson('/api/v1/organizations')->assertOk();
    expect(DatabaseTenantContext::current('tenant_id'))->toBe('');

    $this->withToken(Identity::tokenFor($adminB))->getJson('/api/v1/organizations')->assertOk();
    expect(DatabaseTenantContext::current('tenant_id'))->toBe('');

    $this->withToken(Identity::tokenFor($adminA))->getJson('/api/v1/organizations')->assertOk();
    expect(DatabaseTenantContext::current('tenant_id'))->toBe('');
});

it('leaves the database context empty for unauthenticated requests', function () {
    $this->getJson('/api/v1/health/live')->assertOk();

    expect(DatabaseTenantContext::current('tenant_id'))->toBe('')
        ->and(DatabaseTenantContext::current('user_id'))->toBe('')
        ->and(DatabaseTenantContext::current('is_platform'))->toBe('');
});
