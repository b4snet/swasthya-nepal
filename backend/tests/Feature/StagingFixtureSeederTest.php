<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\StagingFixtureSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * The staging fixture seeder (STAGING.md §13; consumed by
 * backend/smoke_staging.sh and the Playwright staging E2E) — the
 * reproducible synthetic two-tenant shape every staging smoke path depends
 * on. This suite proves the fixture's contract:
 *
 *   1. the documented Tenant A and Tenant B actors exist with exactly the
 *      role/scope assignments the smoke uses (login, tenant context);
 *   2. the Tenant-A NURSE is staff-bound and carries the minimum role
 *      required for RPM device enrollment (rpm:manage) — without
 *      escalation (no ai:sign, no platform/organization scope);
 *   3. every fixture identity is scoped to the correct tenant and facility —
 *      the fixture itself cannot leak across tenants;
 *   4. rerunning the seeder is idempotent — exactly one row per identity,
 *      including the nurse and her staff record;
 *   5. every actor/row is in the state the smoke's login/context/OPD/RPM/
 *      CDSS/AI paths require (active statuses, service/medication pricing,
 *      Tuesday schedule with capacity, verifiable synthetic credential);
 *   6. only synthetic identities and the documented fixture credential
 *      exist — no real credentials, no PHI, no environment-specific
 *      values — and seeding never writes to the audit trail.
 *
 * The fixture credential asserted here is the documented synthetic default
 * (`STAGING_FIXTURE_PASSWORD`, STAGING.md §11) — a test value, never a real
 * credential. The seeder refuses APP_ENV=production by construction.
 */
const STAGING_FIXTURE_PASSWORD = 'SmokePass-2026!';

beforeEach(function (): void {
    $this->seed(StagingFixtureSeeder::class);
});

/**
 * @return list<string>
 */
function stagingFixtureEmails(): array
{
    return [
        'smoke.super@two.test',
        'smoke.admin@two.test',
        'smoke.hadmin@two.test',
        'smoke.doctor@two.test',
        'smoke.nurse@two.test',
        'smoke.hadmin@three.test',
        'smoke.doctor@three.test',
    ];
}

it('provisions the documented tenant A actors with their exact role scopes', function (): void {
    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $facA = Facility::query()->where('tenant_id', $orgA->getKey())->where('code', 'smoke-central')->firstOrFail();

    $expected = [
        'smoke.super@two.test' => ['superadmin', Role::SCOPE_PLATFORM, null, null],
        'smoke.admin@two.test' => ['org_admin', 'organization', $orgA->getKey(), null],
        'smoke.hadmin@two.test' => ['hospital_admin', 'facility', $orgA->getKey(), $facA->getKey()],
        'smoke.doctor@two.test' => ['doctor', 'facility', $orgA->getKey(), $facA->getKey()],
        'smoke.nurse@two.test' => ['nurse', 'facility', $orgA->getKey(), $facA->getKey()],
    ];

    foreach ($expected as $email => [$roleCode, $scopeType, $tenantId, $facilityId]) {
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull("fixture user {$email} missing")
            ->and($user->status)->toBe(User::STATUS_ACTIVE);

        $assignment = $user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->first();
        expect($assignment)->not->toBeNull("no active assignment for {$email}")
            ->and($assignment->role->code)->toBe($roleCode)
            ->and($assignment->scope_type)->toBe($scopeType)
            ->and($assignment->tenant_id)->toBe($tenantId)
            ->and($assignment->facility_id)->toBe($facilityId);

        expect(RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('status', RoleAssignment::STATUS_ACTIVE)
            ->count())->toBe(1, "{$email} must have exactly one active assignment");
    }
});

it('provisions the documented tenant B actors and no tenant-B nurse', function (): void {
    $orgB = Organization::query()->where('code', 'apex-care')->firstOrFail();
    $facB = Facility::query()->where('tenant_id', $orgB->getKey())->where('code', 'apex-central')->firstOrFail();

    foreach (['smoke.hadmin@three.test' => 'hospital_admin', 'smoke.doctor@three.test' => 'doctor'] as $email => $roleCode) {
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull()
            ->and($user->status)->toBe(User::STATUS_ACTIVE)
            ->and($user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->first()?->role->code)->toBe($roleCode)
            ->and($user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->first()?->tenant_id)->toBe($orgB->getKey())
            ->and($user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->first()?->facility_id)->toBe($facB->getKey());
    }

    expect(User::query()->where('email', 'smoke.nurse@three.test')->exists())->toBeFalse()
        ->and(Staff::query()
            ->where('tenant_id', $orgB->getKey())
            ->where('employee_code', 'NUR-001')
            ->exists())->toBeFalse();
});

