<?php

use App\Models\Role;
use App\Support\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Tests\Support\Identity;

/**
 * The authorization matrix (MASTER_RULES.md §9.5, SECURITY.md §6): every
 * seeded role × every seeded permission must produce the exact documented
 * decision. A permission that cannot be tested is not added — this suite is
 * the gate for both.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array<string, array{0: string, 1: list<string>}>
 */
function matrixCases(): array
{
    $cases = [];

    foreach (RolePermissionSeeder::catalog() as $code => $definition) {
        $cases[$code] = [$code, $definition['permissions']];
    }

    return $cases;
}

it('grants exactly the seeded permissions to each role in its scope', function (string $roleCode, array $expected) {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user();

    $role = Role::query()->where('code', $roleCode)->firstOrFail();
    $isPlatform = $role->scope_type === Role::SCOPE_PLATFORM;

    $assignment = Identity::assign(
        $user,
        $roleCode,
        $isPlatform ? null : $org,
        $isPlatform ? null : $facility,
    );

    $context = new TenantContext(
        user: $user,
        isPlatform: $isPlatform,
        organization: $isPlatform ? null : $org,
        facility: $isPlatform ? null : $facility,
        assignments: collect([$assignment->load('role.permissions')]),
    );

    foreach (array_keys(RolePermissionSeeder::permissionCatalog()) as $permission) {
        // Platform context (outside a support session) can only exercise
        // 'platform'/'both'-scope permissions (TENANCY.md V2 §8) — tenant
        // business permissions are unreachable without a support session.
        $expectedDecision = $isPlatform
            ? in_array(RolePermissionSeeder::scopes()[$permission] ?? 'tenant', ['platform', 'both'], true)
            : in_array($permission, $expected, true);

        expect($context->can($permission))
            ->toBe($expectedDecision, "role {$roleCode} / permission {$permission}");
    }
})->with(matrixCases());

it('has no orphan permissions — every seeded permission is granted somewhere', function () {
    $granted = collect(RolePermissionSeeder::catalog())
        ->flatMap(fn (array $definition): array => $definition['permissions'])
        ->unique()
        ->sort()
        ->values()
        ->all();

    $catalog = array_keys(RolePermissionSeeder::permissionCatalog());
    sort($catalog);

    expect($granted)->toBe($catalog);
});

it('facility-scoped roles cannot exercise org-scoped actions', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);

    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/users')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});