it('binds the tenant-A nurse to staff with exactly the RPM enrollment role', function (): void {
    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $facA = Facility::query()->where('tenant_id', $orgA->getKey())->where('code', 'smoke-central')->firstOrFail();
    $dept = Department::query()
        ->where('tenant_id', $orgA->getKey())
        ->where('facility_id', $facA->getKey())
        ->where('code', 'general-opd')
        ->firstOrFail();

    $nurse = User::query()->where('email', 'smoke.nurse@two.test')->firstOrFail();
    $nurseRole = $nurse->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->firstOrFail()->role;

    // The smoke's RPM register step enrolls as the NURSE — enrollment needs
    // a staff-bound account with rpm:manage (the documented enrollment role).
    expect($nurseRole->code)->toBe('nurse')
        ->and($nurseRole->permissions()->where('code', 'rpm:manage')->exists())->toBeTrue()
        ->and($nurseRole->permissions()->where('code', 'rpm:view')->exists())->toBeTrue()
        ->and($nurseRole->permissions()->where('code', 'rpm:acknowledge')->exists())->toBeTrue();

    // No escalation from the fixture's own role wiring: the nurse never
    // signs AI drafts, never ingests on the machine path, and has no
    // organization/platform scope.
    expect($nurseRole->permissions()->where('code', 'ai:sign')->exists())->toBeFalse()
        ->and($nurseRole->permissions()->where('code', 'rpm:ingest')->exists())->toBeFalse()
        ->and($nurseRole->scope_type)->not->toBeIn(['organization', 'platform']);

    $staff = Staff::query()
        ->where('tenant_id', $orgA->getKey())
        ->where('facility_id', $facA->getKey())
        ->where('employee_code', 'NUR-001')
        ->firstOrFail();

    expect($staff->user_id)->toBe($nurse->getKey())
        ->and($staff->full_name)->toBe('Nurse Smoke')
        ->and($staff->designation)->toBe('Staff Nurse')
        ->and($staff->department_id)->toBe($dept->getKey())
        ->and($staff->status)->toBe(Staff::STATUS_ACTIVE);

    // The clinical provider identity the smoke's OPD chain uses is bound to
    // the doctor login account.
    $doctor = User::query()->where('email', 'smoke.doctor@two.test')->firstOrFail();
    expect(Staff::query()
        ->where('tenant_id', $orgA->getKey())
        ->where('facility_id', $facA->getKey())
        ->where('employee_code', 'DOC-001')
        ->firstOrFail()->user_id)->toBe($doctor->getKey());
});

it('scopes every fixture identity to the correct tenant and facility (no cross-tenant leakage in the fixture)', function (): void {
    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $orgB = Organization::query()->where('code', 'apex-care')->firstOrFail();
    $facA = Facility::query()->where('tenant_id', $orgA->getKey())->firstOrFail();
    $facB = Facility::query()->where('tenant_id', $orgB->getKey())->firstOrFail();

    $tenantAEmails = ['smoke.super@two.test', 'smoke.admin@two.test', 'smoke.hadmin@two.test', 'smoke.doctor@two.test', 'smoke.nurse@two.test'];
    $tenantBEmails = ['smoke.hadmin@three.test', 'smoke.doctor@three.test'];

    // Every tenant-A actor's non-platform assignment belongs to org A, and
    // every facility-scoped one belongs to facility A — never B.
    foreach ($tenantAEmails as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
        foreach ($user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->get() as $assignment) {
            if ($assignment->scope_type !== Role::SCOPE_PLATFORM) {
                expect($assignment->tenant_id)->toBe($orgA->getKey());
            }
            if ($assignment->scope_type === 'facility') {
                expect($assignment->facility_id)->toBe($facA->getKey());
            }
        }
    }

    // Same for tenant B — never A.
    foreach ($tenantBEmails as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
        foreach ($user->roleAssignments()->where('status', RoleAssignment::STATUS_ACTIVE)->get() as $assignment) {
            expect($assignment->tenant_id)->toBe($orgB->getKey())
                ->and($assignment->facility_id)->toBe($facB->getKey());
        }
    }

    // Staff, service, formulary, and schedule rows are keyed to their own
    // tenant/facility only.
    expect(Staff::query()->where('tenant_id', $orgA->getKey())->pluck('facility_id')->unique()->all())
        ->toBe([$facA->getKey()])
        ->and(Staff::query()->where('tenant_id', $orgB->getKey())->pluck('facility_id')->unique()->all())
        ->toBe([$facB->getKey()]);

    foreach ([$orgA, $orgB] as $org) {
        foreach ([Service::class, Medication::class, ScheduleTemplate::class] as $model) {
            expect($model::query()->where('tenant_id', $org->getKey())->count())->toBe(1)
                ->and($model::query()->where('tenant_id', $org->getKey())->where('facility_id', $org === $orgA ? $facA->getKey() : $facB->getKey())->count())->toBe(1);
        }
    }
});

it('is idempotent — rerunning creates exactly one of every fixture identity', function (): void {
    // The beforeEach already ran the seeder once; run it again.
    $this->seed(StagingFixtureSeeder::class);

    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $orgB = Organization::query()->where('code', 'apex-care')->firstOrFail();
    $fixtureTenantIds = [$orgA->getKey(), $orgB->getKey()];
    $fixtureUserIds = User::query()->whereIn('email', stagingFixtureEmails())->pluck('id')->all();

    // Counts are scoped to fixture identities (codes/emails/tenant ids) —
    // never global table counts: other suites legitimately create rows (the
    // RLS suites insert through a separate app-role connection whose rows
    // are not inside this test's transaction).
    expect(Organization::query()->whereIn('code', ['smoke-group', 'apex-care'])->count())->toBe(2)
        ->and(Facility::query()->whereIn('code', ['smoke-central', 'apex-central'])->count())->toBe(2)
        ->and(Department::query()->whereIn('tenant_id', $fixtureTenantIds)->count())->toBe(2)
        ->and(User::query()->whereIn('email', stagingFixtureEmails())->count())->toBe(7)
        ->and(Staff::query()->whereIn('tenant_id', $fixtureTenantIds)->count())->toBe(3)
        ->and(RoleAssignment::query()->whereIn('user_id', $fixtureUserIds)->count())->toBe(7)
        ->and(Service::query()->whereIn('tenant_id', $fixtureTenantIds)->count())->toBe(2)
        ->and(Medication::query()->whereIn('tenant_id', $fixtureTenantIds)->count())->toBe(2)
        ->and(ScheduleTemplate::query()->whereIn('tenant_id', $fixtureTenantIds)->count())->toBe(2);

    // The nurse identity and her staff record are single rows.
    $nurse = User::query()->where('email', 'smoke.nurse@two.test')->firstOrFail();
    expect(User::query()->where('email', 'smoke.nurse@two.test')->count())->toBe(1)
        ->and(Staff::query()->where('tenant_id', $orgA->getKey())->where('employee_code', 'NUR-001')->count())->toBe(1)
        ->and(Staff::query()->where('user_id', $nurse->getKey())->count())->toBe(1)
        ->and(RoleAssignment::query()->where('user_id', $nurse->getKey())->count())->toBe(1);

    // Every intended fixture email resolves to exactly one user.
    foreach (stagingFixtureEmails() as $email) {
        expect(User::query()->where('email', $email)->count())->toBe(1);
    }
});

it('keeps every actor and row in the state the smoke paths require', function (): void {
    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $facA = Facility::query()->where('tenant_id', $orgA->getKey())->where('code', 'smoke-central')->firstOrFail();

    // Login: every smoke actor is active and the documented synthetic
    // credential verifies against the stored hash (argon2id at rest — never
    // the plaintext).
    foreach (['smoke.hadmin@two.test', 'smoke.doctor@two.test', 'smoke.nurse@two.test', 'smoke.hadmin@three.test'] as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->status)->toBe(User::STATUS_ACTIVE)
            ->and(Hash::check(STAGING_FIXTURE_PASSWORD, (string) $user->password_hash))->toBeTrue()
            ->and($user->password_hash)->not->toBe(STAGING_FIXTURE_PASSWORD);
    }

    // OPD chain: active facility/service/medication with the documented
    // pricing the invoice assertion depends on.
    expect($facA->status)->toBe(Facility::STATUS_ACTIVE);
    $service = Service::query()->where('tenant_id', $orgA->getKey())->where('code', 'opd-consult')->firstOrFail();
    expect($service->status)->toBe(Service::STATUS_ACTIVE)
        ->and((int) $service->default_charge_minor)->toBe(5000);
    $medication = Medication::query()->where('tenant_id', $orgA->getKey())->where('code', 'para-500')->firstOrFail();
    expect($medication->status)->toBe(Medication::STATUS_ACTIVE)
        ->and((int) $medication->price_minor)->toBe(3000);

    // Booking: the recurring Tuesday 09:00–11:00 template with capacity is
    // what the smoke's availability-derived slot depends on.
    $template = ScheduleTemplate::query()->where('tenant_id', $orgA->getKey())->firstOrFail();
    expect($template->status)->toBe(ScheduleTemplate::STATUS_ACTIVE)
        ->and($template->day_of_week)->toBe(2)
        ->and((int) $template->capacity)->toBe(5)
        ->and((int) $template->slot_minutes)->toBe(30);
});

it('contains only synthetic identities and never writes to the audit trail', function (): void {
    // Every fixture email uses the reserved .test TLD — the fixture cannot
    // carry a real-world identity.
    foreach (User::query()->whereIn('email', stagingFixtureEmails())->pluck('email') as $email) {
        expect((string) $email)->toEndWith('.test');
    }

    // Seeding is not a user action: no audit events are written for the
    // fixture tenants, so no PHI or actor data can enter the audit trail
    // from the fixture itself. Scoped to the fixture tenants — other suites
    // legitimately write their own audit rows on their own tenants.
    $orgA = Organization::query()->where('code', 'smoke-group')->firstOrFail();
    $orgB = Organization::query()->where('code', 'apex-care')->firstOrFail();
    expect(AuditEvent::query()->whereIn('tenant_id', [$orgA->getKey(), $orgB->getKey()])->count())->toBe(0);
});
