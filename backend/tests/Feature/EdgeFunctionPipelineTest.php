<?php

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\SupportSession;
use App\Models\User;
use App\Support\AuthClaims;
use App\Support\DatabaseTenantContext;
use App\Support\EdgeFunctionPipeline;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 4 — Edge Function pipeline, DB-coupled tier.
 *
 * The pure-logic half (JWT verification, context decision tree, claims
 * construction, authorization, envelope) is executed and proven by the Node
 * harness (supabase/functions/_shared/harness). This suite proves the parts
 * that need the real database:
 *
 *   - GoTrue subject → application user (users.auth_subject_id);
 *   - status gate (locked / disabled → 403);
 *   - suspended organization → 403 TENANT_SUSPENDED;
 *   - facility / branch proposal validation against active assignments;
 *   - forged app_* claims in the token payload are IGNORED — the five
 *     authoritative claims derive from the server-resolved context only;
 *   - the pipeline's claims, written into request.jwt.claims the way the
 *     deployed function writes the GUC on its least-privilege connection,
 *     reproduce the Phase 2 isolation matrix exactly (tenant / facility /
 *     branch / platform audit separation);
 *   - support sessions cannot accidentally become tenant or platform context.
 *
 * The app-role connection (swasthya_app, NOBYPASSRLS) exercises the policies;
 * every test rolls back in all paths — no fixtures leak.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('refuses an unknown subject with a controlled 401', function () {
    $bearer = edgePipelineToken((string) Str::uuid());

    expect(fn () => EdgeFunctionPipeline::resolve($bearer))
        ->toThrow(ApiException::class)
        ->and(fn () => EdgeFunctionPipeline::resolve($bearer))
        ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::INVALID_TOKEN && $e->statusCode === 401);
});

it('refuses locked and disabled identities before context resolution', function () {
    $org = Identity::organization();
    $locked = Identity::user(['status' => User::STATUS_LOCKED, 'auth_subject_id' => (string) Str::uuid()]);
    $disabled = Identity::user(['status' => User::STATUS_DISABLED, 'auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($locked, 'org_admin', $org);
    Identity::assign($disabled, 'org_admin', $org);

    foreach ([$locked, $disabled] as $user) {
        expect(fn () => EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id)))
            ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::FORBIDDEN && $e->statusCode === 403);
    }
});

it('maps a valid subject to the application user and emits exactly the five claims', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($user, 'hospital_admin', $org, $facility);

    [$resolved, $context, $claims] = EdgeFunctionPipeline::resolve(
        edgePipelineToken((string) $user->auth_subject_id),
        ['facilityId' => $facility->getKey()],
    );

    expect($resolved->getKey())->toBe($user->getKey())
        ->and($context->facility?->getKey())->toBe($facility->getKey())
        ->and($claims)->toBe([
            'app_user_id' => $user->getKey(),
            'app_tenant_id' => $org->getKey(),
            'app_facility_id' => $facility->getKey(),
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ])
        ->and(array_keys($claims))->toBe(AuthClaims::KEYS);
});

it('rejects a suspended organization with TENANT_SUSPENDED', function () {
    $org = Identity::organization(['status' => Organization::STATUS_SUSPENDED]);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($user, 'org_admin', $org);

    expect(fn () => EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id)))
        ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::TENANT_SUSPENDED && $e->statusCode === 403);
});

it('validates facility proposals against active assignments only', function () {
    $orgA = Identity::organization();
    $facA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facB = Identity::facility($orgB);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($user, 'doctor', $orgA, $facA);

    // Same-tenant facility the user is NOT assigned to → denied.
    $facA2 = Identity::facility($orgA);
    expect(fn () => EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id), ['facilityId' => $facA2->getKey()]))
        ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::FACILITY_DENIED);

    // Cross-tenant facility → denied.
    expect(fn () => EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id), ['facilityId' => $facB->getKey()]))
        ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::FACILITY_DENIED);

    // The assigned facility → resolves with the correct tenant claim.
    [, , $claims] = EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id), ['facilityId' => $facA->getKey()]);
    expect($claims['app_tenant_id'])->toBe($orgA->getKey())
        ->and($claims['app_facility_id'])->toBe($facA->getKey());
});

it('validates branch proposals against the resolved facility and tenant', function () {
    $org = Identity::organization();
    $fac = Identity::facility($org);
    $branchA = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $fac->getKey()]);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($user, 'doctor', $org, $fac);

    // Valid branch under the facility.
    [, , $claims] = EdgeFunctionPipeline::resolve(
        edgePipelineToken((string) $user->auth_subject_id),
        ['facilityId' => $fac->getKey(), 'branchId' => $branchA->getKey()],
    );
    expect($claims['app_branch_id'])->toBe($branchA->getKey());

    // A branch of the SAME tenant under a DIFFERENT facility → denied.
    $fac2 = Identity::facility($org);
    $branchOther = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $fac2->getKey()]);
    expect(fn () => EdgeFunctionPipeline::resolve(
        edgePipelineToken((string) $user->auth_subject_id),
        ['facilityId' => $fac->getKey(), 'branchId' => $branchOther->getKey()],
    ))->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::BRANCH_DENIED);

    // Branch without facility context → denied for an org-scoped principal
    // (whose default context has no facility).
    $orgUser = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($orgUser, 'org_admin', $org);
    expect(fn () => EdgeFunctionPipeline::resolve(
        edgePipelineToken((string) $orgUser->auth_subject_id),
        ['branchId' => $branchA->getKey()],
    ))->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::BRANCH_DENIED);
});

it('ignores forged app_* claims in the token payload — context is the only source', function () {
    $orgA = Identity::organization();
    $facA = Identity::facility($orgA);
    $userA = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($userA, 'hospital_admin', $orgA, $facA);

    // A hostile token claims a DIFFERENT tenant, facility, branch, and
    // platform status in its payload.
    $bearer = edgePipelineToken((string) $userA->auth_subject_id, [
        'app_tenant_id' => (string) Str::uuid(),
        'app_facility_id' => (string) Str::uuid(),
        'app_branch_id' => (string) Str::uuid(),
        'app_is_platform' => 'true',
    ]);

    [, , $claims] = EdgeFunctionPipeline::resolve($bearer, ['facilityId' => $facA->getKey()]);

    expect($claims['app_tenant_id'])->toBe($orgA->getKey())
        ->and($claims['app_facility_id'])->toBe($facA->getKey())
        ->and($claims['app_branch_id'])->toBe('')
        ->and($claims['app_is_platform'])->toBe('false');
});

it('proves the full chain: pipeline claims → request.jwt.claims → RLS isolation (Phase 2 matrix)', function () {
    // The FK-referenced rows must be visible to the OWNER connection (pipeline
    // resolution) AND the app-role connection (RLS proof). The owner
    // connection's RefreshDatabase transaction holds everything it inserts
    // UNCOMMITTED, and any FK reference from inside it takes a KEY SHARE lock
    // that would block cleanup — so the ENTIRE fixture graph is committed via
    // a raw autocommit PDO (the precedent of DatabaseRowLevelSecurityTest's
    // two-connection test) and the owner transaction never touches these rows.
    // The finally deletes them, so nothing survives into the shared database.
    $config = config('database.connections.pgsql');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
    $raw = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $orgA = (string) Str::uuid();
    $facA1 = (string) Str::uuid();
    $branchA1 = (string) Str::uuid();
    $role = (string) Str::uuid();
    $userA = (string) Str::uuid();
    $assignment = (string) Str::uuid();
    $subject = (string) Str::uuid();

    try {
        $raw->exec("insert into organizations (id, name, code, status) values ('{$orgA}', 'Org A', 'edge-chain-org', 'active')");
        $raw->exec("insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values ('{$facA1}', '{$orgA}', 'Fac A1', 'edge-chain-fac', 'active', 'UTC', '{}', '{}')");
        $raw->exec("insert into branches (id, tenant_id, facility_id, name, code, status) values ('{$branchA1}', '{$orgA}', '{$facA1}', 'Branch A1', 'edge-chain-br', 'active')");
        $raw->exec("insert into roles (id, code, name, scope_type, is_system) values ('{$role}', 'edge_chain_doctor', 'Edge Chain Doctor', 'facility', true)");
        $raw->exec("insert into users (id, email, password_hash, status, auth_subject_id) values ('{$userA}', 'edge-chain@test.local', 'edge-chain-hash', 'active', '{$subject}')");
        $raw->exec("insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values ('{$assignment}', '{$userA}', '{$role}', '{$orgA}', '{$facA1}', null, 'facility', 'active', now())");

        // The pipeline resolves claims for the real committed identity.
        [, , $claimsA] = EdgeFunctionPipeline::resolve(
            edgePipelineToken($subject),
            ['facilityId' => $facA1, 'branchId' => $branchA1],
        );

        expect($claimsA['app_tenant_id'])->toBe($orgA)
            ->and($claimsA['app_facility_id'])->toBe($facA1)
            ->and($claimsA['app_branch_id'])->toBe($branchA1);

        // RLS proof on the app-role connection: the same claims, written into
        // request.jwt.claims the way the deployed function writes the GUC.
        rlsTx(rlsConn(), function ($c) use ($claimsA, $orgA, $facA1, $branchA1): void {
            DatabaseTenantContext::setClaims($claimsA, $c);
            $patient = (string) Str::uuid();
            $c->insert(
                'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
                [$patient, $orgA, $facA1, 'MRN-EDGE-A', 'Edge A', '1990-01-01', 'female', 'active']
            );
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();

            // A forged cross-tenant claim (structurally valid but NOT derived
            // from the identity) → the row vanishes; update and delete affect
            // zero rows.
            DatabaseTenantContext::setClaims(array_merge($claimsA, ['app_tenant_id' => (string) Str::uuid()]), $c);
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull()
                ->and($c->update('update patients set status = ? where id = ?', ['merged', $patient]))->toBe(0)
                ->and($c->delete('delete from patients where id = ?', [$patient]))->toBe(0);

            // A forged cross-facility claim within the same tenant → invisible.
            DatabaseTenantContext::setClaims(array_merge($claimsA, ['app_facility_id' => (string) Str::uuid()]), $c);
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull();

            // A forged branch claim within the same facility hides branch
            // rows; the owning claims restore visibility.
            $department = (string) Str::uuid();
            DatabaseTenantContext::setClaims($claimsA, $c);
            $c->insert(
                'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
                [$department, $orgA, $facA1, $branchA1, 'Cardiology', 'cardiology', 'active']
            );
            DatabaseTenantContext::setClaims(array_merge($claimsA, ['app_branch_id' => (string) Str::uuid()]), $c);
            expect($c->selectOne('select id from departments where id = ?', [$department]))->toBeNull();

            // Missing context fails closed to zero rows.
            DatabaseTenantContext::setClaims([], $c);
            expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
        });
    } finally {
        // Remove the committed fixture rows (children first). Patients and
        // departments created in the RLS proof rolled back with rlsTx; the
        // owner connection never touched any of these rows, so no lock blocks
        // the cleanup.
        $raw->exec("delete from role_assignments where id = '{$assignment}'");
        $raw->exec("delete from users where id = '{$userA}'");
        $raw->exec("delete from branches where id = '{$branchA1}'");
        $raw->exec("delete from facilities where id = '{$facA1}'");
        $raw->exec("delete from roles where id = '{$role}'");
        $raw->exec("delete from organizations where id = '{$orgA}'");
    }
});

it('keeps support sessions from becoming tenant or platform context', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $platform = Identity::user(['auth_subject_id' => (string) Str::uuid()]);
    Identity::assign($platform, 'superadmin');

    SupportSession::query()->create([
        'user_id' => $platform->getKey(),
        'organization_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'reason' => 'Support escalation.',
        'status' => SupportSession::STATUS_ACTIVE,
        'opened_at' => now()->subMinute(),
        'expires_at' => now()->addHour(),
        'correlation_id' => (string) Str::uuid(),
    ]);

    [, $context, $claims] = EdgeFunctionPipeline::resolve(edgePipelineToken((string) $platform->auth_subject_id));

    expect($context->isPlatform)->toBeFalse()
        ->and($context->organization?->getKey())->toBe($org->getKey())
        ->and($context->facility?->getKey())->toBe($facility->getKey())
        ->and($context->supportSessionId)->not->toBeNull()
        ->and($context->assignments)->toHaveCount(1)
        ->and($context->assignments->first()->role?->code)->toBe('support_agent')
        ->and($claims['app_tenant_id'])->toBe($org->getKey())
        ->and($claims['app_facility_id'])->toBe($facility->getKey())
        ->and($claims['app_is_platform'])->toBe('false');

    // The same principal WITHOUT a session resolves to platform context.
    SupportSession::query()->where('user_id', $platform->getKey())->update(['status' => SupportSession::STATUS_ENDED]);
    [, $platformContext, $platformClaims] = EdgeFunctionPipeline::resolve(edgePipelineToken((string) $platform->auth_subject_id));
    expect($platformContext->isPlatform)->toBeTrue()
        ->and($platformClaims['app_tenant_id'])->toBe('')
        ->and($platformClaims['app_is_platform'])->toBe('true');
});

it('refuses a principal with no active assignments', function () {
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);

    expect(fn () => EdgeFunctionPipeline::resolve(edgePipelineToken((string) $user->auth_subject_id)))
        ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCodes::FORBIDDEN && $e->statusCode === 403);
});

it('patients:list — the claims-scoped query returns exactly the RLS-visible rows (Phase 7)', function () {
    // Self-contained on the app-role connection: orgs/facilities/patients
    // inserted inside the same transaction (claimsTenants precedent), so FK
    // checks see them and rlsTx rolls everything back.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // A second facility of tenant A.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );

        $patients = [
            'a1' => [$t['tenantA'], $t['facilityA']],
            'a2' => [$t['tenantA'], $facA2],
            'b' => [$t['tenantB'], $t['facilityB']],
        ];
        foreach ($patients as $key => [$tenant, $facility]) {
            $c->insert(
                'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
                [(string) Str::uuid(), $tenant, $facility, 'MRN-'.$key, 'Patient '.$key, '1990-01-01', 'female', 'active']
            );
        }

        // The exact SELECT the patients-list edge function runs (safe fields
        // only), under the claims the pipeline would have produced.
        $select = 'select id, mrn, facility_id, full_name, date_of_birth, sex, blood_group, status, created_at, updated_at from patients';
        $ids = function (array $rows): array {
            return array_map(fn ($row): string => $row->id, $rows);
        };

        // Tenant A / facility A claims → only the facility-A patient.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $a1 = $ids($c->select($select));
        expect($a1)->toHaveCount(1)->and($c->selectOne('select mrn from patients where id = ?', [$a1[0]])->mrn)->toBe('MRN-a1');

        // Tenant A / facility A2 → only the A2 patient.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($ids($c->select($select)))->toHaveCount(1)
            ->and($c->selectOne('select mrn from patients where id = ?', [$ids($c->select($select))[0]])->mrn)->toBe('MRN-a2');

        // Tenant B / facility B → only the B patient (Tenant A's rows are
        // invisible — no cross-tenant read).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($ids($c->select($select)))->toHaveCount(1)
            ->and($c->selectOne('select mrn from patients where id = ?', [$ids($c->select($select))[0]])->mrn)->toBe('MRN-b');

        // A forged cross-tenant/facility combination → zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($ids($c->select($select)))->toBe([]);

        // Missing/empty claims → zero rows (fail closed).
        DatabaseTenantContext::setClaims([], $c);
        expect($ids($c->select($select)))->toBe([]);
    });
});

it('patients:show — a single-row read by id is RLS-gated; out-of-scope and nonexistent are indistinguishable (Phase 8)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );

        $patients = [
            'a1' => [$t['tenantA'], $t['facilityA']],
            'a2' => [$t['tenantA'], $facA2],
            'b' => [$t['tenantB'], $t['facilityB']],
        ];
        $ids = [];
        foreach ($patients as $key => [$tenant, $facility]) {
            $ids[$key] = (string) Str::uuid();
            $c->insert(
                'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
                [$ids[$key], $tenant, $facility, 'MRN-'.$key, 'Patient '.$key, '1990-01-01', 'female', 'active']
            );
        }

        // The exact single-row SELECT patients-show runs (safe fields only),
        // on the SAME app-role connection (RLS active, NOBYPASSRLS).
        $selectOne = function (string $id) use ($c): ?object {
            return $c->selectOne(
                'select id, mrn, facility_id, full_name, date_of_birth, sex, blood_group, status, created_at, updated_at from patients where id = ? limit 1',
                [$id]
            );
        };

        $claims = function (string $tenant, string $facility): array {
            return [
                'app_user_id' => (string) Str::uuid(),
                'app_tenant_id' => $tenant,
                'app_facility_id' => $facility,
                'app_branch_id' => '',
                'app_is_platform' => 'false',
            ];
        };

        // Authorized: Tenant A / facility A reads its own patient (exact row).
        DatabaseTenantContext::setClaims($claims($t['tenantA'], $t['facilityA']), $c);
        $visible = $selectOne($ids['a1']);
        expect($visible)->not->toBeNull()
            ->and($visible->mrn)->toBe('MRN-a1')
            ->and($visible->full_name)->toBe('Patient a1');

        // Cross-facility (same tenant): the fac-A2 patient is invisible to A/fac-A1.
        expect($selectOne($ids['a2']))->toBeNull();

        // Cross-tenant: Tenant B cannot read Tenant A's patient.
        DatabaseTenantContext::setClaims($claims($t['tenantB'], $t['facilityB']), $c);
        expect($selectOne($ids['a1']))->toBeNull();

        // Forged cross-tenant+facility combination: invisible.
        DatabaseTenantContext::setClaims($claims($t['tenantB'], $t['facilityA']), $c);
        expect($selectOne($ids['a1']))->toBeNull();

        // Nonexistent id: null — byte-identical outcome to out-of-scope, so
        // the handler's 404 cannot leak whether a foreign record exists.
        expect($selectOne((string) Str::uuid()))->toBeNull();

        // Missing/empty claims: fail closed to null.
        DatabaseTenantContext::setClaims([], $c);
        expect($selectOne($ids['a1']))->toBeNull();
    });
});

it('the me contract reports exactly the resolved identity and its claims gate RLS visibility (Phase 6)', function () {
    // Identity half (owner connection): the pipeline is what the `me` edge
    // function runs; its safe payload must match the real DB rows exactly.
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid(), 'email' => 'me@contract.test']);
    Identity::assign($user, 'doctor', $org, $facility);

    [$resolved, $context, $claims] = EdgeFunctionPipeline::resolve(
        edgePipelineToken((string) $user->auth_subject_id),
        ['facilityId' => $facility->getKey()],
    );

    // The exact shape the `me` function returns (me.ts data.me + context).
    $mePayload = [
        'id' => $resolved->getKey(),
        'email' => $resolved->email,
        'status' => $resolved->status,
    ];
    expect($mePayload)->toBe(['id' => $user->getKey(), 'email' => 'me@contract.test', 'status' => User::STATUS_ACTIVE])
        ->and($claims['app_user_id'])->toBe($user->getKey())
        ->and($claims['app_tenant_id'])->toBe($org->getKey())
        ->and($claims['app_facility_id'])->toBe($facility->getKey())
        ->and($context->facility?->getKey())->toBe($facility->getKey());

    // RLS half (app-role connection, self-contained): the claims the `me`
    // function would write into request.jwt.claims gate visibility exactly —
    // Tenant A sees its own row; Tenant B cannot read or modify it.
    rlsTx(rlsConn(), function ($c): void {
        $tenants = claimsTenants($c);
        $patient = (string) Str::uuid();

        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $tenants['tenantA'],
            'app_facility_id' => $tenants['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $tenants['tenantA'], $tenants['facilityA'], 'MRN-ME-A', 'Me A', '1990-01-01', 'female', 'active']
        );
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();

        // Tenant B (same claims shape, different tenant value): invisible and
        // unmodifiable.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $tenants['tenantB'],
            'app_facility_id' => $tenants['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull()
            ->and($c->update('update patients set status = ? where id = ?', ['merged', $patient]))->toBe(0)
            ->and($c->delete('delete from patients where id = ?', [$patient]))->toBe(0);
    });
});

it('appointments:create — the claims-scoped INSERT is RLS-gated, race-safe, and owner-attributed (Phase 9)', function () {
    // The exact INSERT appointments-create runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS) inside one transaction:
    //  1. the booking lands under the authoritative tenant/facility claims;
    //  2. the partial unique index (uq_appointments_tenant_provider_start) is
    //     the FINAL double-booking arbiter — a duplicate live slot violates it;
    //  3. cancelled status frees the slot (live-status semantics);
    //  4. forged / missing claims make the row invisible and unmodifiable.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-APPT-A', 'Appt Patient A', '1990-01-01', 'female', 'active']
        );

        $startsAt = '2026-03-02 09:00:00';
        $endsAt = '2026-03-02 09:30:00';
        $actor = (string) Str::uuid();

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];

        $insertAppointment = function (string $id, ?string $status = 'booked', ?string $cancelReason = null) use ($c, $t, $patient, $provider, $startsAt, $endsAt, $actor): void {
            $c->insert(
                'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, cancel_reason, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', $startsAt, $endsAt, $status, 'counter', $cancelReason, 0, $actor]
            );
        };

        // 1. The booking lands under the owning claims, owner-attributed.
        DatabaseTenantContext::setClaims($claimsA, $c);
        $appt = (string) Str::uuid();
        $insertAppointment($appt);
        $row = $c->selectOne('select id, status, source, lock_version, created_by from appointments where id = ?', [$appt]);
        expect($row)->not->toBeNull()
            ->and($row->status)->toBe('booked')
            ->and($row->source)->toBe('counter')
            ->and($row->lock_version)->toBe(0)
            ->and($row->created_by)->toBe($actor);

        // 2. The unique-slot race: a second LIVE booking of the same start
        //    violates uq_appointments_tenant_provider_start → 23505. This is
        //    the DB guarantee the edge function maps to 409 SLOT_TAKEN — no
        //    application-level check-then-insert can substitute. A savepoint
        //    isolates the failed statement so the outer transaction survives.
        $c->beginTransaction();
        try {
            $insertAppointment((string) Str::uuid());
            expect(true)->toBeFalse('the duplicate live slot must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        // 3. Live-status semantics: cancelling the booking frees the slot —
        //    the partial index only covers live statuses, so a NEW booking of
        //    the same start succeeds after cancellation.
        $c->update('update appointments set status = ?, cancel_reason = ?, updated_at = now() where id = ?', ['cancelled', 'Duplicate', $appt]);
        $rebooked = (string) Str::uuid();
        $insertAppointment($rebooked);
        expect($c->selectOne('select id, status from appointments where id = ?', [$rebooked]))->not->toBeNull();

        // 4. Forged cross-tenant claims: the row is invisible and immune to
        //    update/delete — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->update('update appointments set status = ? where id = ?', ['completed', $appt]))->toBe(0)
            ->and($c->delete('delete from appointments where id = ?', [$appt]))->toBe(0);

        // 5. Forged cross-facility claims within the tenant: invisible.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull();

        // 6. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from appointments')->total)->toBe(0);
    });
});

it('appointments:checkin — the row-locked token allocation and guarded status transition are DB-enforced (Phase 10)', function () {
    // The exact check-in mutation appointments-checkin runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring AppointmentController::checkIn + TokenIssuer:
    //  1. the token_counters row is created (ON CONFLICT DO NOTHING) and
    //     locked FOR UPDATE — parallel issuers serialize on the row lock;
    //  2. the appointment transition is GUARDED (`WHERE status = 'booked'`)
    //     — a duplicate check-in matches zero rows → the edge 409s;
    //  3. forged/missing claims make the row invisible and the mutation
    //     impossible (RLS is the final boundary; token_counters is itself a
    //     claims-scoped table).
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → booked appointment.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);
        $startsAt = '2026-03-02 09:00:00';

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-CI-A', 'Checkin Patient', '1990-01-01', 'female', 'active']
        );
        // users is outside the RLS-scoped set — the app-role connection can
        // create the actor (checked_in_by → users.id FK).
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'checkin-actor@test.local', 'checkin-hash', 'active']
        );

        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', $startsAt, '2026-03-02 09:30:00', 'booked', 'counter', 0, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. TokenIssuer parity: create + row-lock the counter, mint the next
        //    token. token_counters is claims-scoped, so this only works under
        //    the authoritative tenant/facility claims.
        $c->insert(
            'insert into token_counters (id, tenant_id, facility_id, provider_staff_id, queue_date, last_token) values (?, ?, ?, ?, ?, 0) on conflict on constraint uq_token_counters_tenant_facility_provider_date do nothing',
            [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], $provider, '2026-03-02']
        );
        $counter = $c->selectOne(
            'select id, last_token from token_counters where tenant_id = ? and facility_id = ? and provider_staff_id = ? and queue_date = ? for update',
            [$t['tenantA'], $t['facilityA'], $provider, '2026-03-02']
        );
        expect($counter)->not->toBeNull();
        $token = (int) $counter->last_token + 1;
        $c->update('update token_counters set last_token = ? where id = ?', [$token, $counter->id]);

        // 2. The guarded check-in transition — exactly what the edge function
        //    executes. lock_version increments; owner attribution recorded.
        $updated = $c->update(
            "update appointments set status = 'checked_in', token_no = ?, checked_in_by = ?, checked_in_at = now(), lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and facility_id = ? and status = 'booked'",
            [$token, $actor, $appt, $t['tenantA'], $t['facilityA']]
        );
        expect($updated)->toBe(1);
        $row = $c->selectOne('select status, token_no, checked_in_by, checked_in_at, lock_version from appointments where id = ?', [$appt]);
        expect($row->status)->toBe('checked_in')
            ->and((int) $row->token_no)->toBe(1)
            ->and($row->checked_in_by)->toBe($actor)
            ->and($row->checked_in_at)->not->toBeNull()
            ->and((int) $row->lock_version)->toBe(1);

        // 3. Duplicate check-in: the guarded transition now matches ZERO rows
        //    (the edge function maps this to 409 — same contract as the
        //    status gate in the Laravel controller).
        expect($c->update(
            "update appointments set status = 'checked_in', token_no = 2, checked_in_by = ?, checked_in_at = now(), lock_version = lock_version + 1 where id = ? and tenant_id = ? and facility_id = ? and status = 'booked'",
            [$actor, $appt, $t['tenantA'], $t['facilityA']]
        ))->toBe(0);
        expect((int) $c->selectOne('select last_token from token_counters where id = ?', [$counter->id])->last_token)->toBe(1);

        // 4. Forged cross-tenant claims: the appointment is invisible and the
        //    mutation affects zero rows — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->update("update appointments set status = 'completed' where id = ?", [$appt]))->toBe(0)
            ->and($c->selectOne('select id from token_counters where id = ?', [$counter->id]))->toBeNull();

        // 5. Missing claims: fail closed.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull();
    });
});

it('encounters:create — the guarded appointment transition + encounter INSERT are DB-enforced and RLS-gated (Phase 11)', function () {
    // The exact start transaction encounters-create runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring EncounterController::start:
    //  1. the appointment transition is GUARDED (`WHERE status =
    //     'checked_in'`) — a duplicate start matches zero rows → 409;
    //  2. the encounter INSERT derives tenant/facility/patient/provider
    //     exclusively from the RLS-visible appointment;
    //  3. uq_encounters_tenant_appointment (one encounter per appointment)
    //     is the DB-enforced backstop;
    //  4. forged cross-tenant / cross-facility / missing claims make the
    //     encounter and its appointment invisible and unmodifiable — RLS is
    //     the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → checked-in appointment.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-ENC-A', 'Encounter Patient', '1990-01-01', 'female', 'active']
        );
        // users is outside the RLS-scoped set — the app-role connection can
        // create the actor (encounters.created_by → users.id FK).
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'encounter-actor@test.local', 'encounter-hash', 'active']
        );

        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 'counter', 1, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The guarded appointment transition — exactly what the edge
        //    function executes. A non-checked_in appointment matches zero
        //    rows (the 409 the function returns).
        $updated = $c->update(
            "update appointments set status = 'in_consultation', lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and facility_id = ? and status = 'checked_in'",
            [$appt, $t['tenantA'], $t['facilityA']]
        );
        expect($updated)->toBe(1);

        // 2. The encounter INSERT — every scoped column derived from the
        //    appointment. type=opd, status=open, lock_version=0,
        //    created_by=actor (EncounterController::start parity).
        $encounter = (string) Str::uuid();
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );
        $row = $c->selectOne('select id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, lock_version, created_by from encounters where id = ?', [$encounter]);
        expect($row)->not->toBeNull()
            ->and($row->facility_id)->toBe($t['facilityA'])
            ->and($row->patient_id)->toBe($patient)
            ->and($row->appointment_id)->toBe($appt)
            ->and($row->provider_staff_id)->toBe($provider)
            ->and($row->type)->toBe('opd')
            ->and($row->status)->toBe('open')
            ->and((int) $row->lock_version)->toBe(0)
            ->and($row->created_by)->toBe($actor);

        // The appointment moved in the SAME transaction.
        $apptRow = $c->selectOne('select status, lock_version from appointments where id = ?', [$appt]);
        expect($apptRow->status)->toBe('in_consultation')
            ->and((int) $apptRow->lock_version)->toBe(2);

        // 3. Duplicate start: the guarded transition now matches ZERO rows
        //    (the edge function maps this to 409 — same contract as the
        //    status gate in the Laravel controller).
        expect($c->update(
            "update appointments set status = 'in_consultation', lock_version = lock_version + 1 where id = ? and tenant_id = ? and facility_id = ? and status = 'checked_in'",
            [$appt, $t['tenantA'], $t['facilityA']]
        ))->toBe(0);

        // 4. The partial unique index uq_encounters_tenant_appointment is the
        //    DB backstop: a second encounter for the same appointment violates
        //    it → 23505. A savepoint isolates the failed statement so the
        //    outer transaction survives (the edge function maps this to 409,
        //    never a 500).
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
            );
            expect(true)->toBeFalse('a second encounter for the same appointment must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        // 5. Forged cross-tenant claims: the encounter and its appointment are
        //    invisible and immune to update/delete — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->update("update encounters set status = 'signed' where id = ?", [$encounter]))->toBe(0)
            ->and($c->delete('delete from encounters where id = ?', [$encounter]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: invisible.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from encounters')->total)->toBe(0);
    });
});

it('encounter-notes:draft — the clinical note INSERT is RLS-gated and author-FK-enforced (Phase 12)', function () {
    // The exact draft-note INSERT encounter-notes:draft runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring EncounterController::storeNote:
    //  1. the note lands under the authoritative tenant claims with
    //     author_staff_id = the encounter provider (server-derived);
    //  2. the composite FK (tenant_id, author_staff_id) → staff is the DB
    //     backstop — a forged cross-tenant author violates it (23503);
    //  3. forged cross-tenant / cross-facility / missing claims make the note
    //     invisible and unmodifiable — RLS is the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff, user-bound)
        // → patient → checked-in appointment → open encounter.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'note-actor@test.local', 'note-hash', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, $actor, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-NOTE-A', 'Note Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'in_consultation', 'counter', 2, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 1, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The draft-note INSERT — every scoped column derived from the
        //    encounter/provider (storeNote parity): status draft, lock_version
        //    0, created_by = actor.
        $note = (string) Str::uuid();
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, lock_version, created_by) values (?, ?, ?, 'consultation', ?, ?::jsonb, 'draft', 0, ?)",
            [$note, $t['tenantA'], $encounter, $provider, '{"complaint":"Fever","plan":"Review in 3 days"}', $actor]
        );
        $row = $c->selectOne('select id, encounter_id, note_type, author_staff_id, status, lock_version, created_by from clinical_notes where id = ?', [$note]);
        expect($row)->not->toBeNull()
            ->and($row->encounter_id)->toBe($encounter)
            ->and($row->note_type)->toBe('consultation')
            ->and($row->author_staff_id)->toBe($provider)
            ->and($row->status)->toBe('draft')
            ->and((int) $row->lock_version)->toBe(0)
            ->and($row->created_by)->toBe($actor);

        // 2. The composite FK (tenant_id, author_staff_id) → staff is the DB
        //    backstop: an author staff row belonging to ANOTHER tenant cannot
        //    be forged. A savepoint isolates the failed statement.
        $foreignStaff = (string) Str::uuid();
        $foreignDepartment = (string) Str::uuid();
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$foreignDepartment, $t['tenantB'], $t['facilityB'], 'OPD-B', 'opd-b-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$foreignStaff, $t['tenantB'], $t['facilityB'], $foreignDepartment, 'EMP-F-'.$suffix, 'Dr. Foreign', 'Doctor', 'active']
        );
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, lock_version, created_by) values (?, ?, ?, 'consultation', ?, '{}'::jsonb, 'draft', 0, ?)",
                [(string) Str::uuid(), $t['tenantA'], $encounter, $foreignStaff, $actor]
            );
            expect(true)->toBeFalse('a cross-tenant author must violate the composite FK');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23503');
            $c->rollBack();
        }

        // 3. Forged cross-tenant claims: the note and its encounter are
        //    invisible and immune to update/delete — RLS is the final
        //    boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from clinical_notes where id = ?', [$note]))->toBeNull()
            ->and($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->update("update clinical_notes set status = 'signed' where id = ?", [$note]))->toBe(0)
            ->and($c->delete('delete from clinical_notes where id = ?', [$note]))->toBe(0);

        // 4. clinical_notes is a TENANT-ONLY RLS table (the Phase 2 matrix:
        //    `tenant_id = claims.tenant`, no facility clause) — a DIFFERENT
        //    facility of the SAME tenant still sees the note. Facility
        //    isolation for notes is enforced at the encounter-lookup path
        //    (the edge function can only attach a note to an RLS-visible,
        //    facility-scoped encounter — proven by the harness), not by a
        //    facility clause on the note table itself.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from clinical_notes where id = ?', [$note]))->not->toBeNull()
            ->and($c->update("update clinical_notes set status = 'amended' where id = ?", [$note]))->toBe(1);

        // 5. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from clinical_notes where id = ?', [$note]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from clinical_notes')->total)->toBe(0);
    });
});

it('encounter-notes:sign — the guarded draft→signed transition is DB-enforced and RLS-gated (Phase 13)', function () {
    // The exact signing transition encounter-notes:sign runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring EncounterController::signNote:
    //  1. the GUARDED UPDATE (`status = 'signed', signed_at = now(),
    //     lock_version + 1 WHERE status = 'draft'`) is the atomic arbiter — a
    //     duplicate/concurrent sign matches zero rows, so signed notes are
    //     immutable;
    //  2. signed_at is generated SERVER-SIDE by now();
    //  3. forged cross-tenant / missing claims make the note invisible and
    //     unmodifiable — RLS is the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff, user-bound)
        // → patient → checked-in appointment → open encounter → draft note
        // authored by the provider.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $note = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'sign-actor@test.local', 'sign-hash', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, $actor, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-SIGN-A', 'Sign Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'in_consultation', 'counter', 2, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 1, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, lock_version, created_by) values (?, ?, ?, 'consultation', ?, '{\"complaint\":\"Fever\"}'::jsonb, 'draft', 0, ?)",
            [$note, $t['tenantA'], $encounter, $provider, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The GUARDED signing transition — exactly what the edge function
        //    executes. signed_at generated server-side (now()); lock_version
        //    incremented; exactly one row transitioned.
        $updated = $c->update(
            "update clinical_notes set status = 'signed', signed_at = now(), lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and encounter_id = ? and status = 'draft'",
            [$note, $t['tenantA'], $encounter]
        );
        expect($updated)->toBe(1);
        $row = $c->selectOne('select id, status, signed_at, lock_version from clinical_notes where id = ?', [$note]);
        expect($row->status)->toBe('signed')
            ->and($row->signed_at)->not->toBeNull()
            ->and((int) $row->lock_version)->toBe(1);

        // 2. Duplicate/concurrent sign: the guard now matches ZERO rows (a
        //    signed note is immutable) — the edge function maps this to the
        //    exact 409 contract.
        expect($c->update(
            "update clinical_notes set status = 'signed', signed_at = now(), lock_version = lock_version + 1 where id = ? and tenant_id = ? and encounter_id = ? and status = 'draft'",
            [$note, $t['tenantA'], $encounter]
        ))->toBe(0);
        expect((int) $c->selectOne('select lock_version from clinical_notes where id = ?', [$note])->lock_version)->toBe(1);

        // 3. Forged cross-tenant claims: the note is invisible and immune to
        //    the mutation — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from clinical_notes where id = ?', [$note]))->toBeNull()
            ->and($c->update("update clinical_notes set status = 'amended' where id = ?", [$note]))->toBe(0);

        // 4. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from clinical_notes where id = ?', [$note]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from clinical_notes')->total)->toBe(0);
    });
});

it('encounters:sign — the guarded encounter signing + appointment handoff are DB-enforced and RLS-gated (Phase 14)', function () {
    // The exact signing transaction encounters-sign runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring EncounterController::sign:
    //  1. the GUARDED encounter transition (`status = 'signed', ended_at =
    //     now(), signed_at = now(), signed_by = actor, lock_version + 1
    //     WHERE status = 'open'`) is the atomic arbiter — a duplicate/
    //     concurrent sign matches zero rows, so signed encounters are
    //     immutable;
    //  2. the GUARDED appointment handoff (`status = 'completed' WHERE
    //     status = 'in_consultation'`) completes the visit — a zero-row
    //     handoff would be the silent-skip Laravel parity;
    //  3. ended_at/signed_at are generated SERVER-SIDE by now();
    //  4. forged cross-tenant / missing claims make the rows invisible and
    //     unmodifiable — RLS is the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff, user-bound)
        // → patient → in_consultation appointment → open encounter → signed
        // note by the provider.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $note = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'enc-sign-actor@test.local', 'enc-sign-hash', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, $actor, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-ES-A', 'EncSign Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'in_consultation', 'counter', 1, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by) values (?, ?, ?, 'consultation', ?, '{\"complaint\":\"Fever\"}'::jsonb, 'signed', now(), 1, ?)",
            [$note, $t['tenantA'], $encounter, $provider, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The GUARDED encounter transition — exactly what the edge
        //    function executes. ended_at/signed_at generated server-side
        //    (now()); signed_by = actor; lock_version incremented; exactly
        //    one row transitioned.
        $updated = $c->update(
            "update encounters set status = 'signed', ended_at = now(), signed_by = ?, signed_at = now(), lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and facility_id = ? and status = 'open'",
            [$actor, $encounter, $t['tenantA'], $t['facilityA']]
        );
        expect($updated)->toBe(1);
        $row = $c->selectOne('select status, ended_at, signed_at, signed_by, lock_version from encounters where id = ?', [$encounter]);
        expect($row->status)->toBe('signed')
            ->and($row->ended_at)->not->toBeNull()
            ->and($row->signed_at)->not->toBeNull()
            ->and($row->signed_by)->toBe($actor)
            ->and((int) $row->lock_version)->toBe(1);

        // 2. The GUARDED appointment handoff — the visit completes in the
        //    same transaction.
        $handoff = $c->update(
            "update appointments set status = 'completed', lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and facility_id = ? and status = 'in_consultation'",
            [$appt, $t['tenantA'], $t['facilityA']]
        );
        expect($handoff)->toBe(1);
        $apptRow = $c->selectOne('select status, lock_version from appointments where id = ?', [$appt]);
        expect($apptRow->status)->toBe('completed')
            ->and((int) $apptRow->lock_version)->toBe(2);

        // 3. Duplicate/concurrent sign: the guard now matches ZERO rows (a
        //    signed encounter is immutable) — the edge function maps this to
        //    the exact 409 contract.
        expect($c->update(
            "update encounters set status = 'signed', ended_at = now(), signed_by = ?, signed_at = now(), lock_version = lock_version + 1 where id = ? and tenant_id = ? and facility_id = ? and status = 'open'",
            [$actor, $encounter, $t['tenantA'], $t['facilityA']]
        ))->toBe(0);
        expect((int) $c->selectOne('select lock_version from encounters where id = ?', [$encounter])->lock_version)->toBe(1);

        // 4. Forged cross-tenant claims: the encounter, its appointment, and
        //    its note are invisible and immune to mutation — RLS is the final
        //    boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->selectOne('select id from clinical_notes where id = ?', [$note]))->toBeNull()
            ->and($c->update("update encounters set status = 'closed' where id = ?", [$encounter]))->toBe(0);

        // 5. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from encounters')->total)->toBe(0);
    });
});
it('encounters:invoice — the issue transaction, totals, and uniqueness backstops are DB-enforced and RLS-gated (Phase 15)', function () {
    // The exact invoice issue transaction encounters-invoice runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS) inside one
    // transaction, mirroring EncounterController::invoice +
    // BillingService::issueInvoice:
    //  1. the consultation charge is derived server-side from the
    //     appointment's service rate ONLY when no encounter-source charge
    //     exists (idempotent);
    //  2. the prescription charges are derived from ORDERED lines ×
    //     medication price (quantity = max(1, quantity_minor ?? 1)) ONLY when
    //     the encounter's first prescription is not yet charged; cancelled
    //     lines are excluded;
    //  3. the invoice total is the sum of posted charges in integer minor
    //     units (consultation 50000 + 500 + 3600 + 250 = 54350);
    //  4. the invoice number is server-generated ('INV-YYYYMMDD-XXXXX') and
    //     uq_invoices_tenant_number is the uniqueness backstop (23505 → 409);
    //  5. uq_invoice_lines_tenant_charge (one charge, one invoice) is the
    //     concurrent-issue backstop (23505 → 409) — the pre-check returns
    //     the same conflict sequentially;
    //  6. forged cross-tenant / cross-facility / missing claims make every
    //     billing row invisible and unmodifiable — RLS is the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff, user-bound)
        // → patient → appointment with a service → signed encounter →
        // prescription with ordered + cancelled lines.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $service = (string) Str::uuid();
        $medParacetamol = (string) Str::uuid();
        $medAmoxicillin = (string) Str::uuid();
        $prescription = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'inv-actor@test.local', 'inv-hash', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, $actor, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-INV-A', 'Invoice Patient', '1990-01-01', 'female', 'active']
        );
        // Service catalog: the consultation charge source (500.00 NPR).
        $c->insert(
            "insert into services (id, tenant_id, facility_id, name, code, service_type, status, default_charge_minor, currency) values (?, ?, ?, 'General OPD', ?, 'opd_consultation', 'active', 50000, 'NPR')",
            [$service, $t['tenantA'], $t['facilityA'], 'svc-'.$suffix]
        );
        // Medication catalog: 2.50 and 12.00 (minor units).
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, strength, unit, price_minor, currency, status) values (?, ?, ?, ?, 'Paracetamol', '500mg', 'tab', 250, 'NPR', 'active')",
            [$medParacetamol, $t['tenantA'], $t['facilityA'], 'med-p-'.$suffix]
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, strength, unit, price_minor, currency, status) values (?, ?, ?, ?, 'Amoxicillin', '250mg', 'cap', 1200, 'NPR', 'active')",
            [$medAmoxicillin, $t['tenantA'], $t['facilityA'], 'med-a-'.$suffix]
        );
        $c->insert(
            "insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, service_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'completed', 'counter', 2, ?)",
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, $service, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, ended_at, signed_at, signed_by, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'signed', now(), now(), now(), ?, 1, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor, $actor]
        );
        $c->insert(
            'insert into prescriptions (id, tenant_id, patient_id, encounter_id, prescriber_staff_id, status, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 0, ?)',
            [$prescription, $t['tenantA'], $patient, $encounter, $provider, 'drafted', $actor]
        );
        // Lines: two ordered (500 + 3600), one cancelled (excluded by the
        // derivation SQL), and one ordered with a NULL quantity (→ 250).
        $c->insert(
            "insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, quantity_minor, status, line_no, created_by) values (?, ?, ?, ?, '1 tab', 'oral', 'tid', 2, 'ordered', 1, ?)",
            [(string) Str::uuid(), $t['tenantA'], $prescription, $medParacetamol, $actor]
        );
        $c->insert(
            "insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, quantity_minor, status, line_no, created_by) values (?, ?, ?, ?, '1 cap', 'oral', 'bid', 3, 'ordered', 2, ?)",
            [(string) Str::uuid(), $t['tenantA'], $prescription, $medAmoxicillin, $actor]
        );
        $c->insert(
            "insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, quantity_minor, status, line_no, created_by) values (?, ?, ?, ?, '1 tab', 'oral', 'tid', 4, 'cancelled', 3, ?)",
            [(string) Str::uuid(), $t['tenantA'], $prescription, $medParacetamol, $actor]
        );
        $c->insert(
            "insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, quantity_minor, status, line_no, created_by) values (?, ?, ?, ?, '1 tab', 'oral', 'tid', null, 'ordered', 4, ?)",
            [(string) Str::uuid(), $t['tenantA'], $prescription, $medParacetamol, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The consultation charge — the exact `insert … select … where not
        //    exists` the edge function executes. Server-derived from the
        //    appointment's service rate.
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) select gen_random_uuid(), a.tenant_id, ?, ?, 'encounter', ?, null, s.name || ' — consultation', s.default_charge_minor, coalesce(s.currency, 'NPR'), 0, 'posted', now(), ?, now(), now() from appointments a join services s on s.tenant_id = a.tenant_id and s.id = a.service_id where a.tenant_id = ? and a.id = ? and s.default_charge_minor is not null and not exists (select 1 from charges c where c.tenant_id = ? and c.encounter_id = ? and c.source_type = 'encounter')",
            [$t['facilityA'], $patient, $encounter, $actor, $t['tenantA'], $appt, $t['tenantA'], $encounter]
        );

        // 2. The prescription-line charges — ordered lines × price, quantity =
        //    max(1, quantity_minor ?? 1); the cancelled line is excluded by
        //    the join filter; the null-quantity line yields 1.
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) select gen_random_uuid(), p.tenant_id, ?, ?, 'prescription', ?, p.id, m.generic_name || ' (' || m.strength || ') × ' || greatest(1, coalesce(pl.quantity_minor, 1))::text, m.price_minor * greatest(1, coalesce(pl.quantity_minor, 1)), m.currency, 0, 'posted', now(), ?, now(), now() from prescriptions p join prescription_lines pl on pl.tenant_id = p.tenant_id and pl.prescription_id = p.id join medications m on m.tenant_id = pl.tenant_id and m.id = pl.medication_id where p.tenant_id = ? and p.encounter_id = ? and pl.status = 'ordered' and not exists (select 1 from charges c where c.tenant_id = ? and c.prescription_id = ?)",
            [$t['facilityA'], $patient, $encounter, $actor, $t['tenantA'], $encounter, $t['tenantA'], $prescription]
        );

        // 3. Posted charges for the encounter — the invoice is built from
        //    exactly these (consultation 50000 + 500 + 3600 + 250).
        $charges = $c->select('select id, description, amount_minor, tax_rate_bps from charges where tenant_id = ? and encounter_id = ? and status = ? order by amount_minor', [$t['tenantA'], $encounter, 'posted']);
        expect($charges)->toHaveCount(4);
        $amounts = array_map(fn ($charge): int => (int) $charge->amount_minor, $charges);
        sort($amounts);
        expect($amounts)->toBe([250, 500, 3600, 50000]);
        expect((int) array_sum($amounts))->toBe(54350);
        $descriptions = array_map(fn ($charge): string => $charge->description, $charges);
        expect($descriptions)->toContain('General OPD — consultation');
        expect($descriptions)->toContain('Paracetamol (500mg) × 2');
        expect($descriptions)->toContain('Amoxicillin (250mg) × 3');
        expect($descriptions)->toContain('Paracetamol (500mg) × 1');

        // 4. The invoice — server-generated number (BillingService::nextNumber
        //    format), integer minor-unit totals, issued status, lock_version 0.
        $invoiceNumber = 'INV-'.date('Ymd').'-10001';
        $invoiceId = (string) Str::uuid();
        $c->insert(
            "insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, 'issued', ?, 0, 0, now(), 0, ?, now(), now())",
            [$invoiceId, $t['tenantA'], $t['facilityA'], $patient, $invoiceNumber, 54350, $actor]
        );
        $invoice = $c->selectOne('select invoice_number, status, total_minor, total_tax_minor, paid_minor, lock_version from invoices where id = ?', [$invoiceId]);
        expect($invoice->invoice_number)->toMatch('/^INV-\d{8}-\d{5}$/')
            ->and($invoice->status)->toBe('issued')
            ->and((int) $invoice->total_minor)->toBe(54350)
            ->and((int) $invoice->total_tax_minor)->toBe(0)
            ->and((int) $invoice->paid_minor)->toBe(0)
            ->and((int) $invoice->lock_version)->toBe(0);

        // 5. The frozen lines — one per posted charge, line_no 1..4, each
        //    charge invoiced exactly once.
        foreach ($charges as $index => $charge) {
            $c->insert(
                'insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::uuid(), $t['tenantA'], $invoiceId, $charge->id, $charge->description, (int) $charge->amount_minor, 0, $index + 1]
            );
        }
        $lineTotal = (int) $c->selectOne('select coalesce(sum(amount_minor), 0) as total from invoice_lines where invoice_id = ?', [$invoiceId])->total;
        expect($lineTotal)->toBe(54350);

        // 6. A second issue of the same encounter cannot re-invoice: the
        //    already-invoiced pre-check (the exact SELECT the edge function
        //    runs) reports true BEFORE any insert.
        $chargeIds = array_map(fn ($charge): string => $charge->id, $charges);
        $alreadyInvoiced = $c->selectOne(
            'select exists (select 1 from invoice_lines where tenant_id = ? and charge_id = any(?::uuid[])) as present',
            [$t['tenantA'], '{'.implode(',', $chargeIds).'}']
        );
        expect((bool) $alreadyInvoiced->present)->toBeTrue();

        // 7. uq_invoice_lines_tenant_charge is the CONCURRENT backstop — a
        //    racing issue that bypasses the pre-check violates it (23505 →
        //    409). A savepoint isolates the failed statement.
        $c->beginTransaction();
        try {
            $c->insert(
                'insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
                [(string) Str::uuid(), $t['tenantA'], $invoiceId, $charges[0]->id, $charges[0]->description, (int) $charges[0]->amount_minor, 0, 99]
            );
            expect(true)->toBeFalse('a charge already on an invoice must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        // 8. uq_invoices_tenant_number is the invoice-number backstop — a
        //    concurrent issue drawing the same number violates it (23505 →
        //    retryable 409). Savepoint-isolated.
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, 'issued', 1, 0, 0, now(), 0, ?, now(), now())",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], $patient, $invoiceNumber, $actor]
            );
            expect(true)->toBeFalse('a duplicate invoice number in the tenant must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        // 9. Forged cross-tenant claims: every billing row is invisible and
        //    immune to mutation — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoiceId]))->toBeNull()
            ->and($c->selectOne('select id from charges where id = ?', [$charges[0]->id]))->toBeNull()
            ->and($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->update('update invoices set status = ? where id = ?', ['voided', $invoiceId]))->toBe(0)
            ->and($c->delete('delete from invoices where id = ?', [$invoiceId]))->toBe(0);

        // 10. Forged cross-facility claims within the tenant: invisible.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoiceId]))->toBeNull();

        // 11. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoiceId]))->toBeNull()
            ->and($c->selectOne('select id from charges where id = ?', [$charges[0]->id]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from invoices')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from charges')->total)->toBe(0);
    });
});
it('invoices:pay — the capture transaction, idempotency, and optimistic-lock backstop are DB-enforced and RLS-gated (Phase 16)', function () {
    // The exact capture transaction invoices-pay runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS) inside one transaction,
    // mirroring BillingController::pay + BillingService::capturePayment:
    //  1. IDEMPOTENCY FIRST: a payment with the same (tenant, idempotency_key)
    //     is replayed — no new money; uq_payments_tenant_idempotency is the
    //     concurrent backstop (23505 → retryable 409);
    //  2. payment INSERT (currency NPR, status captured, received_at/created_by
    //     server-derived) + payment_allocations INSERT;
    //  3. the GUARDED optimistic-lock invoice update
    //     (`lock_version = <expected>`) decides the winner — a stale expected
    //     version matches zero rows, and the whole transaction rolls back (no
    //     orphan payment/allocation); paid_minor/status/lock_version updated
    //     exactly once (full payment → 'paid');
    //  4. forged cross-tenant / cross-facility / missing claims make every
    //     billing row invisible and unmodifiable — RLS is the final boundary.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff, user-bound)
        // → patient → issued invoice (54350, unpaid, lock_version 0).
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $invoice = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'pay-actor@test.local', 'pay-hash', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, $actor, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-PAY-A', 'Pay Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            "insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, 'issued', 54350, 0, 0, now(), 0, ?, now(), now())",
            [$invoice, $t['tenantA'], $t['facilityA'], $patient, 'INV-'.$suffix, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The capture transaction — payment INSERT + allocation INSERT +
        //    GUARDED optimistic-lock invoice update (the exact SQL the edge
        //    function executes). Full payment: status → 'paid', lock_version 0
        //    → 1.
        $payment = (string) Str::uuid();
        $allocation = (string) Str::uuid();
        $c->insert(
            "insert into payments (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'cash', ?, 54350, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
            [$payment, $t['tenantA'], $t['facilityA'], $patient, 'gw-'.$suffix, 'pay-key-'.$suffix, $actor, $actor]
        );
        $c->insert(
            'insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 54350, now(), ?, now(), now())',
            [$allocation, $t['tenantA'], $payment, $invoice, $actor]
        );
        $updated = $c->update(
            'update invoices set paid_minor = paid_minor + ?, status = ?, lock_version = lock_version + 1, updated_at = now() where id = ? and tenant_id = ? and lock_version = ?',
            [54350, 'paid', $invoice, $t['tenantA'], 0]
        );
        expect($updated)->toBe(1);

        $paymentRow = $c->selectOne('select method, amount_minor, currency, status, idempotency_key, received_by, received_at from payments where id = ?', [$payment]);
        expect($paymentRow->method)->toBe('cash')
            ->and((int) $paymentRow->amount_minor)->toBe(54350)
            ->and($paymentRow->currency)->toBe('NPR')
            ->and($paymentRow->status)->toBe('captured')
            ->and($paymentRow->idempotency_key)->toBe('pay-key-'.$suffix)
            ->and($paymentRow->received_by)->toBe($actor)
            ->and($paymentRow->received_at)->not->toBeNull();
        expect($c->selectOne('select amount_minor from payment_allocations where id = ?', [$allocation])->amount_minor)->toBe(54350);

        $invoiceRow = $c->selectOne('select status, paid_minor, lock_version from invoices where id = ?', [$invoice]);
        expect($invoiceRow->status)->toBe('paid')
            ->and((int) $invoiceRow->paid_minor)->toBe(54350)
            ->and((int) $invoiceRow->lock_version)->toBe(1);

        // 2. IDEMPOTENCY replay: the exact SELECT the edge function runs — the
        //    same (tenant, key) finds the original payment (no new money).
        $replay = $c->selectOne(
            'select id, method, amount_minor, status from payments where tenant_id = ? and idempotency_key = ? limit 1',
            [$t['tenantA'], 'pay-key-'.$suffix]
        );
        expect($replay->id)->toBe($payment)
            ->and((int) $replay->amount_minor)->toBe(54350);
        expect((int) $c->selectOne('select count(*) as total from payments where tenant_id = ?', [$t['tenantA']])->total)->toBe(1);

        // 3. uq_payments_tenant_idempotency is the CONCURRENT same-key backstop
        //    — a second insert with the same key violates it (23505 → retryable
        //    409). Savepoint-isolated.
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into payments (id, tenant_id, facility_id, patient_id, method, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'cash', 54350, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], $patient, 'pay-key-'.$suffix, $actor, $actor]
            );
            expect(true)->toBeFalse('a duplicate idempotency key in the tenant must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        // 4. The GUARDED optimistic-lock update: a STALE expected version
        //    matches ZERO rows, and the payment + allocation already inserted
        //    in that transaction roll back — no orphan rows survive
        //    (LOCK_CONFLICT → 409, the DB decides the winner).
        $c->beginTransaction();
        $loserPayment = (string) Str::uuid();
        try {
            $c->insert(
                "insert into payments (id, tenant_id, facility_id, patient_id, method, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'card', 1000, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
                [$loserPayment, $t['tenantA'], $t['facilityA'], $patient, 'pay-key-race-'.$suffix, $actor, $actor]
            );
            $c->insert(
                'insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 1000, now(), ?, now(), now())',
                [(string) Str::uuid(), $t['tenantA'], $loserPayment, $invoice, $actor]
            );
            expect($c->update(
                'update invoices set paid_minor = paid_minor + ?, status = ?, lock_version = lock_version + 1 where id = ? and tenant_id = ? and lock_version = ?',
                [1000, 'partially_paid', $invoice, $t['tenantA'], 0] // stale — the winner already took 0 → 1
            ))->toBe(0);
            $c->rollBack(); // the adapter rolls the WHOLE transaction back
        } catch (QueryException $e) {
            $c->rollBack();
            throw $e;
        }
        expect($c->selectOne('select id from payments where id = ?', [$loserPayment]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from payments where tenant_id = ?', [$t['tenantA']])->total)->toBe(1)
            ->and((int) $c->selectOne('select count(*) as total from payment_allocations where tenant_id = ?', [$t['tenantA']])->total)->toBe(1)
            ->and($c->selectOne('select status, paid_minor, lock_version from invoices where id = ?', [$invoice])->status)->toBe('paid');

        // 5. Forged cross-tenant claims: every billing row is invisible and
        //    immune to mutation — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payment]))->toBeNull()
            ->and($c->selectOne('select id from payment_allocations where id = ?', [$allocation]))->toBeNull()
            ->and($c->update('update invoices set status = ? where id = ?', ['voided', $invoice]))->toBe(0)
            ->and($c->delete('delete from payments where id = ?', [$payment]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: invisible.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payment]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from invoices')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from payments')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from payment_allocations')->total)->toBe(0);
    });
});
it('invoices:show — the claims-scoped invoice read is RLS-gated, ordered, and mutation-free (Phase 17)', function () {
    // The exact RLS-scoped read invoices:show runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // BillingController::showInvoice + AccessCheck::scoped:
    //  1. the invoice header SELECT by id is visible ONLY under matching
    //     tenant + facility claims (invoices is a TENANT_FACILITY table);
    //  2. the lines SELECT is bound to the verified invoice id and ordered
    //     by line_no exactly as `Invoice::lines()->orderBy('line_no')`;
    //  3. the READ never mutates — status/paid_minor/lock_version are
    //     untouched, and a forged UPDATE matches zero rows;
    //  4. forged cross-tenant / cross-facility claims hide the header (404
    //     semantics); invoice_lines is TENANT_ONLY (Phase 2 matrix) — a
    //     different facility of the same tenant still sees the lines, so
    //     facility isolation is enforced at the RLS-visible header lookup
    //     (the edge function can only read lines of an RLS-visible invoice),
    //     exactly as proven for clinical_notes in Phase 12;
    //  5. missing claims fail closed to zero rows.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient → two posted manual charges →
        // issued invoice (54350 total) → two frozen lines (50000 + 4350).
        $patient = (string) Str::uuid();
        $invoice = (string) Str::uuid();
        $chargeConsult = (string) Str::uuid();
        $chargeMed = (string) Str::uuid();
        $lineConsult = (string) Str::uuid();
        $lineMed = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'show-actor@test.local', 'show-hash', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-SHOW-A', 'Show Patient', '1990-01-01', 'female', 'active']
        );
        // Two posted manual charges (no encounter/prescription linkage needed
        // for the read contract).
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'manual', null, null, ?, ?, 'NPR', 0, 'posted', now(), ?, now(), now())",
            [$chargeConsult, $t['tenantA'], $t['facilityA'], $patient, 'General OPD — consultation', 50000, $actor]
        );
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'manual', null, null, ?, ?, 'NPR', 0, 'posted', now(), ?, now(), now())",
            [$chargeMed, $t['tenantA'], $t['facilityA'], $patient, 'Paracetamol 500mg', 4350, $actor]
        );
        $c->insert(
            "insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, 'issued', 54350, 0, 0, now(), 0, ?, now(), now())",
            [$invoice, $t['tenantA'], $t['facilityA'], $patient, 'INV-SHOW-'.$suffix, $actor]
        );
        // Lines inserted deliberately out of line_no order (line 2 first) —
        // the read must return them ordered by line_no.
        $c->insert(
            'insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no) values (?, ?, ?, ?, ?, ?, ?, 2)',
            [$lineMed, $t['tenantA'], $invoice, $chargeMed, 'Paracetamol 500mg', 4350, 0]
        );
        $c->insert(
            'insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no) values (?, ?, ?, ?, ?, ?, ?, 1)',
            [$lineConsult, $t['tenantA'], $invoice, $chargeConsult, 'General OPD — consultation', 50000, 0]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The header SELECT by id (the exact edge read) — visible under
        //    the authoritative tenant + facility claims.
        $header = $c->selectOne(
            'select id, invoice_number, facility_id, patient_id, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version from invoices where id = ? and tenant_id = ? and facility_id = ? limit 1',
            [$invoice, $t['tenantA'], $t['facilityA']]
        );
        expect($header)->not->toBeNull()
            ->and($header->invoice_number)->toBe('INV-SHOW-'.$suffix)
            ->and($header->facility_id)->toBe($t['facilityA'])
            ->and($header->patient_id)->toBe($patient)
            ->and($header->status)->toBe('issued')
            ->and((int) $header->total_minor)->toBe(54350)
            ->and((int) $header->total_tax_minor)->toBe(0)
            ->and((int) $header->paid_minor)->toBe(0)
            ->and((int) $header->lock_version)->toBe(0)
            ->and($header->issued_at)->not->toBeNull();

        // 2. The lines SELECT — bound to the invoice id and ordered by
        //    line_no (Invoice::lines() parity). The out-of-order inserts
        //    must come back 1 then 2.
        $lines = $c->select(
            'select id, description, amount_minor, tax_minor from invoice_lines where invoice_id = ? order by line_no asc',
            [$invoice]
        );
        expect(count($lines))->toBe(2)
            ->and($lines[0]->id)->toBe($lineConsult)
            ->and((int) $lines[0]->amount_minor)->toBe(50000)
            ->and((int) $lines[0]->tax_minor)->toBe(0)
            ->and($lines[1]->id)->toBe($lineMed)
            ->and((int) $lines[1]->amount_minor)->toBe(4350);

        // 3. The read mutates nothing — status/paid_minor/lock_version are
        //    untouched by the SELECTs above.
        expect($c->selectOne('select status, paid_minor, lock_version from invoices where id = ?', [$invoice])->status)->toBe('issued')
            ->and((int) $c->selectOne('select paid_minor from invoices where id = ?', [$invoice])->paid_minor)->toBe(0)
            ->and((int) $c->selectOne('select lock_version from invoices where id = ?', [$invoice])->lock_version)->toBe(0);

        // 4. Forged cross-tenant claims: the header and its lines are
        //    invisible and immune to mutation — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from invoice_lines where id = ?', [$lineConsult]))->toBeNull()
            ->and($c->update("update invoices set status = 'voided' where id = ?", [$invoice]))->toBe(0)
            ->and($c->delete('delete from invoice_lines where id = ?', [$lineConsult]))->toBe(0);

        // 5. Forged cross-facility claims within the tenant: the HEADER is
        //    invisible (invoices is TENANT_FACILITY), so the edge returns
        //    404 — while invoice_lines is a TENANT-ONLY RLS table (the Phase
        //    2 matrix: tenant clause, no facility clause) and remains visible
        //    to the same tenant. Facility isolation for lines is enforced at
        //    the RLS-visible header lookup — the edge can only read lines of
        //    a verified invoice — exactly as proven for clinical_notes in
        //    Phase 12, not by a facility clause on the lines table itself.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from invoice_lines where id = ?', [$lineConsult]))->not->toBeNull()
            ->and($c->update("update invoices set status = 'voided' where id = ?", [$invoice]))->toBe(0);

        // 6. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from invoice_lines where id = ?', [$lineConsult]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from invoices')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from invoice_lines')->total)->toBe(0);
    });
});
it('invoices:payments — the claims-scoped payment read is RLS-gated, ordered, and mutation-free (Phase 18)', function () {
    // The exact RLS-scoped read invoices:payments runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // BillingController::payments + AccessCheck::scoped:
    //  1. the invoice gate SELECT by id is visible ONLY under matching
    //     tenant + facility claims (invoices is a TENANT_FACILITY table) —
    //     an out-of-scope invoice is 404 before any allocation is read;
    //  2. the allocations read (payment_allocations is TENANT_ONLY) is bound
    //     to the verified invoice id and ordered by allocated_at ascending
    //     exactly as `->orderBy('allocated_at')`;
    //  3. the payment method resolves under the SAME claims (payments is
    //     TENANT_FACILITY) via the LEFT JOIN — an allocation whose payment
    //     lives in another facility of the same tenant renders method = null,
    //     exactly like Laravel's `payment?->method`;
    //  4. the READ never mutates — statuses/amounts are untouched and forged
    //     mutations match zero rows under every claim set;
    //  5. forged cross-tenant / cross-facility / missing claims hide the
    //     invoice (404 semantics) and cannot expand scope.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient → issued invoice (54350 total) →
        // three captured payments (two in fac-a1, one in fac-a2 — same
        // tenant) → three allocations (one per payment — the
        // uq_payment_allocations_tenant_payment_invoice constraint allows at
        // most one), inserted deliberately OUT of allocated_at order; the
        // earliest references the fac-a2 payment.
        $patient = (string) Str::uuid();
        $invoice = (string) Str::uuid();
        $payLocal1 = (string) Str::uuid();
        $payLocal2 = (string) Str::uuid();
        $payOtherFac = (string) Str::uuid();
        $allocEarly = (string) Str::uuid();
        $allocLate = (string) Str::uuid();
        $allocLast = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'payments-actor@test.local', 'pay-hash', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-PAY-A', 'Pay Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            "insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, issued_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, 'issued', 54350, 0, 54350, now(), 0, ?, now(), now())",
            [$invoice, $t['tenantA'], $t['facilityA'], $patient, 'INV-PAY-'.$suffix, $actor]
        );
        // Payment 1: in the caller's facility (fac-a1), cash, 50000.
        $c->insert(
            "insert into payments (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'cash', 'ref-local-1', 50000, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
            [$payLocal1, $t['tenantA'], $t['facilityA'], $patient, 'idem-local-1', $actor, $actor]
        );
        // Payment 2: in the caller's facility (fac-a1), wallet, 1000.
        $c->insert(
            "insert into payments (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'wallet', 'ref-local-2', 1000, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
            [$payLocal2, $t['tenantA'], $t['facilityA'], $patient, 'idem-local-2', $actor, $actor]
        );
        // Payment 3: SAME tenant, DIFFERENT facility (fac-a2) — visible to
        // the tenant-only allocation but filtered by the facility-scoped
        // payments policy for a fac-a1 caller.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            "insert into payments (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor, currency, status, idempotency_key, received_by, received_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'card', 'ref-other-1', 4350, 'NPR', 'captured', ?, ?, now(), ?, now(), now())",
            [$payOtherFac, $t['tenantA'], $facA2, $patient, 'idem-other-1', $actor, $actor]
        );
        // Allocations — one per payment (the unique index allows exactly
        // one), deliberately out of allocated_at order: late first, early
        // second, last third.
        $c->insert(
            "insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 50000, now() + interval '1 minute', ?, now(), now())",
            [$allocLate, $t['tenantA'], $payLocal1, $invoice, $actor]
        );
        $c->insert(
            'insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 4350, now(), ?, now(), now())',
            [$allocEarly, $t['tenantA'], $payOtherFac, $invoice, $actor]
        );
        $c->insert(
            "insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 1000, now() + interval '2 minutes', ?, now(), now())",
            [$allocLast, $t['tenantA'], $payLocal2, $invoice, $actor]
        );
        // The unique index uq_payment_allocations_tenant_payment_invoice is
        // the DB backstop: a second allocation for the same (payment,
        // invoice) pair violates it → 23505. A savepoint isolates the failed
        // statement so the outer transaction survives.
        $c->beginTransaction();
        try {
            $c->insert(
                'insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 1, now(), ?, now(), now())',
                [(string) Str::uuid(), $t['tenantA'], $payLocal1, $invoice, $actor]
            );
            expect(true)->toBeFalse('a second allocation for the same (payment, invoice) must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The invoice gate (the exact edge lookup) — visible under the
        //    authoritative tenant + facility claims.
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->not->toBeNull();

        // 2. The allocations read (the exact edge query) — bound to the
        //    invoice, ordered by allocated_at ascending; the fac-a2 payment
        //    renders method null under the fac-a1 claims (RLS parity with
        //    `payment?->method`).
        $rows = $c->select(
            'select pa.payment_id, pa.amount_minor, pa.allocated_at, p.method
               from payment_allocations pa
               left join payments p on p.id = pa.payment_id and p.tenant_id = pa.tenant_id
              where pa.invoice_id = ?
              order by pa.allocated_at asc',
            [$invoice]
        );
        expect(count($rows))->toBe(3)
            ->and($rows[0]->payment_id)->toBe($payOtherFac)
            ->and($rows[0]->method)->toBeNull() // fac-a2 payment filtered by RLS
            ->and((int) $rows[0]->amount_minor)->toBe(4350)
            ->and($rows[1]->payment_id)->toBe($payLocal1)
            ->and($rows[1]->method)->toBe('cash')
            ->and((int) $rows[1]->amount_minor)->toBe(50000)
            ->and($rows[2]->payment_id)->toBe($payLocal2)
            ->and($rows[2]->method)->toBe('wallet')
            ->and((int) $rows[2]->amount_minor)->toBe(1000);

        // 3. The read mutates nothing — payment status/amounts and the
        //    invoice paid state are untouched by the SELECTs above.
        expect($c->selectOne('select status, amount_minor from payments where id = ?', [$payLocal1])->status)->toBe('captured')
            ->and((int) $c->selectOne('select amount_minor from payments where id = ?', [$payLocal1])->amount_minor)->toBe(50000)
            ->and($c->selectOne('select status, paid_minor from invoices where id = ?', [$invoice])->status)->toBe('issued')
            ->and((int) $c->selectOne('select paid_minor from invoices where id = ?', [$invoice])->paid_minor)->toBe(54350);

        // 4. Forged cross-tenant claims: the invoice gate hides the resource
        //    (404 semantics) and mutation immunity holds for every table.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from payment_allocations where id = ?', [$allocEarly]))->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payLocal1]))->toBeNull()
            ->and($c->update('update payments set status = ? where id = ?', ['refunded', $payLocal1]))->toBe(0)
            ->and($c->delete('delete from payment_allocations where id = ?', [$allocEarly]))->toBe(0);

        // 5. Forged cross-facility claims within the tenant: the INVOICE is
        //    invisible (invoices is TENANT_FACILITY) — the edge 404s before
        //    any allocation read; payment_allocations is TENANT_ONLY so the
        //    rows stay tenant-visible, but they are unreachable through the
        //    RLS-visible invoice, exactly as proven for invoice_lines in
        //    Phase 17. The facility-scoped payments policy flips which
        //    payment's method is visible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from payment_allocations where id = ?', [$allocEarly]))->not->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payLocal1]))->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payOtherFac]))->not->toBeNull()
            ->and($c->update('update invoices set status = ? where id = ?', ['voided', $invoice]))->toBe(0);

        // 6. Missing claims: fail closed to zero rows across the gate and
        //    both scoped tables.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from invoices where id = ?', [$invoice]))->toBeNull()
            ->and($c->selectOne('select id from payment_allocations where id = ?', [$allocEarly]))->toBeNull()
            ->and($c->selectOne('select id from payments where id = ?', [$payLocal1]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from invoices')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from payment_allocations')->total)->toBe(0);
    });
});
it('encounters:charges — the claims-scoped charge read is RLS-gated, ordered, and mutation-free (Phase 19)', function () {
    // The exact RLS-scoped read encounters:charges runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // EncounterController::charges + AccessCheck::scoped:
    //  1. the encounter gate SELECT by id is visible ONLY under matching
    //     tenant + facility claims (encounters is a TENANT_FACILITY table) —
    //     an out-of-scope encounter is 404 before any charge is read;
    //  2. the charges SELECT runs under the same claims (charges is
    //     TENANT_FACILITY) bound to the verified encounter id and ordered by
    //     charged_at ascending exactly as `->orderBy('charged_at')`; ALL
    //     statuses return — including voided (the Laravel hasMany has no
    //     status filter), the presented status lets the client see them;
    //  3. the READ never mutates — charge statuses/amounts and the encounter
    //     state are untouched, and forged mutations match zero rows under
    //     every claim set;
    //  4. forged cross-tenant / cross-facility / missing claims hide the
    //     encounter (404 semantics) and cannot expand scope.
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → checked-in appointment → open encounter → three charges,
        // inserted deliberately OUT of charged_at order; the third is
        // VOIDED (all statuses return).
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $chargeConsult = (string) Str::uuid();
        $chargeRx = (string) Str::uuid();
        $chargeVoid = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-CHG-A', 'Charge Patient', '1990-01-01', 'female', 'active']
        );
        // users is outside the RLS-scoped set — the app-role connection can
        // create the actor (charges.created_by → users.id FK).
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'charges-actor@test.local', 'charges-hash', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 'counter', 1, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );
        // Charges — deliberately out of charged_at order: prescription (later)
        // first, encounter (earliest) second, voided manual (latest) third.
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'prescription', ?, null, ?, ?, 'NPR', 0, 'posted', now() + interval '5 minutes', ?, now(), now())",
            [$chargeRx, $t['tenantA'], $t['facilityA'], $patient, $encounter, 'Paracetamol 500mg x 2', 500, $actor]
        );
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'encounter', ?, null, ?, ?, 'NPR', 0, 'posted', now(), ?, now(), now())",
            [$chargeConsult, $t['tenantA'], $t['facilityA'], $patient, $encounter, 'General OPD — consultation', 50000, $actor]
        );
        $c->insert(
            "insert into charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id, description, amount_minor, currency, tax_rate_bps, status, charged_at, created_by, created_at, updated_at) values (?, ?, ?, ?, 'manual', ?, null, ?, ?, 'NPR', 0, 'voided', now() + interval '10 minutes', ?, now(), now())",
            [$chargeVoid, $t['tenantA'], $t['facilityA'], $patient, $encounter, 'Late fee adjustment', 4350, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The encounter gate (the exact edge lookup) — visible under the
        //    authoritative tenant + facility claims.
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->not->toBeNull();

        // 2. The charges read (the exact edge query) — bound to the
        //    encounter, ordered by charged_at ascending; ALL statuses return
        //    (the voided charge is included with its status).
        $rows = $c->select(
            'select id, source_type, description, amount_minor, currency, status, charged_at
               from charges
              where encounter_id = ?
              order by charged_at asc',
            [$encounter]
        );
        expect(count($rows))->toBe(3)
            ->and($rows[0]->id)->toBe($chargeConsult)
            ->and($rows[0]->source_type)->toBe('encounter')
            ->and((int) $rows[0]->amount_minor)->toBe(50000)
            ->and($rows[0]->status)->toBe('posted')
            ->and($rows[1]->id)->toBe($chargeRx)
            ->and($rows[1]->source_type)->toBe('prescription')
            ->and((int) $rows[1]->amount_minor)->toBe(500)
            ->and($rows[2]->id)->toBe($chargeVoid)
            ->and($rows[2]->source_type)->toBe('manual')
            ->and((int) $rows[2]->amount_minor)->toBe(4350)
            ->and($rows[2]->status)->toBe('voided');

        // 3. The read mutates nothing — charge statuses/amounts and the
        //    encounter state are untouched by the SELECTs above.
        expect($c->selectOne('select status, amount_minor from charges where id = ?', [$chargeVoid])->status)->toBe('voided')
            ->and((int) $c->selectOne('select amount_minor from charges where id = ?', [$chargeConsult])->amount_minor)->toBe(50000)
            ->and($c->selectOne('select status, lock_version from encounters where id = ?', [$encounter])->status)->toBe('open')
            ->and((int) $c->selectOne('select lock_version from encounters where id = ?', [$encounter])->lock_version)->toBe(0);

        // 4. Forged cross-tenant claims: the encounter gate hides the
        //    resource (404 semantics) and mutation immunity holds for every
        //    table.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->selectOne('select id from charges where id = ?', [$chargeConsult]))->toBeNull()
            ->and($c->update('update charges set status = ? where id = ?', ['voided', $chargeConsult]))->toBe(0)
            ->and($c->delete('delete from charges where id = ?', [$chargeRx]))->toBe(0);

        // 5. Forged cross-facility claims within the tenant: the encounter
        //    AND the charges (both TENANT_FACILITY) are invisible — the edge
        //    404s at the encounter gate before any charge read.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->selectOne('select id from charges where id = ?', [$chargeConsult]))->toBeNull()
            ->and($c->update('update encounters set status = ? where id = ?', ['signed', $encounter]))->toBe(0);

        // 6. Missing claims: fail closed to zero rows across the gate and
        //    the charges table.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->selectOne('select id from charges where id = ?', [$chargeConsult]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from encounters')->total)->toBe(0)
            ->and((int) $c->selectOne('select count(*) as total from charges')->total)->toBe(0);
    });
});
it('encounters:show — the claims-scoped encounter read is RLS-gated and mutation-free (Phase 20)', function () {
    // The exact RLS-scoped read encounters:show runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // EncounterController::show + AccessCheck::scoped:
    //  1. the encounter SELECT by id (the 11 present() columns) is visible
    //     ONLY under matching tenant + facility claims (encounters is a
    //     TENANT_FACILITY table) — an out-of-scope encounter is 404;
    //  2. the read never mutates — status/lock_version are untouched and
    //     forged mutations match zero rows under every claim set;
    //  3. forged cross-tenant / cross-facility / missing claims hide the
    //     encounter and cannot expand scope. (The encounter.viewed audit
    //     append is proven at the harness tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → checked-in appointment → open encounter.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-SHW-A', 'Show Patient', '1990-01-01', 'female', 'active']
        );
        // users is outside the RLS-scoped set — the app-role connection can
        // create the actor (encounters.created_by → users.id FK).
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'enc-show-actor@test.local', 'enc-show-hash', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 'counter', 1, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The encounter read (the exact edge query) — the 11 present()
        //    columns, visible under the authoritative tenant + facility
        //    claims with the exact present() values.
        $row = $c->selectOne(
            'select id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, ended_at, signed_at, lock_version
               from encounters
              where id = ? and tenant_id = ? and facility_id = ?
              limit 1',
            [$encounter, $t['tenantA'], $t['facilityA']]
        );
        expect($row)->not->toBeNull()
            ->and($row->facility_id)->toBe($t['facilityA'])
            ->and($row->patient_id)->toBe($patient)
            ->and($row->appointment_id)->toBe($appt)
            ->and($row->provider_staff_id)->toBe($provider)
            ->and($row->type)->toBe('opd')
            ->and($row->status)->toBe('open')
            ->and($row->started_at)->not->toBeNull()
            ->and($row->ended_at)->toBeNull()
            ->and($row->signed_at)->toBeNull()
            ->and((int) $row->lock_version)->toBe(0);

        // 2. The read mutates nothing — status/lock_version are untouched.
        expect($c->selectOne('select status, lock_version from encounters where id = ?', [$encounter])->status)->toBe('open')
            ->and((int) $c->selectOne('select lock_version from encounters where id = ?', [$encounter])->lock_version)->toBe(0);

        // 3. Forged cross-tenant claims: the encounter is invisible and
        //    mutation-immune — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->update('update encounters set status = ? where id = ?', ['signed', $encounter]))->toBe(0);

        // 4. Forged cross-facility claims within the tenant: the encounter
        //    is invisible (404 semantics).
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and($c->update('update encounters set status = ? where id = ?', ['signed', $encounter]))->toBe(0);

        // 5. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from encounters where id = ?', [$encounter]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from encounters')->total)->toBe(0);
    });
});
it('appointments:show — the claims-scoped appointment read is RLS-gated, refs-scoped, and mutation-free (Phase 21)', function () {
    // The exact RLS-scoped read appointments:show runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // AppointmentController::show + AccessCheck::scoped:
    //  1. the appointment SELECT by id (the 15 present() columns) is visible
    //     ONLY under matching tenant + facility claims (appointments is a
    //     TENANT_FACILITY table) — an out-of-scope appointment is 404;
    //  2. the patient/provider REF selects resolve under the SAME claims
    //     (patients and staff are both TENANT_FACILITY) — an out-of-facility
    //     related row renders null (payment?->method parity), never a leak;
    //  3. the read never mutates — status/lock_version/token_no are
    //     untouched and forged mutations match zero rows under every claim
    //     set;
    //  4. forged cross-tenant / cross-facility / missing claims hide the
    //     appointment and cannot expand scope. (The no-audit contract is
    //     proven at the harness tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → booked appointment. A second patient in facility A2 proves the
        // related-ref scope (out-of-facility patient → null ref).
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $patientX = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-SHW-A', 'Show Patient', '1990-01-01', 'female', 'active']
        );
        // Facility A2 (same tenant) — the out-of-scope related ref.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientX, $t['tenantA'], $facA2, 'MRN-SHW-X', 'Cross Patient', '1992-02-02', 'male', 'active']
        );
        // users is outside the RLS-scoped set — the app-role connection can
        // create the actor (appointments.created_by → users.id FK).
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'appt-show-actor@test.local', 'appt-show-hash', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'booked', 'counter', 0, $actor]
        );

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The appointment read (the exact edge query) — the 15 present()
        //    columns, visible under the authoritative tenant + facility
        //    claims with the exact present() values.
        $row = $c->selectOne(
            'select id, facility_id, patient_id, provider_staff_id, service_id,
                    appointment_type, starts_at, ends_at, status, token_no,
                    source, cancel_reason, lock_version
               from appointments
              where id = ? and tenant_id = ? and facility_id = ?
              limit 1',
            [$appt, $t['tenantA'], $t['facilityA']]
        );
        expect($row)->not->toBeNull()
            ->and($row->facility_id)->toBe($t['facilityA'])
            ->and($row->patient_id)->toBe($patient)
            ->and($row->provider_staff_id)->toBe($provider)
            ->and($row->service_id)->toBeNull()
            ->and($row->appointment_type)->toBe('opd')
            ->and($row->starts_at)->not->toBeNull()
            ->and($row->ends_at)->not->toBeNull()
            ->and($row->status)->toBe('booked')
            ->and($row->token_no)->toBeNull()
            ->and($row->source)->toBe('counter')
            ->and($row->cancel_reason)->toBeNull()
            ->and((int) $row->lock_version)->toBe(0);

        // 2. The related refs resolve under the SAME claims — the patient
        //    and provider in scope render their exact ref fields.
        $patientRef = $c->selectOne(
            'select id, mrn, full_name from patients
              where id = ? and tenant_id = ? and facility_id = ?
              limit 1',
            [$patient, $t['tenantA'], $t['facilityA']]
        );
        expect($patientRef)->not->toBeNull()
            ->and($patientRef->mrn)->toBe('MRN-SHW-A')
            ->and($patientRef->full_name)->toBe('Show Patient');
        $providerRef = $c->selectOne(
            'select id, facility_id, full_name from staff
              where id = ? and tenant_id = ? and facility_id = ? and status <> \'departed\'
              limit 1',
            [$provider, $t['tenantA'], $t['facilityA']]
        );
        expect($providerRef)->not->toBeNull()
            ->and($providerRef->full_name)->toBe('Dr. Provider A');

        // 3. An out-of-facility related row is invisible under the same
        //    claims → the presentation renders the ref as null (never a
        //    leak). The patient in facility A2 is NOT visible from facA1.
        expect($c->selectOne(
            'select id from patients
              where id = ? and tenant_id = ? and facility_id = ?
              limit 1',
            [$patientX, $t['tenantA'], $t['facilityA']]
        ))->toBeNull();

        // 4. The read mutates nothing — status/lock_version/token_no are
        //    untouched.
        $stored = $c->selectOne('select status, lock_version, token_no from appointments where id = ?', [$appt]);
        expect($stored->status)->toBe('booked')
            ->and((int) $stored->lock_version)->toBe(0)
            ->and($stored->token_no)->toBeNull();

        // 5. Forged cross-tenant claims: the appointment is invisible and
        //    mutation-immune — RLS is the final boundary.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->update('update appointments set status = ? where id = ?', ['cancelled', $appt]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: the appointment
        //    is invisible (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and($c->update('update appointments set status = ? where id = ?', ['cancelled', $appt]))->toBe(0);

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->selectOne('select id from appointments where id = ?', [$appt]))->toBeNull()
            ->and((int) $c->selectOne('select count(*) as total from appointments')->total)->toBe(0);
    });
});
it('appointments:index — the claims-scoped appointment list is RLS-gated, ordered, filterable, and mutation-free (Phase 22)', function () {
    // The exact RLS-scoped list appointments:index runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // AppointmentController::index:
    //  1. the appointment SELECT (the 15 present() columns + the LEFT-JOINed
    //     patient/provider refs) is visible ONLY under matching tenant +
    //     facility claims (appointments/patients/staff are TENANT_FACILITY)
    //     — an org-level claim (facility NULL) sees every facility of the
    //     tenant (RLS facilityClause parity), a facility claim narrows to
    //     that facility;
    //  2. ordering is `starts_at` ascending (the only Laravel ordering key);
    //  3. the `date` (date(starts_at) = ?) and `providerStaffId` filters
    //     narrow exactly like the Laravel query;
    //  4. an out-of-facility related row joins to NULL — the ref renders
    //     null, never a leak;
    //  5. the read never mutates — status/lock_version are untouched and
    //     forged mutations match zero rows under every claim set;
    //  6. forged cross-tenant / cross-facility / missing claims hide rows
    //     and cannot expand scope. (The no-audit contract is proven at the
    //     harness tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → two providers (staff) →
        // patients (fac-a1 + fac-a2) → three appointments (two in fac-a1 on
        // the same day at different times/providers, one in fac-a2).
        $department = (string) Str::uuid();
        $providerA = (string) Str::uuid();
        $providerB = (string) Str::uuid();
        $providerX = (string) Str::uuid();
        $patientA = (string) Str::uuid();
        $patientX = (string) Str::uuid();
        $apptA = (string) Str::uuid();
        $apptB = (string) Str::uuid();
        $apptX = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerA, $t['tenantA'], $t['facilityA'], $department, 'EMP-A-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerB, $t['tenantA'], $t['facilityA'], $department, 'EMP-B-'.$suffix, 'Dr. Provider B', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-IDX-A', 'Index Patient', '1990-01-01', 'female', 'active']
        );
        // Facility A2 (same tenant) — the out-of-facility ref case.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $departmentX = (string) Str::uuid();
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$departmentX, $t['tenantA'], $facA2, 'OPD-A2', 'opd-a2-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerX, $t['tenantA'], $facA2, $departmentX, 'EMP-X-'.$suffix, 'Dr. Provider X', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientX, $t['tenantA'], $facA2, 'MRN-IDX-X', 'Cross Patient', '1992-02-02', 'male', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'appt-index-actor@test.local', 'appt-index-hash', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptA, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'booked', 'counter', 0, $actor]
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptB, $t['tenantA'], $t['facilityA'], $patientA, $providerB, 'opd', '2026-03-02 11:00:00', '2026-03-02 11:30:00', 'booked', 'counter', 0, $actor]
        );
        // The fac-a2 appointment references the fac-a2 patient/provider — its
        // rows are invisible under fac-a1 claims (and its refs under fac-a2
        // claims resolve in scope).
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptX, $t['tenantA'], $facA2, $patientX, $providerX, 'opd', '2026-03-02 10:00:00', '2026-03-02 10:30:00', 'booked', 'counter', 0, $actor]
        );

        $claimsFacA1 = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        // `coalesce(?, x.facility_id) = x.facility_id` is the null-safe
        // facilityClause spelling that also gives PG a uuid type for the
        // parameter (a bare `? is null` cannot be type-inferred — 42P18).
        $select = 'select a.id, a.facility_id, a.patient_id, a.provider_staff_id, a.service_id,
                          a.appointment_type, a.starts_at, a.ends_at, a.status, a.token_no,
                          a.source, a.cancel_reason, a.lock_version,
                          p.id as patient_ref_id, p.mrn as patient_ref_mrn, p.full_name as patient_ref_full_name,
                          s.id as provider_ref_id, s.full_name as provider_ref_full_name,
                          s.facility_id as provider_ref_facility_id
                     from public.appointments a
                     left join public.patients p
                            on p.id = a.patient_id and p.tenant_id = a.tenant_id
                           and coalesce(?, p.facility_id) = p.facility_id
                     left join public.staff s
                            on s.id = a.provider_staff_id and s.tenant_id = a.tenant_id
                           and coalesce(?, s.facility_id) = s.facility_id and s.status <> \'departed\'
                    where a.tenant_id = ? and coalesce(?, a.facility_id) = a.facility_id';

        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The exact edge query (fac-a1 claims): ONLY the fac-a1 rows,
        //    ordered by starts_at ascending, refs resolved.
        $rows = $c->select($select.' order by a.starts_at asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'],
        ]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$apptA, $apptB])
            ->and($rows[0]->patient_ref_id)->toBe($patientA)
            ->and($rows[0]->provider_ref_id)->toBe($providerA)
            ->and($rows[1]->provider_ref_id)->toBe($providerB)
            ->and($rows[0]->status)->toBe('booked')
            ->and((int) $rows[0]->lock_version)->toBe(0)
            ->and($rows[0]->token_no)->toBeNull()
            ->and($rows[0]->source)->toBe('counter');

        // 2. The date filter (whereDate parity): only the same-day rows.
        $dayRows = $c->select($select.' and date(a.starts_at) = ? order by a.starts_at asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], '2026-03-02',
        ]);
        expect(array_map(fn ($r) => $r->id, $dayRows))->toBe([$apptA, $apptB]);

        // 3. The providerStaffId filter: only that provider's rows.
        $providerRows = $c->select($select.' and a.provider_staff_id = ? order by a.starts_at asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], $providerB,
        ]);
        expect(array_map(fn ($r) => $r->id, $providerRows))->toBe([$apptB]);

        // 4. The out-of-facility ref case: a fac-a1 appointment whose patient
        //    lives in fac-a2 joins to NULL — the ref renders null, never a leak.
        $apptCross = (string) Str::uuid();
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptCross, $t['tenantA'], $t['facilityA'], $patientX, $providerA, 'opd', '2026-03-02 12:00:00', '2026-03-02 12:30:00', 'booked', 'counter', 0, $actor]
        );
        $crossRow = $c->selectOne($select.' and a.id = ?', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], $apptCross,
        ]);
        expect($crossRow)->not->toBeNull()
            ->and($crossRow->patient_ref_id)->toBeNull()
            ->and($crossRow->provider_ref_id)->toBe($providerA);

        // 5. Org-level claims (facility NULL): every facility of the tenant —
        //    fac-a1 AND fac-a2 rows (RLS facilityClause parity).
        DatabaseTenantContext::setClaims([
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by a.starts_at asc', [
            null, null, $t['tenantA'], null,
        ]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$apptA, $apptX, $apptB, $apptCross]);
        // The fac-a2 row's patient/provider refs are visible under the
        // org-level claim.
        $orgCross = collect($orgRows)->firstWhere('id', $apptX);
        expect($orgCross->patient_ref_id)->toBe($patientX)
            ->and($orgCross->provider_ref_id)->toBe($providerX);

        // 6. The read mutates nothing — status/lock_version are untouched.
        expect($c->selectOne('select status, lock_version from appointments where id = ?', [$apptA])->status)->toBe('booked')
            ->and((int) $c->selectOne('select lock_version from appointments where id = ?', [$apptA])->lock_version)->toBe(0);

        // 7. Forged cross-tenant claims: invisible and mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select.' order by a.starts_at asc', [
            $t['facilityB'], $t['facilityB'], $t['tenantB'], $t['facilityB'],
        ]))->toBe([])
            ->and($c->update('update appointments set status = ? where id = ?', ['cancelled', $apptA]))->toBe(0);

        // 8. Forged cross-facility claims within the tenant: only fac-a2 rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $facA2Rows = $c->select($select.' order by a.starts_at asc', [
            $facA2, $facA2, $t['tenantA'], $facA2,
        ]);
        expect(array_map(fn ($r) => $r->id, $facA2Rows))->toBe([$apptX]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select.' order by a.starts_at asc', [
            null, null, null, null,
        ]))->toBe([])
            ->and((int) $c->selectOne('select count(*) as total from appointments')->total)->toBe(0);
    });
});
it('patients:search — the claims-scoped patient search is RLS-gated, active-only, score-ordered, and mutation-free (Phase 23)', function () {
    // The exact RLS-scoped search patients:search runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PatientController::search:
    //  1. the patients SELECT (the 7 result columns + pg_trgm
    //     similarity(lower(full_name), q)) is visible ONLY under matching
    //     tenant + facility claims (patients is TENANT_FACILITY) — an
    //     org-level claim (facility NULL) searches the whole tenant (RLS
    //     facilityClause parity), a facility claim narrows to that facility;
    //  2. `status = 'active'` filters out archived/merged patients;
    //  3. the case-insensitive `lower(full_name) like '%q%' or lower(mrn)
    //     like 'q%'` match (LIKE wildcards unescaped — Laravel parity) and
    //     the REAL pg_trgm similarity score + `order by score desc limit 20`
    //     reproduce the Laravel query exactly;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The patient.searched audit append is proven at the harness tier — the
    // handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixtures: two active patients + one archived patient
        // (the status filter) + a fac-a2 patient (facility scope) + an
        // org-b patient (tenant scope).
        $patA = (string) Str::uuid();
        $patB = (string) Str::uuid();
        $patC = (string) Str::uuid();
        $patX = (string) Str::uuid();
        $patY = (string) Str::uuid();
        $facA2 = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patA, $t['tenantA'], $t['facilityA'], 'MRN-A1-001', 'Aarav Shrestha', '1990-01-01', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patB, $t['tenantA'], $t['facilityA'], 'MRN-A1-002', 'Bimala Gurung', '1985-06-15', 'female', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patC, $t['tenantA'], $t['facilityA'], 'MRN-A1-003', 'Aarav Archived', '1978-03-22', 'other', 'archived']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patX, $t['tenantA'], $facA2, 'MRN-A2-001', 'Chandra Thapa', '1978-03-22', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patY, $t['tenantB'], $t['facilityB'], 'MRN-B1-001', 'Devaki Lama', '1995-11-30', 'female', 'active']
        );

        // The exact edge query (coalesce = the null-safe facilityClause).
        $select = 'select id, mrn, full_name, date_of_birth, sex, facility_id,
                          similarity(lower(full_name), ?) as score
                     from public.patients
                    where tenant_id = ?
                      and coalesce(?, facility_id) = facility_id
                      and status = \'active\'
                      and (lower(full_name) like ? or lower(mrn) like ?)
                    order by score desc
                    limit 20';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. Name-substring match: only the active Aarav, exact 7 fields,
        //    real pg_trgm score > 0.
        $rows = $c->select($select, ['aar', $t['tenantA'], $t['facilityA'], '%aar%', 'aar%']);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$patA])
            ->and($rows[0]->mrn)->toBe('MRN-A1-001')
            ->and($rows[0]->full_name)->toBe('Aarav Shrestha')
            ->and($rows[0]->date_of_birth)->toBe('1990-01-01')
            ->and($rows[0]->sex)->toBe('male')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and((float) $rows[0]->score)->toBeGreaterThan(0.0);

        // 2. The archived 'Aarav Archived' is EXCLUDED — status = 'active'
        //    is part of the query.
        $aaravRows = $c->select($select, ['aarav', $t['tenantA'], $t['facilityA'], '%aarav%', 'aarav%']);
        expect(array_map(fn ($r) => $r->id, $aaravRows))->toBe([$patA]);

        // 3. MRN-prefix match: both active MRN-A1 patients, archived excluded.
        $mrnRows = $c->select($select, ['mrn-a1', $t['tenantA'], $t['facilityA'], '%mrn-a1%', 'mrn-a1%']);
        expect(array_map(fn ($r) => $r->id, $mrnRows))->toBe([$patA, $patB]);

        // 4. Real pg_trgm ordering: 'shrestha' ranks Aarav first (the ONLY
        //    name containing it), so the order is deterministic.
        $nameRows = $c->select($select, ['shrestha', $t['tenantA'], $t['facilityA'], '%shrestha%', 'shrestha%']);
        expect(array_map(fn ($r) => $r->id, $nameRows))->toBe([$patA])
            ->and((float) $nameRows[0]->score)->toBeGreaterThan(0.0);

        // 5. LIKE wildcard parity (unescaped): 'a%r' matches any name with
        //    an 'a' followed later by an 'r' — Aarav + Bimala, archived out.
        $wildRows = $c->select($select, ['a%r', $t['tenantA'], $t['facilityA'], '%a%r%', 'a%r%']);
        expect(array_map(fn ($r) => $r->id, $wildRows))->toBe([$patA, $patB]);

        // 6. Facility scope: the fac-a2 patient is invisible to fac-a1.
        expect($c->select($select, ['chandra', $t['tenantA'], $t['facilityA'], '%chandra%', 'chandra%']))->toBe([]);

        // 7. Org-level claims (facility NULL): the whole tenant — Chandra
        //    (fac-a2) IS found.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select, ['chandra', $t['tenantA'], null, '%chandra%', 'chandra%']);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$patX]);

        // 8. Tenant scope: the org-b patient never appears under org-a
        //    claims (facility-scoped AND org-level).
        expect($c->select($select, ['devaki', $t['tenantA'], null, '%devaki%', 'devaki%']))->toBe([]);

        // 9. The read mutates nothing — statuses are untouched.
        expect($c->selectOne('select status from patients where id = ?', [$patA])->status)->toBe('active');

        // 10. Forged cross-tenant claims: zero rows and mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, ['aar', $t['tenantB'], $t['facilityB'], '%aar%', 'aar%']))->toBe([])
            ->and($c->update('update patients set status = ? where id = ?', ['archived', $patA]))->toBe(0);

        // 11. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, ['aar', null, null, '%aar%', 'aar%']))->toBe([]);
    });
});
it('encounters:notes — the claims-scoped clinical-notes read is RLS-gated, ordered, ref-scoped, and mutation-free (Phase 25)', function () {
    // The exact RLS-scoped read encounters:notes runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // EncounterController::notes + AccessCheck::scoped:
    //  1. the encounter gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (encounters is TENANT_FACILITY);
    //  2. the clinical_notes SELECT (the 6 presented fields + the author
    //     ref) is tenant-scoped (clinical_notes is TENANT_ONLY) and bound to
    //     the verified encounter, ordered by created_at ascending — the
    //     exact `->orderBy('created_at')`; ALL statuses return;
    //  3. the author ref joins under the same claims (staff is
    //     TENANT_FACILITY) — an out-of-scope author renders NULL, never a
    //     leak;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → checked-in appointment → open encounter; a second facility
        // (fac-a2) with its own department/provider for the out-of-scope
        // author ref.
        $department = (string) Str::uuid();
        $provider = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $appt = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $departmentA2 = (string) Str::uuid();
        $providerA2 = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$provider, $t['tenantA'], $t['facilityA'], $department, 'EMP-'.$suffix, 'Dr. Provider A', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-NOT-A', 'Notes Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$departmentA2, $t['tenantA'], $facA2, 'OPD', 'opd2-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerA2, $t['tenantA'], $facA2, $departmentA2, 'EMP2-'.$suffix, 'Dr. Provider A2', 'Doctor', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'enc-notes-actor@test.local', 'enc-notes-hash', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$appt, $t['tenantA'], $t['facilityA'], $patient, $provider, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 'counter', 1, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patient, $appt, $provider, $actor]
        );

        // Three notes on the encounter — draft / signed / amended (ALL
        // statuses return), seeded out of created_at order (the read must
        // order by created_at ascending); one note authored by the fac-a2
        // provider (the ref must render NULL under fac-a1 claims); one
        // cross-tenant note (must be invisible — the tenant filter).
        $noteDraft = (string) Str::uuid();
        $noteSigned = (string) Str::uuid();
        $noteAmended = (string) Str::uuid();
        $noteForeignAuthor = (string) Str::uuid();
        $noteOtherTenant = (string) Str::uuid();

        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, 'consultation', ?, '{\"complaint\":\"Fever\"}'::jsonb, 'draft', null, 0, ?, '2026-03-02 09:05:00', '2026-03-02 09:05:00')",
            [$noteDraft, $t['tenantA'], $encounter, $provider, $actor]
        );
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, 'consultation', ?, '{\"assessment\":\"Viral illness\"}'::jsonb, 'signed', '2026-03-02 09:10:00', 1, ?, '2026-03-02 09:06:00', '2026-03-02 09:06:00')",
            [$noteSigned, $t['tenantA'], $encounter, $provider, $actor]
        );
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, 'consultation', ?, '{\"plan\":\"Rest\"}'::jsonb, 'amended', '2026-03-02 09:12:00', 2, ?, '2026-03-02 09:08:00', '2026-03-02 09:08:00')",
            [$noteAmended, $t['tenantA'], $encounter, $provider, $actor]
        );
        // Same tenant, fac-a2 author — visible note, NULL author ref under
        // fac-a1 claims.
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by, created_at, updated_at) values (?, ?, ?, 'nursing', ?, '{\"note\":\"Foreign author\"}'::jsonb, 'draft', null, 0, ?, '2026-03-02 09:20:00', '2026-03-02 09:20:00')",
            [$noteForeignAuthor, $t['tenantA'], $encounter, $providerA2, $actor]
        );
        // Other tenant — its own department + provider satisfy the composite
        // author FK; encounter_id null keeps the encounter FK happy. The
        // tenant filter hides the row entirely.
        $departmentB = (string) Str::uuid();
        $providerB = (string) Str::uuid();
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$departmentB, $t['tenantB'], $t['facilityB'], 'OPD', 'opdb-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerB, $t['tenantB'], $t['facilityB'], $departmentB, 'EMPB-'.$suffix, 'Dr. Provider B', 'Doctor', 'active']
        );
        $c->insert(
            "insert into clinical_notes (id, tenant_id, encounter_id, note_type, author_staff_id, content, status, signed_at, lock_version, created_by, created_at, updated_at) values (?, ?, null, 'consultation', ?, '{\"note\":\"Other tenant\"}'::jsonb, 'draft', null, 0, ?, '2026-03-02 09:30:00', '2026-03-02 09:30:00')",
            [$noteOtherTenant, $t['tenantB'], $providerB, $actor]
        );

        // The exact edge queries (the encounter gate + the notes read with
        // the null-safe coalesce facilityClause on the author join).
        $gate = 'select id from public.encounters where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $notes = 'select n.id, n.note_type, n.content, n.status, n.signed_at::text,
                         s.id as author_id, s.full_name as author_full_name
                    from public.clinical_notes n
                    left join public.staff s
                           on s.id = n.author_staff_id and s.tenant_id = n.tenant_id
                          and coalesce(?, s.facility_id) = s.facility_id and s.status <> \'departed\'
                   where n.encounter_id = ? and n.tenant_id = ?
                   order by n.created_at asc';

        $claimsA = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The encounter gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$encounter, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The notes read: all statuses, ordered by created_at ascending,
        //    exact 6-field shape + the in-scope author ref.
        $rows = $c->select($notes, [$t['facilityA'], $encounter, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$noteDraft, $noteSigned, $noteAmended, $noteForeignAuthor])
            ->and($rows[0]->note_type)->toBe('consultation')
            ->and($rows[0]->status)->toBe('draft')
            ->and($rows[0]->signed_at)->toBeNull()
            ->and(json_decode($rows[0]->content, true))->toBe(['complaint' => 'Fever'])
            ->and($rows[0]->author_id)->toBe($provider)
            ->and($rows[0]->author_full_name)->toBe('Dr. Provider A')
            ->and($rows[1]->status)->toBe('signed')
            ->and($rows[1]->signed_at)->toBe('2026-03-02 09:10:00+00')
            ->and($rows[2]->status)->toBe('amended')
            ->and($rows[2]->signed_at)->toBe('2026-03-02 09:12:00+00');

        // 3. The out-of-scope author ref renders NULL — never a leak.
        expect($rows[3]->author_id)->toBeNull()
            ->and($rows[3]->author_full_name)->toBeNull();

        // 4. The cross-tenant note is invisible — the tenant filter.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($noteOtherTenant);

        // 5. The read mutates nothing — statuses/lock versions are
        //    untouched.
        expect($c->selectOne('select status, lock_version from encounters where id = ?', [$encounter])->status)->toBe('open')
            ->and((int) $c->selectOne('select lock_version from encounters where id = ?', [$encounter])->lock_version)->toBe(0)
            ->and($c->selectOne('select status from clinical_notes where id = ?', [$noteDraft])->status)->toBe('draft');

        // 6. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$encounter, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($notes, [$t['facilityB'], $encounter, $t['tenantB']]))->toBe([])
            ->and($c->update('update clinical_notes set status = ? where id = ?', ['signed', $noteDraft]))->toBe(0);

        // 7. Forged cross-facility claims within the tenant: the gate hides
        //    the encounter (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$encounter, $t['tenantA'], $facA2]))->toBeNull();

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($notes, [null, $encounter, null]))->toBe([]);
    });
});
it('patients:timeline — the claims-scoped timeline read is RLS-gated, ordered, and mutation-free (Phase 26)', function () {
    // The exact RLS-scoped read patients:timeline runs is proven on the REAL
    // app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PatientController::timeline + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the patient_timeline_entries SELECT (the 4 presented fields) is
    //     tenant-scoped (patient_timeline_entries is TENANT_ONLY) and bound
    //     to the verified patient, ordered by occurred_at DESC then id DESC
    //     — the exact `->orderByDesc('occurred_at')->orderByDesc('id')`;
    //  3. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient + four timeline entries (distinct
        // event types) seeded out of occurred_at order (the read must order
        // by occurred_at DESC); a fac-a2 patient with entries (facility
        // scope); an org-b patient with entries (tenant scope).
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $entryRegistered = (string) Str::uuid();
        $entryIdentifier = (string) Str::uuid();
        $entryDocument = (string) Str::uuid();
        $entryConsent = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-TL-A', 'Timeline Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-TL-A2', 'Timeline A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-TL-B', 'Timeline B', '1975-09-09', 'other', 'active']
        );

        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 09:00:00', 'patient.identifier.added', '{\"type\":\"national_id\"}'::jsonb)",
            [$entryIdentifier, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 10:00:00', 'patient.registered', '{\"by\":\"registration-desk\"}'::jsonb)",
            [$entryRegistered, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 08:00:00', 'patient.document.attached', '{\"kind\":\"report\"}'::jsonb)",
            [$entryDocument, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 11:00:00', 'patient.consent.updated', '{\"scope\":\"care\"}'::jsonb)",
            [$entryConsent, $t['tenantA'], $patient]
        );
        // Same-tenant fac-a2 patient's own entry (invisible to fac-a1).
        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 09:30:00', 'patient.registered', '{}'::jsonb)",
            [(string) Str::uuid(), $t['tenantA'], $patientA2]
        );
        // Other-tenant patient's own entry (invisible to org-a).
        $c->insert(
            "insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, '2026-03-02 09:30:00', 'patient.registered', '{}'::jsonb)",
            [(string) Str::uuid(), $t['tenantB'], $patientB]
        );

        // The exact edge queries.
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $timeline = 'select id, occurred_at::text, event_type, summary
                       from public.patient_timeline_entries
                      where patient_id = ? and tenant_id = ?
                      order by occurred_at desc, id desc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The timeline read: the exact 4-field shape, ordered by
        //    occurred_at DESC (the entries were seeded out of order).
        $rows = $c->select($timeline, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$entryConsent, $entryRegistered, $entryIdentifier, $entryDocument])
            ->and($rows[0]->event_type)->toBe('patient.consent.updated')
            ->and($rows[0]->occurred_at)->toBe('2026-03-02 11:00:00+00')
            ->and(json_decode($rows[0]->summary, true))->toBe(['scope' => 'care'])
            ->and($rows[1]->event_type)->toBe('patient.registered')
            ->and($rows[2]->event_type)->toBe('patient.identifier.added')
            ->and($rows[3]->event_type)->toBe('patient.document.attached')
            ->and($rows[3]->occurred_at)->toBe('2026-03-02 08:00:00+00');

        // 3. The fac-a2 patient's entry never leaks into the fac-a1 read
        //    (the read is bound to the verified patient + tenant).
        expect($rows)->toHaveCount(4);

        // 4. The read mutates nothing — the entries are untouched.
        expect($c->selectOne('select event_type from patient_timeline_entries where id = ?', [$entryRegistered])->event_type)->toBe('patient.registered');

        // 5. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($timeline, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update patient_timeline_entries set event_type = ? where id = ?', ['patient.registered', $entryRegistered]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($timeline, [$patient, null]))->toBe([]);
    });
});
it('appointments:queue — the claims-scoped queue read is RLS-gated, status-filtered, ordered, and mutation-free (Phase 27)', function () {
    // The exact RLS-scoped queue read appointments:queue runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // AppointmentController::queue:
    //  1. the appointment SELECT (the 6 presented fields + the LEFT-JOINed
    //     patient ref / encounter id) is visible ONLY under matching tenant
    //     + facility claims (appointments/patients/encounters are
    //     TENANT_FACILITY) — an org-level claim (facility NULL) sees every
    //     facility of the tenant (RLS facilityClause parity), a facility
    //     claim narrows to that facility;
    //  2. the status IN (checked_in, in_consultation) filter is ALWAYS
    //     applied — booked/cancelled/completed rows never appear;
    //  3. ordering is `token_no` ascending (the only Laravel ordering key;
    //     the ASC default NULLS LAST);
    //  4. the `date(a.starts_at) = date` and optional providerStaffId
    //     filters narrow exactly like the Laravel query;
    //  5. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the default-date behavior are proven at the
    // harness tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: department → provider (staff) → patient
        // → five appointments on 2026-03-02 (checked_in/in_consultation with
        // token numbers seeded out of order + a NULL-token edge + a booked
        // row for the status filter) + one encounter on the token-2 visit
        // (the encounterId ref); a fac-a2 appointment (facility scope) and
        // an org-b appointment (tenant scope).
        $department = (string) Str::uuid();
        $providerA = (string) Str::uuid();
        $patientA = (string) Str::uuid();
        $actor = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerA, $t['tenantA'], $t['facilityA'], $department, 'EMP-Q-'.$suffix, 'Dr. Provider Q', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-Q-A', 'Queue Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$actor, 'appt-queue-actor@test.local', 'appt-queue-hash', 'active']
        );

        $apptQ1 = (string) Str::uuid(); // checked_in, token 3
        $apptQ2 = (string) Str::uuid(); // in_consultation, token 1
        $apptQ3 = (string) Str::uuid(); // booked — status filter
        $apptQ4 = (string) Str::uuid(); // checked_in, token NULL
        $apptQ5 = (string) Str::uuid(); // in_consultation, token 2 + encounter
        $facA2 = (string) Str::uuid();
        $apptX = (string) Str::uuid(); // fac-a2 checked_in
        $apptB = (string) Str::uuid(); // org-b checked_in
        $encounter = (string) Str::uuid();

        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptQ1, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 3, 'counter', 1, $actor]
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptQ2, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:05:00', '2026-03-02 09:35:00', 'in_consultation', 1, 'counter', 1, $actor]
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptQ3, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:10:00', '2026-03-02 09:40:00', 'booked', 4, 'counter', 0, $actor]
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptQ4, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:15:00', '2026-03-02 09:45:00', 'checked_in', null, 'counter', 1, $actor]
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptQ5, $t['tenantA'], $t['facilityA'], $patientA, $providerA, 'opd', '2026-03-02 09:20:00', '2026-03-02 09:50:00', 'in_consultation', 2, 'counter', 1, $actor]
        );
        $c->insert(
            "insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version, created_by) values (?, ?, ?, ?, ?, ?, 'opd', 'open', now(), 0, ?)",
            [$encounter, $t['tenantA'], $t['facilityA'], $patientA, $apptQ5, $providerA, $actor]
        );

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $departmentX = (string) Str::uuid();
        $providerX = (string) Str::uuid();
        $patientX = (string) Str::uuid();
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$departmentX, $t['tenantA'], $facA2, 'OPD-A2', 'opd-a2-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerX, $t['tenantA'], $facA2, $departmentX, 'EMP-QX-'.$suffix, 'Dr. Provider QX', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientX, $t['tenantA'], $facA2, 'MRN-QX', 'Queue Cross', '1992-02-02', 'male', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptX, $t['tenantA'], $facA2, $patientX, $providerX, 'opd', '2026-03-02 10:00:00', '2026-03-02 10:30:00', 'checked_in', 5, 'counter', 1, $actor]
        );
        $departmentB = (string) Str::uuid();
        $providerB = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$departmentB, $t['tenantB'], $t['facilityB'], 'OPD-B', 'opd-b-'.$suffix, 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$providerB, $t['tenantB'], $t['facilityB'], $departmentB, 'EMP-QB-'.$suffix, 'Dr. Provider QB', 'Doctor', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-QB', 'Queue B', '1985-06-15', 'female', 'active']
        );
        $c->insert(
            'insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, appointment_type, starts_at, ends_at, status, token_no, source, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apptB, $t['tenantB'], $t['facilityB'], $patientB, $providerB, 'opd', '2026-03-02 09:00:00', '2026-03-02 09:30:00', 'checked_in', 6, 'counter', 1, $actor]
        );

        // The exact edge query (coalesce = the null-safe facilityClause).
        $select = 'select a.id, a.token_no, a.status, a.starts_at::text,
                          p.id as patient_id, p.mrn as patient_mrn, p.full_name as patient_full_name,
                          e.id as encounter_id
                     from public.appointments a
                     left join public.patients p
                            on p.id = a.patient_id and p.tenant_id = a.tenant_id
                           and coalesce(?, p.facility_id) = p.facility_id
                     left join public.encounters e
                            on e.appointment_id = a.id and e.tenant_id = a.tenant_id
                           and coalesce(?, e.facility_id) = e.facility_id
                    where a.tenant_id = ? and coalesce(?, a.facility_id) = a.facility_id
                      and date(a.starts_at) = ?
                      and a.status in (\'checked_in\', \'in_consultation\')';

        $claimsFacA1 = [
            'app_user_id' => $actor,
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The exact edge query (fac-a1 claims): ONLY the fac-a1 live
        //    visits, ordered by token_no ascending (NULLS LAST), the patient
        //    ref resolved, the encounter id on the started visit.
        $rows = $c->select($select.' order by a.token_no asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], '2026-03-02',
        ]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$apptQ2, $apptQ5, $apptQ1, $apptQ4])
            ->and((int) $rows[0]->token_no)->toBe(1)
            ->and($rows[0]->status)->toBe('in_consultation')
            ->and($rows[1]->token_no)->toBe(2)
            ->and($rows[1]->encounter_id)->toBe($encounter)
            ->and((int) $rows[2]->token_no)->toBe(3)
            ->and($rows[2]->patient_id)->toBe($patientA)
            ->and($rows[2]->patient_mrn)->toBe('MRN-Q-A')
            ->and($rows[2]->patient_full_name)->toBe('Queue Patient')
            ->and($rows[2]->encounter_id)->toBeNull()
            ->and($rows[3]->token_no)->toBeNull()
            ->and($rows[0]->starts_at)->not->toBeNull();

        // 2. The status filter: the booked row never appears.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($apptQ3);

        // 3. The date filter: a different date returns zero rows.
        expect($c->select($select.' order by a.token_no asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], '2026-03-03',
        ]))->toBe([]);

        // 4. The providerStaffId filter narrows exactly.
        $providerRows = $c->select($select.' and a.provider_staff_id = ? order by a.token_no asc', [
            $t['facilityA'], $t['facilityA'], $t['tenantA'], $t['facilityA'], '2026-03-02', $providerA,
        ]);
        expect(count($providerRows))->toBe(4);

        // 5. The read mutates nothing — statuses/token numbers are untouched.
        expect($c->selectOne('select status from appointments where id = ?', [$apptQ2])->status)->toBe('in_consultation')
            ->and((int) $c->selectOne('select token_no from appointments where id = ?', [$apptQ1])->token_no)->toBe(3);

        // 6. Forged cross-tenant claims: ONLY the tenant's own row is
        //    visible and the tenant-A rows are mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $tenantBRows = $c->select($select.' order by a.token_no asc', [
            $t['facilityB'], $t['facilityB'], $t['tenantB'], $t['facilityB'], '2026-03-02',
        ]);
        expect(array_map(fn ($r) => $r->id, $tenantBRows))->toBe([$apptB])
            ->and($c->update('update appointments set status = ? where id = ?', ['booked', $apptQ2]))->toBe(0)
            ->and($c->update('update appointments set status = ? where id = ?', ['booked', $apptB]))->toBe(1);

        // 7. Forged cross-facility claims within the tenant: the fac-a1 rows
        //    are invisible; only the fac-a2 row resolves.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $facA2Rows = $c->select($select.' order by a.token_no asc', [
            $facA2, $facA2, $t['tenantA'], $facA2, '2026-03-02',
        ]);
        expect(array_map(fn ($r) => $r->id, $facA2Rows))->toBe([$apptX]);

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select.' order by a.token_no asc', [
            null, null, null, null, '2026-03-02',
        ]))->toBe([]);
    });
});
it('patients:identifiers — the claims-scoped identifiers read is RLS-gated, ordered, encrypted-at-rest, and mutation-free (Phase 28)', function () {
    // The exact RLS-scoped read patients:identifiers runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PatientIdentifierController::index + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the patient_identifiers SELECT (the 6 presented fields) is
    //     tenant-scoped (patient_identifiers is TENANT_ONLY) and bound to
    //     the verified patient, ordered by created_at DESC — the exact
    //     `->orderByDesc('created_at')` — with NO status filter (active AND
    //     superseded rows both return);
    //  3. `value_encrypted` holds ciphertext at rest (never the plaintext)
    //     and `value_hash` is the deterministic sha256 of the normalized
    //     value — the Laravel hashValue() semantics;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior and the decrypted-plaintext presentation —
    // the EncryptedString cast boundary — are proven at the harness tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient + three identifiers (distinct
        // types, one superseded — no status filter) seeded out of
        // created_at order (the read must order by created_at DESC); a
        // fac-a2 patient with an identifier (facility scope); an org-b
        // patient with an identifier (tenant scope).
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $idNational = (string) Str::uuid();
        $idPassport = (string) Str::uuid();
        $idLicense = (string) Str::uuid();
        $plaintext = 'NPRN-55667788';
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-ID-A', 'Identifier Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-ID-A2', 'Identifier A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-ID-B', 'Identifier B', '1975-09-09', 'other', 'active']
        );

        // Three identifiers on the fac-a1 patient, seeded out of created_at
        // order; one is superseded (NO status filter); value_encrypted is
        // ciphertext-shaped (never the plaintext); value_hash is the sha256
        // of the normalized plaintext (hashValue() parity).
        $c->insert(
            "insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, issuing_country, is_verified, status, created_at) values (?, ?, ?, 'national_id', ?, ?, 'NP', false, 'active', '2026-03-02 09:00:00')",
            [$idNational, $t['tenantA'], $patient, 'cipher:v1:'.$suffix.'-a', hash('sha256', $plaintext)]
        );
        $c->insert(
            "insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, issuing_country, is_verified, status, created_at) values (?, ?, ?, 'passport', ?, ?, 'NP', true, 'active', '2026-03-02 11:00:00')",
            [$idPassport, $t['tenantA'], $patient, 'cipher:v1:'.$suffix.'-b', hash('sha256', 'P'.$plaintext)]
        );
        $c->insert(
            "insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, issuing_country, is_verified, status, created_at) values (?, ?, ?, 'license', ?, ?, NULL, false, 'superseded', '2026-03-02 10:00:00')",
            [$idLicense, $t['tenantA'], $patient, 'cipher:v1:'.$suffix.'-c', hash('sha256', 'DL'.$plaintext)]
        );
        // Same-tenant fac-a2 patient's own identifier (invisible to fac-a1).
        $c->insert(
            "insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, issuing_country, is_verified, status, created_at) values (?, ?, ?, 'other', ?, ?, NULL, false, 'active', '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantA'], $patientA2, 'cipher:v1:'.$suffix.'-d', hash('sha256', 'OTHER'.$plaintext)]
        );
        // Other-tenant patient's own identifier (invisible to org-a).
        $c->insert(
            "insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, issuing_country, is_verified, status, created_at) values (?, ?, ?, 'other', ?, ?, NULL, false, 'active', '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantB'], $patientB, 'cipher:v1:'.$suffix.'-e', hash('sha256', 'OTHERB'.$plaintext)]
        );

        // The exact edge queries.
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $identifiers = 'select id, type, value_encrypted, issuing_country, is_verified, status
                          from public.patient_identifiers
                         where patient_id = ? and tenant_id = ?
                         order by created_at desc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The identifiers read: the exact fields, ordered by created_at
        //    DESC (seeded out of order), both statuses present (no filter).
        $rows = $c->select($identifiers, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$idPassport, $idLicense, $idNational])
            ->and($rows[0]->type)->toBe('passport')
            ->and($rows[0]->is_verified)->toBe(true)
            ->and($rows[1]->type)->toBe('license')
            ->and($rows[1]->status)->toBe('superseded')
            ->and($rows[1]->issuing_country)->toBeNull()
            ->and($rows[2]->type)->toBe('national_id')
            ->and($rows[2]->status)->toBe('active')
            ->and($rows[2]->issuing_country)->toBe('NP');

        // 3. Ciphertext at rest: value_encrypted never equals the plaintext,
        //    and value_hash is the deterministic sha256 (hashValue() parity).
        $national = $c->selectOne(
            'select value_encrypted, value_hash from patient_identifiers where id = ?',
            [$idNational]
        );
        expect($national->value_encrypted)->not->toBe($plaintext)
            ->and($national->value_hash)->toBe(hash('sha256', $plaintext));

        // 4. The fac-a2 patient's identifier never leaks into the fac-a1
        //    read (bound to the verified patient + tenant).
        expect($rows)->toHaveCount(3);

        // 5. The read mutates nothing — the identifiers are untouched.
        expect($c->selectOne('select status from patient_identifiers where id = ?', [$idNational])->status)->toBe('active');

        // 6. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($identifiers, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update patient_identifiers set status = ? where id = ?', ['superseded', $idNational]))->toBe(0);

        // 7. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($identifiers, [$patient, null]))->toBe([]);
    });
});
it('patients:contacts — the claims-scoped contacts read is RLS-gated, ordered, and mutation-free (Phase 29)', function () {
    // The exact RLS-scoped read patients:contacts runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PatientContactController::index + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the patient_contacts SELECT (the 7 presented fields) is
    //     tenant-scoped (patient_contacts is TENANT_ONLY) and bound to the
    //     verified patient, ordered by is_primary DESC then created_at ASC
    //     — the exact `->orderByDesc('is_primary')->orderBy('created_at')`
    //     — with NO status filter (active AND superseded rows both return);
    //  3. `address` / `contact_person` are the decoded jsonb payloads (the
    //     'array' casts) and `value` is the plain nullable text (the
    //     value/address XOR CHECK holds); the superseded history row stays
    //     visible;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient + four contacts (all four schema
        // types, one superseded — no status filter) seeded out of order (the
        // read must order by is_primary DESC then created_at ASC); a fac-a2
        // patient with a contact (facility scope); an org-b patient with a
        // contact (tenant scope).
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $contactPhone = (string) Str::uuid();
        $contactAddress = (string) Str::uuid();
        $contactEmail = (string) Str::uuid();
        $contactEmergency = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-CT-A', 'Contact Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-CT-A2', 'Contact A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-CT-B', 'Contact B', '1975-09-09', 'other', 'active']
        );

        // Four contacts on the fac-a1 patient, seeded out of order: the
        // primary phone (is_primary DESC dominates), then the non-primary
        // rows by created_at ASC; one superseded (NO status filter). The
        // value/address XOR CHECK holds on every row.
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'email', 'aarav@example.com', NULL, NULL, false, 'active', '2026-03-02 11:00:00')",
            [$contactEmail, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'address', NULL, '{\"street\":\"Durbar Marg\",\"city\":\"Kathmandu\"}'::jsonb, NULL, false, 'superseded', '2026-03-02 10:00:00')",
            [$contactAddress, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'emergency_contact', '+977-9811111111', NULL, '{\"name\":\"Sita Shrestha\",\"relation\":\"spouse\"}'::jsonb, false, 'active', '2026-03-02 12:00:00')",
            [$contactEmergency, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'phone', '+977-9800000000', NULL, NULL, true, 'active', '2026-03-02 09:00:00')",
            [$contactPhone, $t['tenantA'], $patient]
        );
        // Same-tenant fac-a2 patient's own contact (invisible to fac-a1).
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'phone', '+977-9822222222', NULL, NULL, false, 'active', '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantA'], $patientA2]
        );
        // Other-tenant patient's own contact (invisible to org-a).
        $c->insert(
            "insert into patient_contacts (id, tenant_id, patient_id, type, value, address, contact_person, is_primary, status, created_at) values (?, ?, ?, 'phone', '+977-9833333333', NULL, NULL, false, 'active', '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantB'], $patientB]
        );

        // The exact edge queries.
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $contacts = 'select id, type, value, address, contact_person, is_primary, status
                       from public.patient_contacts
                      where patient_id = ? and tenant_id = ?
                      order by is_primary desc, created_at asc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The contacts read: the exact fields, ordered by is_primary DESC
        //    then created_at ASC (seeded out of order), both statuses
        //    present (no filter), the decoded jsonb payloads.
        $rows = $c->select($contacts, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$contactPhone, $contactAddress, $contactEmail, $contactEmergency])
            ->and($rows[0]->type)->toBe('phone')
            ->and($rows[0]->is_primary)->toBe(true)
            ->and($rows[0]->value)->toBe('+977-9800000000')
            ->and($rows[0]->address)->toBeNull()
            ->and($rows[1]->type)->toBe('address')
            ->and($rows[1]->status)->toBe('superseded')
            ->and($rows[1]->value)->toBeNull()
            // jsonb reorders keys (storage normalization) — assert per key.
            ->and(json_decode($rows[1]->address, true)['street'])->toBe('Durbar Marg')
            ->and(json_decode($rows[1]->address, true)['city'])->toBe('Kathmandu')
            ->and($rows[2]->type)->toBe('email')
            ->and($rows[2]->is_primary)->toBe(false)
            ->and($rows[3]->type)->toBe('emergency_contact')
            ->and(json_decode($rows[3]->contact_person, true)['name'])->toBe('Sita Shrestha')
            ->and(json_decode($rows[3]->contact_person, true)['relation'])->toBe('spouse');

        // 3. The fac-a2 patient's contact never leaks into the fac-a1 read
        //    (bound to the verified patient + tenant).
        expect($rows)->toHaveCount(4);

        // 4. The read mutates nothing — the contacts are untouched.
        expect($c->selectOne('select status from patient_contacts where id = ?', [$contactPhone])->status)->toBe('active');

        // 5. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($contacts, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update patient_contacts set status = ? where id = ?', ['superseded', $contactPhone]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($contacts, [$patient, null]))->toBe([]);
    });
});
it('patients:insurance-policies — the claims-scoped policies read is RLS-gated, ordered, payer-joined, and mutation-free (Phase 30)', function () {
    // The exact RLS-scoped read patients:insurance-policies runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // InsurancePolicyController::index + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the insurance_policies SELECT (the 11 presented fields) is
    //     tenant-scoped (insurance_policies is TENANT_ONLY) and bound to
    //     the verified patient, ordered by created_at DESC — the exact
    //     `->orderByDesc('created_at')` — with NO status filter (active,
    //     expired AND cancelled rows all return — status is a lifecycle,
    //     never a deletion);
    //  3. the payer ref LEFT-JOIN resolves under the SAME tenant claim
    //     (payers is TENANT_ONLY — the eager `payer:id,name,code` parity);
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: payer + patient + three policies
        // (three distinct lifecycle statuses — no status filter) seeded out
        // of created_at order (the read must order by created_at DESC); a
        // fac-a2 patient with a policy (facility scope); an org-b payer +
        // patient with a policy (tenant scope).
        $payerA = (string) Str::uuid();
        $payerB = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $policyActive = (string) Str::uuid();
        $policyCancelled = (string) Str::uuid();
        $policyExpired = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into payers (id, tenant_id, name, code) values (?, ?, ?, ?)',
            [$payerA, $t['tenantA'], 'National Insurance', 'NIC']
        );
        $c->insert(
            'insert into payers (id, tenant_id, name, code) values (?, ?, ?, ?)',
            [$payerB, $t['tenantB'], 'Other Tenant Payer', 'OTP']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-PL-A', 'Policy Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-PL-A2', 'Policy A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-PL-B', 'Policy B', '1975-09-09', 'other', 'active']
        );

        // Three policies on the fac-a1 patient (one active only — the
        // uq_policies_tenant_patient_payer partial unique index), seeded out
        // of created_at order; one active / one cancelled (valid_to NULL) /
        // one expired (the NO status filter). policy_number distinct per
        // payer (uq_policies_tenant_payer_number).
        $c->insert(
            "insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, valid_to, benefits, status, lock_version, created_at) values (?, ?, ?, ?, ?, 'general', '2026-01-01', NULL, '{\"coverage\":80,\"maxPerVisit\":5000}'::jsonb, 'cancelled', 1, '2026-03-02 10:00:00')",
            [$policyCancelled, $t['tenantA'], $patient, $payerA, 'POL-A1-0002']
        );
        $c->insert(
            "insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, valid_to, benefits, status, lock_version, created_at) values (?, ?, ?, ?, ?, 'accident', '2025-01-01', '2026-02-01', '{}'::jsonb, 'expired', 0, '2026-03-02 09:00:00')",
            [$policyExpired, $t['tenantA'], $patient, $payerA, 'POL-A1-0003']
        );
        $c->insert(
            "insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, valid_to, benefits, status, lock_version, created_at) values (?, ?, ?, ?, ?, 'general', '2026-01-01', '2027-01-01', '{\"coverage\":80,\"maxPerVisit\":5000}'::jsonb, 'active', 0, '2026-03-02 11:00:00')",
            [$policyActive, $t['tenantA'], $patient, $payerA, 'POL-A1-0001']
        );
        // Same-tenant fac-a2 patient's own policy (invisible to fac-a1).
        $c->insert(
            "insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, valid_to, benefits, status, lock_version, created_at) values (?, ?, ?, ?, ?, 'general', '2026-01-01', NULL, '{}'::jsonb, 'active', 0, '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantA'], $patientA2, $payerA, 'POL-A2-0001']
        );
        // Other-tenant patient's own policy (invisible to org-a).
        $c->insert(
            "insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, valid_to, benefits, status, lock_version, created_at) values (?, ?, ?, ?, ?, 'general', '2026-01-01', NULL, '{}'::jsonb, 'active', 0, '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantB'], $patientB, $payerB, 'POL-B1-0001']
        );

        // The exact edge queries (the payer ref LEFT-JOIN under the same
        // tenant claim — the eager `payer:id,name,code` parity).
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $policies = 'select p.id, p.patient_id, p.payer_id, p.policy_number, p.coverage_type,
                            p.valid_from::text, p.valid_to::text, p.benefits, p.status, p.lock_version,
                            py.id as payer_ref_id, py.name as payer_ref_name, py.code as payer_ref_code
                       from public.insurance_policies p
                       left join public.payers py on py.id = p.payer_id and py.tenant_id = p.tenant_id
                      where p.patient_id = ? and p.tenant_id = ?
                      order by p.created_at desc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The policies read: the exact fields, ordered by created_at DESC
        //    (seeded out of order), all three lifecycle statuses present
        //    (no filter), the payer ref joined, the decoded jsonb benefits.
        $rows = $c->select($policies, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$policyActive, $policyCancelled, $policyExpired])
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[0]->patient_id)->toBe($patient)
            ->and($rows[0]->payer_id)->toBe($payerA)
            ->and($rows[0]->payer_ref_name)->toBe('National Insurance')
            ->and($rows[0]->payer_ref_code)->toBe('NIC')
            ->and($rows[0]->policy_number)->toBe('POL-A1-0001')
            ->and($rows[0]->coverage_type)->toBe('general')
            ->and($rows[0]->valid_from)->toBe('2026-01-01')
            ->and($rows[0]->valid_to)->toBe('2027-01-01')
            ->and($rows[0]->lock_version)->toBe(0)
            ->and($rows[1]->status)->toBe('cancelled')
            ->and($rows[1]->valid_to)->toBeNull()
            ->and($rows[1]->lock_version)->toBe(1)
            ->and($rows[2]->status)->toBe('expired')
            ->and($rows[2]->coverage_type)->toBe('accident')
            ->and($rows[2]->valid_to)->toBe('2026-02-01');
        // jsonb reorders keys (storage normalization) — assert per key.
        expect(json_decode($rows[0]->benefits, true)['coverage'])->toBe(80)
            ->and(json_decode($rows[0]->benefits, true)['maxPerVisit'])->toBe(5000);

        // 3. The fac-a2 patient's policy never leaks into the fac-a1 read
        //    (bound to the verified patient + tenant).
        expect($rows)->toHaveCount(3);

        // 4. The read mutates nothing — the policies are untouched.
        expect($c->selectOne('select status from insurance_policies where id = ?', [$policyActive])->status)->toBe('active');

        // 5. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($policies, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update insurance_policies set status = ? where id = ?', ['cancelled', $policyActive]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($policies, [$patient, null]))->toBe([]);
    });
});
it('patients:consents — the claims-scoped consents read is RLS-gated, version-ordered, and mutation-free (Phase 31)', function () {
    // The exact RLS-scoped read patients:consents runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // ConsentController::index + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the consents SELECT (the 9 presented fields) is tenant-scoped
    //     (consents is TENANT_ONLY) and bound to the verified patient,
    //     ordered by version DESC — the exact `->orderByDesc('version')` —
    //     with NO status filter (active, expired AND revoked rows all
    //     return; the versioned lifecycle — history outlives the consent);
    //  3. `scope` is the decoded jsonb payload (the 'array' cast);
    //     `given_at` is NOT NULL; `revoked_at`/`revocation_reason` are
    //     nullable; `patient_id` is a contract-explicit presented field;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient + three consents (distinct
        // types + distinct versions + the three lifecycle statuses — no
        // status filter) seeded out of version order (the read must order
        // by version DESC); a fac-a2 patient with a consent (facility
        // scope); an org-b patient with a consent (tenant scope).
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $consentTreatment = (string) Str::uuid();
        $consentDataUse = (string) Str::uuid();
        $consentTelehealth = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-CN-A', 'Consent Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-CN-A2', 'Consent A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-CN-B', 'Consent B', '1975-09-09', 'other', 'active']
        );

        // Three consents on the fac-a1 patient (one active only — the
        // uq_consents_tenant_patient_type partial unique index; distinct
        // versions for a deterministic version DESC order). The revoked row
        // carries revoked_at + revocation_reason; the others render NULL.
        $c->insert(
            "insert into consents (id, tenant_id, patient_id, consent_type, version, status, scope, given_at, revoked_at, revocation_reason) values (?, ?, ?, 'data_use', 2, 'expired', '{\"care\":true}'::jsonb, '2026-03-02 10:00:00', NULL, NULL)",
            [$consentDataUse, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into consents (id, tenant_id, patient_id, consent_type, version, status, scope, given_at, revoked_at, revocation_reason) values (?, ?, ?, 'telehealth', 1, 'revoked', '{\"telehealth\":true}'::jsonb, '2026-03-02 09:00:00', '2026-02-01 10:00:00', 'Patient request')",
            [$consentTelehealth, $t['tenantA'], $patient]
        );
        $c->insert(
            "insert into consents (id, tenant_id, patient_id, consent_type, version, status, scope, given_at, revoked_at, revocation_reason) values (?, ?, ?, 'treatment', 3, 'active', '{\"treatment\":true,\"sharing\":\"clinic\"}'::jsonb, '2026-03-02 11:00:00', NULL, NULL)",
            [$consentTreatment, $t['tenantA'], $patient]
        );
        // Same-tenant fac-a2 patient's own consent (invisible to fac-a1).
        $c->insert(
            "insert into consents (id, tenant_id, patient_id, consent_type, version, status, scope, given_at, revoked_at, revocation_reason) values (?, ?, ?, 'treatment', 1, 'active', '{}'::jsonb, '2026-03-02 09:30:00', NULL, NULL)",
            [(string) Str::uuid(), $t['tenantA'], $patientA2]
        );
        // Other-tenant patient's own consent (invisible to org-a).
        $c->insert(
            "insert into consents (id, tenant_id, patient_id, consent_type, version, status, scope, given_at, revoked_at, revocation_reason) values (?, ?, ?, 'treatment', 1, 'active', '{}'::jsonb, '2026-03-02 09:30:00', NULL, NULL)",
            [(string) Str::uuid(), $t['tenantB'], $patientB]
        );

        // The exact edge queries.
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $consents = 'select id, patient_id, consent_type, version, status, scope,
                            given_at::text, revoked_at::text, revocation_reason
                       from public.consents
                      where patient_id = ? and tenant_id = ?
                      order by version desc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The consents read: the exact fields, ordered by version DESC
        //    (seeded out of order), all three lifecycle statuses present
        //    (no filter), the decoded jsonb scope.
        $rows = $c->select($consents, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$consentTreatment, $consentDataUse, $consentTelehealth])
            ->and($rows[0]->consent_type)->toBe('treatment')
            ->and($rows[0]->version)->toBe(3)
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[0]->patient_id)->toBe($patient)
            ->and($rows[0]->given_at)->toBe('2026-03-02 11:00:00+00')
            ->and($rows[0]->revoked_at)->toBeNull()
            ->and($rows[0]->revocation_reason)->toBeNull()
            ->and($rows[1]->consent_type)->toBe('data_use')
            ->and($rows[1]->version)->toBe(2)
            ->and($rows[1]->status)->toBe('expired')
            ->and($rows[2]->consent_type)->toBe('telehealth')
            ->and($rows[2]->version)->toBe(1)
            ->and($rows[2]->status)->toBe('revoked')
            ->and($rows[2]->revoked_at)->toBe('2026-02-01 10:00:00+00')
            ->and($rows[2]->revocation_reason)->toBe('Patient request');
        // jsonb reorders keys (storage normalization) — assert per key.
        expect(json_decode($rows[0]->scope, true)['treatment'])->toBe(true)
            ->and(json_decode($rows[0]->scope, true)['sharing'])->toBe('clinic');

        // 3. The fac-a2 patient's consent never leaks into the fac-a1 read
        //    (bound to the verified patient + tenant).
        expect($rows)->toHaveCount(3);

        // 4. The read mutates nothing — the consents are untouched.
        expect($c->selectOne('select status from consents where id = ?', [$consentTreatment])->status)->toBe('active');

        // 5. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($consents, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update consents set status = ? where id = ?', ['expired', $consentTreatment]))->toBe(0);

        // 6. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($consents, [$patient, null]))->toBe([]);
    });
});
it('patients:documents — the claims-scoped documents read is RLS-gated, ordered, pointer-free, and mutation-free (Phase 32)', function () {
    // The exact RLS-scoped read patients:documents runs is proven on the
    // REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PatientDocumentController::index + AccessCheck::scoped:
    //  1. the patient gate SELECT (the 404 decision) is visible ONLY under
    //     matching tenant + facility claims (patients is TENANT_FACILITY);
    //  2. the patient_documents SELECT (the 10 presented fields) is
    //     tenant-scoped (patient_documents is TENANT_ONLY) and bound to the
    //     verified patient, ordered by created_at DESC — the exact
    //     `->orderByDesc('created_at')` — with NO status filter (staged,
    //     available, archived AND purged rows all return — the lifecycle
    //     statuses; no object storage yet, records are honestly `staged`);
    //  3. the storage pointer `object_key` is stored in the table but the
    //     read projection NEVER selects it (the Laravel contract does not
    //     present it) — no crypto boundary, the pointer never crosses;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The zero-audit behavior is proven at the harness tier — the handler
    // tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A fixture graph: patient + four documents (distinct
        // types + the four lifecycle statuses — no status filter) seeded
        // out of created_at order (the read must order by created_at DESC);
        // a fac-a2 patient with a document (facility scope); an org-b
        // patient with a document (tenant scope). One fixture carries a
        // real `object_key` value — the read must never project it.
        $patient = (string) Str::uuid();
        $patientA2 = (string) Str::uuid();
        $patientB = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $docReport = (string) Str::uuid();
        $docConsent = (string) Str::uuid();
        $docId = (string) Str::uuid();
        $docDischarge = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-DC-A', 'Document Patient', '1990-01-01', 'female', 'active']
        );
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-faca2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA2, $t['tenantA'], $facA2, 'MRN-DC-A2', 'Document A2', '1980-05-05', 'male', 'active']
        );
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientB, $t['tenantB'], $t['facilityB'], 'MRN-DC-B', 'Document B', '1975-09-09', 'other', 'active']
        );

        // Four documents on the fac-a1 patient, seeded out of created_at
        // order; all four lifecycle statuses (NO status filter). The
        // discharge row is fully-nullable metadata. The consent row carries
        // a real object_key pointer that the read must never project.
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'consent', ?, ?, 204800, 'application/pdf', 'available', '2026-03-02 10:30:00', '2027-06-01 00:00:00', 'legal', '2026-03-02 10:30:00')",
            [$docConsent, $t['tenantA'], $t['facilityA'], $patient, 'patients/'.$patient.'/'.$docConsent, 'c3d4e5f6a7b8'.str_repeat('0', 52)]
        );
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'id', NULL, NULL, NULL, NULL, 'archived', '2026-03-02 10:00:00', NULL, NULL, '2026-03-02 10:00:00')",
            [$docId, $t['tenantA'], $t['facilityA'], $patient]
        );
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'discharge', NULL, NULL, NULL, NULL, 'purged', '2026-03-02 09:00:00', NULL, NULL, '2026-03-02 09:00:00')",
            [$docDischarge, $t['tenantA'], $t['facilityA'], $patient]
        );
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'report', NULL, ?, 1048576, 'application/pdf', 'staged', '2026-03-02 11:00:00', NULL, 'clinical', '2026-03-02 11:00:00')",
            [$docReport, $t['tenantA'], $t['facilityA'], $patient, 'a1b2c3d4e5f6'.str_repeat('0', 52)]
        );
        // Same-tenant fac-a2 patient's own document (invisible to fac-a1).
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'other', NULL, NULL, NULL, NULL, 'staged', '2026-03-02 09:30:00', NULL, NULL, '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantA'], $facA2, $patientA2]
        );
        // Other-tenant patient's own document (invisible to org-a).
        $c->insert(
            "insert into patient_documents (id, tenant_id, facility_id, patient_id, document_type, object_key, checksum, size_bytes, mime_type, status, uploaded_at, expires_at, retention_class, created_at) values (?, ?, ?, ?, 'other', NULL, NULL, NULL, NULL, 'staged', '2026-03-02 09:30:00', NULL, NULL, '2026-03-02 09:30:00')",
            [(string) Str::uuid(), $t['tenantB'], $t['facilityB'], $patientB]
        );

        // The exact edge queries.
        $gate = 'select id from public.patients where id = ? and tenant_id = ? and facility_id = ? limit 1';
        $documents = 'select id, patient_id, document_type, mime_type, size_bytes, checksum, status,
                            uploaded_at::text, expires_at::text, retention_class
                       from public.patient_documents
                      where patient_id = ? and tenant_id = ?
                      order by created_at desc';

        $claimsA = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsA, $c);

        // 1. The patient gate: visible under the authoritative claims.
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $t['facilityA']]))->not->toBeNull();

        // 2. The documents read: the exact fields, ordered by created_at
        //    DESC (seeded out of order), all four lifecycle statuses present
        //    (no filter).
        $rows = $c->select($documents, [$patient, $t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$docReport, $docConsent, $docId, $docDischarge])
            ->and($rows[0]->document_type)->toBe('report')
            ->and($rows[0]->status)->toBe('staged')
            ->and($rows[0]->patient_id)->toBe($patient)
            ->and($rows[0]->mime_type)->toBe('application/pdf')
            ->and($rows[0]->size_bytes)->toBe(1048576)
            ->and($rows[0]->checksum)->toBe('a1b2c3d4e5f6'.str_repeat('0', 52))
            ->and($rows[0]->uploaded_at)->toBe('2026-03-02 11:00:00+00')
            ->and($rows[0]->expires_at)->toBeNull()
            ->and($rows[0]->retention_class)->toBe('clinical')
            ->and($rows[1]->status)->toBe('available')
            ->and($rows[1]->expires_at)->toBe('2027-06-01 00:00:00+00')
            ->and($rows[1]->retention_class)->toBe('legal')
            ->and($rows[2]->status)->toBe('archived')
            ->and($rows[2]->mime_type)->toBeNull()
            ->and($rows[3]->status)->toBe('purged')
            ->and($rows[3]->mime_type)->toBeNull()
            ->and($rows[3]->size_bytes)->toBeNull()
            ->and($rows[3]->checksum)->toBeNull()
            ->and($rows[3]->expires_at)->toBeNull()
            ->and($rows[3]->retention_class)->toBeNull();

        // 3. The storage pointer NEVER crosses: object_key exists in the
        //    table (the consent row) but the read projection never exposes
        //    it — no crypto boundary, no pointer leak.
        expect(property_exists($rows[0], 'object_key'))->toBeFalse()
            ->and(property_exists($rows[1], 'object_key'))->toBeFalse();

        // 4. The fac-a2 patient's document never leaks into the fac-a1 read
        //    (bound to the verified patient + tenant).
        expect($rows)->toHaveCount(4);

        // 5. The read mutates nothing — the documents are untouched.
        expect($c->selectOne('select status from patient_documents where id = ?', [$docReport])->status)->toBe('staged');

        // 6. Forged cross-tenant claims: the gate and the read expose zero
        //    rows and mutations match zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantB'], $t['facilityB']]))->toBeNull()
            ->and($c->select($documents, [$patient, $t['tenantB']]))->toBe([])
            ->and($c->update('update patient_documents set status = ? where id = ?', ['archived', $docReport]))->toBe(0);

        // 7. Forged cross-facility claims within the tenant: the gate hides
        //    the patient (404 semantics).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne($gate, [$patient, $t['tenantA'], $facA2]))->toBeNull();

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($documents, [$patient, null]))->toBe([]);
    });
});
it('organizations:departments — the claims-scoped department read is RLS-gated, branch-scoped, ordered, and mutation-free (Phase 33)', function () {
    // The exact RLS-scoped departments read organizations:departments runs
    // is proven on the REAL app-role connection (swasthya_app,
    // NOBYPASSRLS), mirroring DepartmentController::index +
    // AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the departments SELECT is visible ONLY under matching claims —
    //     departments is TENANT_FACILITY_BRANCH: `tenant_id = TENANT AND
    //     (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
    //     NULL OR branch_id = BRANCH OR BRANCH IS NULL)` — an org-level
    //     claim (facility/branch NULL) sees every facility of the tenant
    //     (the `! isPlatform && facilityId() !== null` parity: no facility
    //     filter), a facility claim narrows to that facility, and a branch
    //     claim narrows to that branch + branch-less rows;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; the exact
    //     7-column projection is the present() map — tenant_id/created_at/
    //     updated_by/etc. never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph: brA1/brA1b of facA (the
        // wrong-branch proof), brA2 of facA2 (facility proof), brB of facB
        // (tenant proof). Departments seeded OUT of name order: Cardiology
        // + Emergency (brA1), Laboratory (branch-less, INACTIVE — the
        // no-status-filter + nullable-branch/parent proof), Radiology
        // (brA1b — the wrong-branch-invisible proof), Surgery (facA2 —
        // facility proof), Oncology (facB — tenant proof). Codes unique per
        // (tenant, facility) — uq_departments_tenant_facility_code.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA1b = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-dep-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1', 'br-a1-'.$suffix],
            [$brA1b, $t['tenantA'], $t['facilityA'], 'Branch A1b', 'br-a1b-'.$suffix],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2', 'br-a2-'.$suffix],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B', 'br-b-'.$suffix],
        ] as $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], $branch[4], 'active']
            );
        }

        $dCard = (string) Str::uuid();
        $dEr = (string) Str::uuid();
        $dLab = (string) Str::uuid();
        $dRad = (string) Str::uuid();
        $dSur = (string) Str::uuid();
        $dBOnc = (string) Str::uuid();

        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dCard, $t['tenantA'], $t['facilityA'], $brA1, 'Cardiology', 'dep-card', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dEr, $t['tenantA'], $t['facilityA'], $brA1, 'Emergency', 'dep-er', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status, parent_department_id) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$dLab, $t['tenantA'], $t['facilityA'], null, 'Laboratory', 'dep-lab', 'inactive', $dCard]
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dRad, $t['tenantA'], $t['facilityA'], $brA1b, 'Radiology', 'dep-rad', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dSur, $t['tenantA'], $facA2, $brA2, 'Surgery', 'dep-sur', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dBOnc, $t['tenantB'], $t['facilityB'], $brB, 'Oncology', 'dep-onc', 'active']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the departments SELECT (the facility clause applied ONLY when
        // the caller has a facility claim — org-level claims see every
        // facility of the tenant; the branch constraint comes from RLS).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = 'select id, facility_id, branch_id, name, code, status, parent_department_id
                     from public.departments
                    where tenant_id = ? and deleted_at is null';
        $selectFacility = $select.' and facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 + br-a1 claims: exactly the br-a1 rows + the branch-less
        //    row, ordered by name ASC; the exact 7-column projection; the
        //    inactive Laboratory row IS present (no status filter); the
        //    nullable branch + parent contract.
        $rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$dCard, $dEr, $dLab])
            ->and($rows[0]->name)->toBe('Cardiology')
            ->and($rows[0]->branch_id)->toBe($brA1)
            ->and($rows[0]->parent_department_id)->toBeNull()
            ->and($rows[2]->name)->toBe('Laboratory')
            ->and($rows[2]->status)->toBe('inactive')
            ->and($rows[2]->branch_id)->toBeNull()
            ->and($rows[2]->parent_department_id)->toBe($dCard)
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The wrong-branch Radiology row (br-a1b) is invisible to br-a1
        //    claims; the fac-a2 Surgery and org-b Oncology rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($dRad)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($dSur)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($dBOnc);

        // 4. A br-a1b claim (same facility) sees exactly br-a1b + branch-less
        //    rows — the wrong-branch isolation is two-sided.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA1b]), $c);
        $brRows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$dLab, $dRad]);

        // 5. Org-level claims (facility/branch NULL): every facility of the
        //    tenant — the `! isPlatform && facilityId() !== null` parity
        //    (the Laravel controller applies NO facility filter for the
        //    org-level caller; the RLS facility/branch clauses pass with
        //    NULL claims). Only the org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$dCard, $dEr, $dLab, $dRad, $dSur])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($dBOnc);

        // 6. fac-a2 claims: exactly the Surgery row.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$dSur]);

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$dBOnc])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update departments set status = ? where id = ?', ['inactive', $dCard]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix.
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from departments where id = ?', [$dCard])->status)->toBe('active')
            ->and($c->selectOne('select status from departments where id = ?', [$dLab])->status)->toBe('inactive');
    });
});
it('facilities:branches — the claims-scoped branch read is RLS-gated, facility-bound, ordered, and mutation-free (Phase 34)', function () {
    // The exact RLS-scoped branches read facilities:branches runs is proven
    // on the REAL app-role connection (swasthya_app, NOBYPASSRLS),
    // mirroring BranchController::index + AccessCheck::facility:
    //  1. the facility gate resolves the facility by id; the SCOPE decision
    //     (nonexistent vs out-of-scope) is the app layer, proven at the
    //     harness tier;
    //  2. the branches SELECT is visible ONLY under the tenant claim
    //     (branches is TENANT_ONLY — select policy `tenant_id = TENANT`)
    //     AND bound to the VERIFIED facility id — the facility scoping is
    //     the QUERY (the exact `->where('facility_id', $facility->getKey())`),
    //     not RLS;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; the exact
    //     Laravel index projection selects ONLY id/name/code/status —
    //     `facility_id` is never hydrated and the present() `facilityId`
    //     renders null (the literal Laravel index output), and
    //     tenant_id/created_at/etc. never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 (same tenant) + the branch graph: three fac-a1
        // branches (seeded OUT of name order; one INACTIVE — the
        // no-status-filter proof), a fac-a2 branch (facility-bound proof),
        // and an org-b branch (tenant proof). Codes unique per (tenant,
        // facility) — uq_branches_tenant_facility_code.
        $facA2 = (string) Str::uuid();
        $brCard = (string) Str::uuid();
        $brEr = (string) Str::uuid();
        $brLab = (string) Str::uuid();
        $brSur = (string) Str::uuid();
        $brOnc = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-br-a2', 'active', 'UTC', '{}', '{}']
        );
        $c->insert(
            'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$brEr, $t['tenantA'], $t['facilityA'], 'Emergency Wing', 'br-er', 'active']
        );
        $c->insert(
            'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$brLab, $t['tenantA'], $t['facilityA'], 'Lab Services', 'br-lab', 'inactive']
        );
        $c->insert(
            'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$brCard, $t['tenantA'], $t['facilityA'], 'Cardiology Clinic', 'br-card', 'active']
        );
        $c->insert(
            'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$brSur, $t['tenantA'], $facA2, 'Surgery Annex', 'br-sur', 'active']
        );
        $c->insert(
            'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
            [$brOnc, $t['tenantB'], $t['facilityB'], 'Oncology Unit', 'br-onc', 'active']
        );

        // The exact edge queries: the facility gate (id is a resource
        // selector) and the branches SELECT — facility-bound, the exact
        // Laravel 4-column index projection, name ASC.
        $gate = 'select id, tenant_id from public.facilities where id = ? limit 1';
        $select = 'select id, name, code, status
                     from public.branches
                    where tenant_id = ? and facility_id = ? and deleted_at is null';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The facility gate resolves the selector (the facility exists).
        expect($c->selectOne($gate, [$t['facilityA']]))->not->toBeNull();

        // 2. The facility-bound read: exactly the fac-a1 branches, ordered
        //    by name ASC; the inactive Lab Services row IS present (no
        //    status filter); the exact 4-column projection — the read never
        //    hydrates facility_id (facilityId renders null in the
        //    present() map) nor tenant/audit metadata.
        $rows = $c->select($select.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$brCard, $brEr, $brLab])
            ->and($rows[0]->name)->toBe('Cardiology Clinic')
            ->and($rows[0]->code)->toBe('br-card')
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[2]->name)->toBe('Lab Services')
            ->and($rows[2]->status)->toBe('inactive')
            ->and(property_exists($rows[0], 'facility_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The fac-a2 and org-b branches never leak into the fac-a1 read.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($brSur)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($brOnc);

        // 4. Org-level claims (facility NULL — the same tenant): the same
        //    facility-bound read works (AccessCheck::facility lets the
        //    org-level principal read any in-tenant facility).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($select.' order by name asc', [$t['tenantA'], $t['facilityA']])))->toBe([$brCard, $brEr, $brLab]);

        // 5. fac-a2 claims: exactly the fac-a2 branch.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($select.' order by name asc', [$t['tenantA'], $facA2])))->toBe([$brSur]);

        // 6. Other-tenant claims: only the org-b branch — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($select.' order by name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$brOnc])
            ->and($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([])
            ->and($c->update('update branches set status = ? where id = ?', ['inactive', $brCard]))->toBe(0);

        // 7. Forged cross-tenant claims: zero rows.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([]);

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([]);

        // 9. The read mutates nothing — every row is untouched after the
        //    full claims matrix.
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from branches where id = ?', [$brCard])->status)->toBe('active')
            ->and($c->selectOne('select status from branches where id = ?', [$brLab])->status)->toBe('inactive');
    });
});
it('organizations:locations — the claims-scoped location read is RLS-gated, branch-scoped, ordered, and mutation-free (Phase 35)', function () {
    // The exact RLS-scoped locations read organizations:locations runs is
    // proven on the REAL app-role connection (swasthya_app, NOBYPASSRLS),
    // mirroring LocationController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the locations SELECT is visible ONLY under matching claims —
    //     locations is TENANT_FACILITY_BRANCH: `tenant_id = TENANT AND
    //     (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS
    //     NULL OR branch_id = BRANCH OR BRANCH IS NULL)` — an org-level
    //     claim (facility/branch NULL) sees every facility of the tenant
    //     (the `! isPlatform && facilityId() !== null` parity: no facility
    //     filter), a facility claim narrows to that facility, and a branch
    //     claim narrows to that branch + branch-less rows;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; the exact
    //     7-column projection is the present() map — facility_id/branch_id
    //     ARE hydrated (real values) while tenant_id/created_at/updated_by
    //     never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (locations.branch_id carries the
        // composite FK to branches): brA1/brA1b of facA (the wrong-branch
        // proof), brA2 of facA2 (facility proof), brB of facB (tenant
        // proof). Locations seeded OUT of name order: Central Store +
        // Reception Waiting (brA1), Nursing Station (branch-less, INACTIVE
        // — the no-status-filter + nullable-branch proof), Annex Storage
        // (brA1b — the wrong-branch-invisible proof), Procedure Suite
        // (facA2 — facility proof), Storage B (facB — tenant proof). Codes
        // unique per (tenant, facility) — uq_locations_tenant_facility_code.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA1b = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-loc-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA1b, $t['tenantA'], $t['facilityA'], 'Branch A1b'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'loc-br-'.$i, 'active']
            );
        }

        $locStore = (string) Str::uuid();
        $locWait = (string) Str::uuid();
        $locNurse = (string) Str::uuid();
        $locAnnex = (string) Str::uuid();
        $locProc = (string) Str::uuid();
        $locB = (string) Str::uuid();

        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'store', 'active')",
            [$locStore, $t['tenantA'], $t['facilityA'], $brA1, 'Central Store', 'loc-store']
        );
        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'waiting_area', 'active')",
            [$locWait, $t['tenantA'], $t['facilityA'], $brA1, 'Reception Waiting', 'loc-wait']
        );
        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'nursing_station', 'inactive')",
            [$locNurse, $t['tenantA'], $t['facilityA'], null, 'Nursing Station', 'loc-nurse']
        );
        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'store', 'active')",
            [$locAnnex, $t['tenantA'], $t['facilityA'], $brA1b, 'Annex Storage', 'loc-annex']
        );
        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'procedure_area', 'active')",
            [$locProc, $t['tenantA'], $facA2, $brA2, 'Procedure Suite', 'loc-proc']
        );
        $c->insert(
            "insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, 'store', 'active')",
            [$locB, $t['tenantB'], $t['facilityB'], $brB, 'Storage B', 'loc-b']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the locations SELECT (the facility clause applied ONLY when
        // the caller has a facility claim — org-level claims see every
        // facility of the tenant; the branch constraint comes from RLS).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = 'select id, facility_id, branch_id, name, code, type, status
                     from public.locations
                    where tenant_id = ? and deleted_at is null';
        $selectFacility = $select.' and facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 + br-a1 claims: exactly the br-a1 rows + the
        //    branch-less row, ordered by name ASC; the exact 7-column
        //    projection with the HYDRATED facility/branch ids (real values);
        //    the inactive Nursing Station row IS present (no status filter);
        //    the nullable branch contract.
        $rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$locStore, $locNurse, $locWait])
            ->and($rows[0]->name)->toBe('Central Store')
            ->and($rows[0]->type)->toBe('store')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->branch_id)->toBe($brA1)
            ->and($rows[1]->name)->toBe('Nursing Station')
            ->and($rows[1]->status)->toBe('inactive')
            ->and($rows[1]->branch_id)->toBeNull()
            ->and($rows[2]->name)->toBe('Reception Waiting')
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The wrong-branch Annex Storage (br-a1b) row is invisible to
        //    br-a1 claims; the fac-a2 Procedure Suite and org-b Storage B
        //    rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($locAnnex)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($locProc)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($locB);

        // 4. A br-a1b claim (same facility) sees exactly br-a1b + branch-less
        //    rows — the wrong-branch isolation is two-sided.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA1b]), $c);
        $brRows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$locAnnex, $locNurse]);

        // 5. Org-level claims (facility/branch NULL): every facility of the
        //    tenant — the `! isPlatform && facilityId() !== null` parity.
        //    Only the org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$locAnnex, $locStore, $locNurse, $locProc, $locWait])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($locB);

        // 6. fac-a2 claims: exactly the Procedure Suite row.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$locProc]);

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$locB])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update locations set status = ? where id = ?', ['inactive', $locStore]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix.
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from locations where id = ?', [$locStore])->status)->toBe('active')
            ->and($c->selectOne('select status from locations where id = ?', [$locNurse])->status)->toBe('inactive');
    });
});

it('organizations:wards — the claims-scoped ward read is RLS-gated, branch-scoped, ordered, and mutation-free (Phase 36)', function () {
    // The exact RLS-scoped wards read organizations:wards runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // WardController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the wards SELECT is visible ONLY under matching claims — wards is
    //     TENANT_FACILITY_BRANCH: `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id
    //     = BRANCH OR BRANCH IS NULL)` — an org-level claim (facility/branch
    //     NULL) sees every facility of the tenant (the `! isPlatform &&
    //     facilityId() !== null` parity: no facility filter), a facility
    //     claim narrows to that facility, and a branch claim narrows to that
    //     branch + branch-less rows;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; the exact
    //     7-column projection is the present() map — facility_id/branch_id
    //     ARE hydrated (real values) while tenant_id/created_at/updated_by/
    //     settings never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (wards.branch_id carries the
        // composite FK to branches): brA1/brA1b of facA (the wrong-branch
        // proof), brA2 of facA2 (facility proof), brB of facB (tenant
        // proof). Wards seeded OUT of name order: General Ward + Intensive
        // Care (brA1), Pediatric (branch-less, INACTIVE — the
        // no-status-filter + nullable-branch proof), Maternity Wing A
        // (brA1b — the wrong-branch-invisible proof), Surgery (facA2 —
        // facility proof), Oncology Suite (facB — tenant proof). Codes
        // unique per (tenant, facility) — uq_wards_tenant_facility_code.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA1b = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-ward-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA1b, $t['tenantA'], $t['facilityA'], 'Branch A1b'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'ward-br-'.$i, 'active']
            );
        }

        $wardGen = (string) Str::uuid();
        $wardIcu = (string) Str::uuid();
        $wardPed = (string) Str::uuid();
        $wardMat = (string) Str::uuid();
        $wardSur = (string) Str::uuid();
        $wardB = (string) Str::uuid();

        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'general', 'active')",
            [$wardGen, $t['tenantA'], $t['facilityA'], $brA1, 'General Ward', 'ward-gen']
        );
        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'icu', 'active')",
            [$wardIcu, $t['tenantA'], $t['facilityA'], $brA1, 'Intensive Care', 'ward-icu']
        );
        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'pediatric', 'inactive')",
            [$wardPed, $t['tenantA'], $t['facilityA'], null, 'Pediatric', 'ward-ped']
        );
        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'maternity', 'active')",
            [$wardMat, $t['tenantA'], $t['facilityA'], $brA1b, 'Maternity Wing A', 'ward-mat']
        );
        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'surgery', 'active')",
            [$wardSur, $t['tenantA'], $facA2, $brA2, 'Surgery', 'ward-sur']
        );
        $c->insert(
            "insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, 'other', 'active')",
            [$wardB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology Suite', 'ward-b']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the wards SELECT (the facility clause applied ONLY when the
        // caller has a facility claim — org-level claims see every facility
        // of the tenant; the branch constraint comes from RLS).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = 'select id, facility_id, branch_id, name, code, ward_type, status
                     from public.wards
                    where tenant_id = ? and deleted_at is null';
        $selectFacility = $select.' and facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 + br-a1 claims: exactly the br-a1 rows + the
        //    branch-less row, ordered by name ASC; the exact 7-column
        //    projection with the HYDRATED facility/branch ids (real values);
        //    the inactive Pediatric row IS present (no status filter); the
        //    nullable branch contract.
        $rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$wardGen, $wardIcu, $wardPed])
            ->and($rows[0]->name)->toBe('General Ward')
            ->and($rows[0]->ward_type)->toBe('general')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->branch_id)->toBe($brA1)
            ->and($rows[1]->name)->toBe('Intensive Care')
            ->and($rows[2]->name)->toBe('Pediatric')
            ->and($rows[2]->status)->toBe('inactive')
            ->and($rows[2]->branch_id)->toBeNull()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'settings'))->toBeFalse();

        // 3. The wrong-branch Maternity Wing A (br-a1b) row is invisible to
        //    br-a1 claims; the fac-a2 Surgery and org-b Oncology Suite rows
        //    too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($wardMat)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($wardSur)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($wardB);

        // 4. A br-a1b claim (same facility) sees exactly br-a1b + branch-less
        //    rows — the wrong-branch isolation is two-sided.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA1b]), $c);
        $brRows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$wardMat, $wardPed]);

        // 5. Org-level claims (facility/branch NULL): every facility of the
        //    tenant — the `! isPlatform && facilityId() !== null` parity.
        //    Only the org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$wardGen, $wardIcu, $wardMat, $wardPed, $wardSur])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($wardB);

        // 6. fac-a2 claims: exactly the Surgery row.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$wardSur]);

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$wardB])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update wards set status = ? where id = ?', ['inactive', $wardGen]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix.
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from wards where id = ?', [$wardGen])->status)->toBe('active')
            ->and($c->selectOne('select status from wards where id = ?', [$wardPed])->status)->toBe('inactive');
    });
});
it('organizations:rooms — the claims-scoped room read is RLS-gated, ward-ref-scoped, ordered, and mutation-free (Phase 37)', function () {
    // The exact RLS-scoped rooms read organizations:rooms runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // RoomController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the rooms SELECT is visible ONLY under matching claims — rooms is
    //     TENANT_FACILITY_BRANCH: `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id
    //     = BRANCH OR BRANCH IS NULL)` — an org-level claim (facility/branch
    //     NULL) sees every facility of the tenant (the `! isPlatform &&
    //     facilityId() !== null` parity: no facility filter), a facility
    //     claim narrows to that facility, and a branch claim narrows to that
    //     branch + branch-less rows;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; the exact
    //     projection is the present() map — facility_id/ward_id are NOT
    //     NULL and HYDRATED (real values), branch_id nullable (tenancy_v2),
    //     daily_rate_minor/currency nullable; the ward ref is eager-loaded
    //     (the exact `with('ward:id,code,name')`) carrying exactly
    //     id/code/name while tenant_id/created_at/updated_by never leave
    //     the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (rooms.branch_id carries the
        // composite FK to branches): brA1/brA1b of facA (the wrong-branch
        // proof), brA2 of facA2 (facility proof), brB of facB (tenant
        // proof). Wards seeded for the rooms' composite FK (tenant,
        // facility, ward_id) → wards (tenant_id, facility_id, id). Rooms
        // seeded OUT of name order: General Room + Private Suite (brA1,
        // ward W1), Semi-Private (branch-less, INACTIVE — the
        // no-status-filter + nullable-branch proof), Maternity Room
        // (brA1b — the wrong-branch-invisible proof), ICU Bay (facA2 —
        // facility proof), Standard Ward Room (facB — tenant proof).
        // Codes unique per (tenant, facility) — uq_rooms_tenant_facility_code.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA1b = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $w1 = (string) Str::uuid();
        $w2 = (string) Str::uuid();
        $wB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-room-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA1b, $t['tenantA'], $t['facilityA'], 'Branch A1b'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'room-br-'.$i, 'active']
            );
        }
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$w1, $t['tenantA'], $t['facilityA'], $brA1, 'General Ward', 'room-w1', 'general', 'active']
        );
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$w2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'room-w2', 'surgery', 'active']
        );
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$wB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology Suite', 'room-wb', 'other', 'active']
        );

        $rGen = (string) Str::uuid();
        $rPriv = (string) Str::uuid();
        $rSemi = (string) Str::uuid();
        $rMat = (string) Str::uuid();
        $rIcu = (string) Str::uuid();
        $rB = (string) Str::uuid();

        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'general', 1000, 'NPR', 'active')",
            [$rGen, $t['tenantA'], $t['facilityA'], $brA1, $w1, 'General Room', 'room-gen']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'private', 5000, 'NPR', 'active')",
            [$rPriv, $t['tenantA'], $t['facilityA'], $brA1, $w1, 'Private Suite', 'room-priv']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'semi_private', 2500, 'NPR', 'inactive')",
            [$rSemi, $t['tenantA'], $t['facilityA'], null, $w1, 'Semi-Private', 'room-semi']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'other', 3000, 'NPR', 'active')",
            [$rMat, $t['tenantA'], $t['facilityA'], $brA1b, $w1, 'Maternity Room', 'room-mat']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'icu', 8000, 'NPR', 'active')",
            [$rIcu, $t['tenantA'], $facA2, $brA2, $w2, 'ICU Bay', 'room-icu']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, daily_rate_minor, currency, status) values (?, ?, ?, ?, ?, ?, ?, 'general', 500, 'NPR', 'active')",
            [$rB, $t['tenantB'], $t['facilityB'], $brB, $wB, 'Standard Ward Room', 'room-b']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the rooms SELECT with the ward ref LEFT JOIN (the facility
        // clause applied ONLY when the caller has a facility claim —
        // org-level claims see every facility of the tenant; the branch
        // constraint comes from RLS).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select r.id, r.facility_id, r.branch_id, r.ward_id, r.name, r.code, r.room_type, r.daily_rate_minor, r.currency, r.status,\n                        w.id as ward_id_ref, w.code as ward_code, w.name as ward_name\n                   from public.rooms r\n                   left join public.wards w\n                     on w.tenant_id = r.tenant_id and w.facility_id = r.facility_id and w.id = r.ward_id and w.deleted_at is null\n                  where r.tenant_id = ? and r.deleted_at is null";
        $selectFacility = $select.' and r.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 + br-a1 claims: exactly the br-a1 rows + the
        //    branch-less row, ordered by name ASC; the exact projection
        //    with the HYDRATED facility/ward ids (real values) and the
        //    eager ward ref (id/code/name); the inactive Semi-Private row
        //    IS present (no status filter); the nullable branch contract.
        $rows = $c->select($selectFacility.' order by r.name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$rGen, $rPriv, $rSemi])
            ->and($rows[0]->name)->toBe('General Room')
            ->and($rows[0]->room_type)->toBe('general')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->branch_id)->toBe($brA1)
            ->and($rows[0]->ward_id)->toBe($w1)
            ->and($rows[0]->ward_id_ref)->toBe($w1)
            ->and($rows[0]->ward_code)->toBe('room-w1')
            ->and($rows[0]->ward_name)->toBe('General Ward')
            ->and($rows[0]->daily_rate_minor)->toBe(1000)
            ->and($rows[0]->currency)->toBe('NPR')
            ->and($rows[1]->name)->toBe('Private Suite')
            ->and($rows[2]->name)->toBe('Semi-Private')
            ->and($rows[2]->status)->toBe('inactive')
            ->and($rows[2]->branch_id)->toBeNull()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse();

        // 3. The wrong-branch Maternity Room (br-a1b) row is invisible to
        //    br-a1 claims; the fac-a2 ICU Bay and org-b Standard Ward Room
        //    rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($rMat)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($rIcu)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($rB);

        // 4. A br-a1b claim (same facility) sees exactly br-a1b + branch-less
        //    rows — the wrong-branch isolation is two-sided.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA1b]), $c);
        $brRows = $c->select($selectFacility.' order by r.name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$rMat, $rSemi]);

        // 5. Org-level claims (facility/branch NULL): every facility of the
        //    tenant — the `! isPlatform && facilityId() !== null` parity.
        //    Only the org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by r.name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$rGen, $rIcu, $rMat, $rPriv, $rSemi])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($rB);

        // 6. fac-a2 claims: exactly the ICU Bay row with its Surgery ward ref.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by r.name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$rIcu])
            ->and($fac2Rows[0]->ward_id_ref)->toBe($w2)
            ->and($fac2Rows[0]->ward_name)->toBe('Surgery');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by r.name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$rB])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update rooms set status = ? where id = ?', ['inactive', $rGen]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix.
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from rooms where id = ?', [$rGen])->status)->toBe('active')
            ->and($c->selectOne('select status from rooms where id = ?', [$rSemi])->status)->toBe('inactive');
    });
});
it('organizations:beds — the claims-scoped bed read is RLS-gated, room-ref-scoped, ordered, and mutation-free (Phase 38)', function () {
    // The exact RLS-scoped beds read organizations:beds runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // BedController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the beds SELECT is visible ONLY under matching claims — beds is
    //     TENANT_FACILITY_BRANCH: `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id
    //     = BRANCH OR BRANCH IS NULL)` — an org-level claim (facility/branch
    //     NULL) sees every facility of the tenant (the `! isPlatform &&
    //     facilityId() !== null` parity: no facility filter), a facility
    //     claim narrows to that facility, and a branch claim narrows to that
    //     branch + branch-less rows;
    //  3. ordering is `bed_code` ascending (the exact `->orderBy('bed_code')`);
    //     NO status filter — every lifecycle status returns
    //     (available/occupied/reserved/cleaning/out_of_service); beds are
    //     NEVER soft-deleted (no deleted_at filter — out_of_service is a
    //     status); the exact projection is the present() map —
    //     facility_id/room_id are NOT NULL and HYDRATED (real values),
    //     branch_id nullable (tenancy_v2), lock_version presented
    //     (CONTRACT-EXPLICIT); the room ref is eager-loaded (the exact
    //     `with('room:id,code,name,ward_id')`) carrying exactly id/code/name
    //     while tenant_id/created_at/updated_by/current_admission_id never
    //     leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (beds.branch_id carries the
        // composite FK to branches): brA1/brA1b of facA (the wrong-branch
        // proof), brA2 of facA2 (facility proof), brB of facB (tenant
        // proof). Wards + rooms seeded for the beds' composite FK (tenant,
        // facility, room_id) → rooms (tenant_id, facility_id, id) and the
        // rooms' own (tenant, facility, ward_id) → wards FK. Beds seeded
        // OUT of bed_code order: B-01 + B-02 (brA1, room R1), B-03
        // (branch-less, OUT_OF_SERVICE — the no-status-filter +
        // nullable-branch proof), B-04 (brA1b — the wrong-branch-invisible
        // proof), I-01 (facA2 — facility proof), S-01 (facB — tenant
        // proof). Codes unique per (tenant, room) — uq_beds_tenant_room_code.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA1b = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $w1 = (string) Str::uuid();
        $w2 = (string) Str::uuid();
        $wB = (string) Str::uuid();
        $r1 = (string) Str::uuid();
        $r2 = (string) Str::uuid();
        $rB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-bed-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA1b, $t['tenantA'], $t['facilityA'], 'Branch A1b'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'bed-br-'.$i, 'active']
            );
        }
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$w1, $t['tenantA'], $t['facilityA'], $brA1, 'General Ward', 'bed-w1', 'general', 'active']
        );
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$w2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'bed-w2', 'surgery', 'active']
        );
        $c->insert(
            'insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$wB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology Suite', 'bed-wb', 'other', 'active']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, status) values (?, ?, ?, ?, ?, ?, ?, 'general', 'active')",
            [$r1, $t['tenantA'], $t['facilityA'], $brA1, $w1, 'General Room', 'bed-r1']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, status) values (?, ?, ?, ?, ?, ?, ?, 'icu', 'active')",
            [$r2, $t['tenantA'], $facA2, $brA2, $w2, 'ICU Bay', 'bed-r2']
        );
        $c->insert(
            "insert into rooms (id, tenant_id, facility_id, branch_id, ward_id, name, code, room_type, status) values (?, ?, ?, ?, ?, ?, ?, 'general', 'active')",
            [$rB, $t['tenantB'], $t['facilityB'], $brB, $wB, 'Standard Ward Room', 'bed-rb']
        );

        $b01 = (string) Str::uuid();
        $b02 = (string) Str::uuid();
        $b03 = (string) Str::uuid();
        $b04 = (string) Str::uuid();
        $i01 = (string) Str::uuid();
        $s01 = (string) Str::uuid();

        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$b01, $t['tenantA'], $t['facilityA'], $brA1, $r1, 'B-01', 'available', 0]
        );
        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$b02, $t['tenantA'], $t['facilityA'], $brA1, $r1, 'B-02', 'occupied', 2]
        );
        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$b03, $t['tenantA'], $t['facilityA'], null, $r1, 'B-03', 'out_of_service', 5]
        );
        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$b04, $t['tenantA'], $t['facilityA'], $brA1b, $r1, 'B-04', 'reserved', 1]
        );
        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$i01, $t['tenantA'], $facA2, $brA2, $r2, 'I-01', 'cleaning', 3]
        );
        $c->insert(
            'insert into beds (id, tenant_id, facility_id, branch_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$s01, $t['tenantB'], $t['facilityB'], $brB, $rB, 'S-01', 'available', 0]
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the beds SELECT with the room ref LEFT JOIN (the facility
        // clause applied ONLY when the caller has a facility claim —
        // org-level claims see every facility of the tenant; the branch
        // constraint comes from RLS; NO deleted_at filter — beds are never
        // soft-deleted).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select b.id, b.facility_id, b.branch_id, b.room_id, b.bed_code, b.status, b.lock_version,\n                        rm.id as room_id_ref, rm.code as room_code, rm.name as room_name\n                   from public.beds b\n                   left join public.rooms rm\n                     on rm.tenant_id = b.tenant_id and rm.facility_id = b.facility_id and rm.id = b.room_id and rm.deleted_at is null\n                  where b.tenant_id = ?";
        $selectFacility = $select.' and b.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 + br-a1 claims: exactly the br-a1 rows + the
        //    branch-less row, ordered by bed_code ASC; the exact projection
        //    with the HYDRATED facility/room ids (real values) and the
        //    eager room ref (id/code/name); the out_of_service row IS
        //    present (no status filter + never soft-deleted); the nullable
        //    branch contract; lock_version presented.
        $rows = $c->select($selectFacility.' order by b.bed_code asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$b01, $b02, $b03])
            ->and($rows[0]->bed_code)->toBe('B-01')
            ->and($rows[0]->status)->toBe('available')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->branch_id)->toBe($brA1)
            ->and($rows[0]->room_id)->toBe($r1)
            ->and($rows[0]->room_id_ref)->toBe($r1)
            ->and($rows[0]->room_code)->toBe('bed-r1')
            ->and($rows[0]->room_name)->toBe('General Room')
            ->and($rows[0]->lock_version)->toBe(0)
            ->and($rows[1]->bed_code)->toBe('B-02')
            ->and($rows[1]->status)->toBe('occupied')
            ->and($rows[1]->lock_version)->toBe(2)
            ->and($rows[2]->bed_code)->toBe('B-03')
            ->and($rows[2]->status)->toBe('out_of_service')
            ->and($rows[2]->branch_id)->toBeNull()
            ->and($rows[2]->lock_version)->toBe(5)
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'current_admission_id'))->toBeFalse();

        // 3. The wrong-branch B-04 (br-a1b) row is invisible to br-a1
        //    claims; the fac-a2 I-01 and org-b S-01 rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($b04)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($i01)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($s01);

        // 4. A br-a1b claim (same facility) sees exactly br-a1b + branch-less
        //    rows — the wrong-branch isolation is two-sided.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA1b]), $c);
        $brRows = $c->select($selectFacility.' order by b.bed_code asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$b03, $b04]);

        // 5. Org-level claims (facility/branch NULL): every facility of the
        //    tenant — the `! isPlatform && facilityId() !== null` parity.
        //    Only the org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by b.bed_code asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$b01, $b02, $b03, $b04, $i01])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($s01);

        // 6. fac-a2 claims: exactly the I-01 row with its ICU Bay room ref.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by b.bed_code asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$i01])
            ->and($fac2Rows[0]->room_id_ref)->toBe($r2)
            ->and($fac2Rows[0]->room_name)->toBe('ICU Bay');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by b.bed_code asc', [$t['tenantB'], $t['facilityB']])))->toBe([$s01])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update beds set status = ? where id = ?', ['occupied', $b01]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses + lock_versions unchanged).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status, lock_version from beds where id = ?', [$b01])->status)->toBe('available')
            ->and($c->selectOne('select status, lock_version from beds where id = ?', [$b01])->lock_version)->toBe(0)
            ->and($c->selectOne('select status from beds where id = ?', [$b03])->status)->toBe('out_of_service');
    });
});
it('organizations:staff — the claims-scoped staff read is RLS-gated, dept-ref-scoped, ordered, and mutation-free (Phase 39)', function () {
    // The exact RLS-scoped staff read organizations:staff runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // StaffController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the staff SELECT is visible ONLY under matching claims — staff is
    //     TENANT_FACILITY (NOT TENANT_FACILITY_BRANCH): `tenant_id = TENANT
    //     AND (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO
    //     branch clause (staff has no branch_id column; a branch proposal
    //     does NOT narrow) — an org-level claim (facility NULL) sees every
    //     facility of the tenant (the `! isPlatform && facilityId() !==
    //     null` parity: no facility filter), a facility claim narrows to
    //     that facility;
    //  3. ordering is `full_name` ascending (the exact `->orderBy('full_name')`);
    //     NO status filter — active/on_leave/departed rows all return; staff
    //     are NEVER soft-deleted (no deleted_at filter — departed is a
    //     status); the exact projection is the present() map —
    //     facility_id/department_id are NOT NULL and HYDRATED (real values),
    //     user_id/designation/hire_date nullable (hire_date as YYYY-MM-DD),
    //     and license_number_encrypted is NEVER selected; the department ref
    //     is eager-loaded (the exact `with('department:id,code,name')`)
    //     carrying exactly id/code/name while tenant_id/created_at/
    //     updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (departments.branch_id carries the
        // composite FK to branches — staff itself has NO branch_id). The
        // departments are seeded for the staff composite FK (tenant,
        // facility, department_id) → departments (tenant_id, facility_id,
        // id). Users seeded for the plain user_id FK → users. Staff seeded
        // OUT of full_name order: Aarav Sharma + Bina Gurung + Chandra Rai
        // (facA1, dept D1 — on_leave), Dawa Sherpa (facA1, dept D1,
        // DEPARTED — the no-status-filter + never-soft-deleted proof, with
        // a soft-deleted department → the ref renders NULL — the
        // dept-ref-null proof), Erika Tamang (facA2, dept D2 — facility
        // proof), Femi Joshi (facB, dept DB — tenant proof). Employee codes
        // unique per tenant — uq_staff_tenant_employee_code; at most one
        // NON-departed record per user per tenant —
        // uq_staff_tenant_active_user.
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $d1 = (string) Str::uuid();
        $d1b = (string) Str::uuid();
        $d2 = (string) Str::uuid();
        $dB = (string) Str::uuid();
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $userC = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-staff-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'staff-br-'.$i, 'active']
            );
        }
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$d1, $t['tenantA'], $t['facilityA'], $brA1, 'Cardiology', 'staff-d1', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$d1b, $t['tenantA'], $t['facilityA'], $brA1, 'Old Cardiology', 'staff-d1b', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$d2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'staff-d2', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology', 'staff-db', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userA, 'staff-a@test.local', 'staff-a-hash', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userB, 'staff-b@test.local', 'staff-b-hash', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userC, 'staff-c@test.local', 'staff-c-hash', 'active']
        );

        $sAarav = (string) Str::uuid();
        $sBina = (string) Str::uuid();
        $sChandra = (string) Str::uuid();
        $sDawa = (string) Str::uuid();
        $sErika = (string) Str::uuid();
        $sFemi = (string) Str::uuid();

        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sAarav, $t['tenantA'], $t['facilityA'], $d1, $userA, 'EMP-001', 'Aarav Sharma', 'Cardiologist', 'active', '2024-01-15']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sBina, $t['tenantA'], $t['facilityA'], $d1, $userB, 'EMP-002', 'Bina Gurung', 'Nurse', 'active', '2023-06-01']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sChandra, $t['tenantA'], $t['facilityA'], $d1, null, 'EMP-003', 'Chandra Rai', 'Lab Tech', 'on_leave', null]
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sDawa, $t['tenantA'], $t['facilityA'], $d1b, $userA, 'EMP-004', 'Dawa Sherpa', null, 'departed', '2022-03-10']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sErika, $t['tenantA'], $facA2, $d2, $userC, 'EMP-101', 'Erika Tamang', 'ICU Specialist', 'active', '2025-02-20']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status, hire_date) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sFemi, $t['tenantB'], $t['facilityB'], $dB, $userA, 'EMP-501', 'Femi Joshi', 'Oncologist', 'active', '2024-09-01']
        );

        // Soft-delete Dawa's OWN department (d1b) — her department ref
        // renders NULL (never a leak) while the composite FK row itself
        // persists and the live d1 ref for the other three rows stays
        // resolved. The UPDATE must run under in-tenant claims or RLS
        // rejects it (0 rows).
        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->update('update departments set deleted_at = now() where id = ?', [$d1b]))->toBe(1);

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the staff SELECT with the department ref LEFT JOIN (the
        // facility clause applied ONLY when the caller has a facility claim
        // — org-level claims see every facility of the tenant; NO branch
        // clause — staff is TENANT_FACILITY; NO deleted_at filter — staff
        // are never soft-deleted).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select s.id, s.facility_id, s.department_id, s.employee_code, s.full_name, s.designation, s.status,\n                        s.user_id, to_char(s.hire_date, 'YYYY-MM-DD') as hire_date,\n                        d.id as dept_id_ref, d.code as dept_code, d.name as dept_name\n                   from public.staff s\n                   left join public.departments d\n                     on d.tenant_id = s.tenant_id and d.facility_id = s.facility_id and d.id = s.department_id and d.deleted_at is null\n                  where s.tenant_id = ?";
        $selectFacility = $select.' and s.facility_id = ?';

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: exactly the fac-a1 rows, ordered by full_name
        //    ASC; the exact projection with the HYDRATED facility/department
        //    ids (real values) and the eager department ref (id/code/name);
        //    the on_leave + departed rows ARE present (no status filter +
        //    never soft-deleted); the soft-deleted department renders the
        //    ref NULL; the nullable user/designation/hire_date contract;
        //    hire_date as YYYY-MM-DD.
        $rows = $c->select($selectFacility.' order by s.full_name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$sAarav, $sBina, $sChandra, $sDawa])
            ->and($rows[0]->full_name)->toBe('Aarav Sharma')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->department_id)->toBe($d1)
            ->and($rows[0]->dept_id_ref)->toBe($d1)
            ->and($rows[0]->dept_code)->toBe('staff-d1')
            ->and($rows[0]->dept_name)->toBe('Cardiology')
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[0]->user_id)->toBe($userA)
            ->and($rows[0]->hire_date)->toBe('2024-01-15')
            ->and($rows[1]->full_name)->toBe('Bina Gurung')
            ->and($rows[2]->full_name)->toBe('Chandra Rai')
            ->and($rows[2]->status)->toBe('on_leave')
            ->and($rows[2]->user_id)->toBeNull()
            ->and($rows[2]->hire_date)->toBeNull()
            ->and($rows[3]->full_name)->toBe('Dawa Sherpa')
            ->and($rows[3]->status)->toBe('departed')
            ->and($rows[3]->designation)->toBeNull()
            ->and($rows[3]->dept_id_ref)->toBeNull()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'license_number_encrypted'))->toBeFalse();

        // 3. The fac-a2 Erika and org-b Femi rows are invisible to fac-a1
        //    claims.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($sErika)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($sFemi);

        // 4. A branch proposal does NOT narrow the read — staff is
        //    TENANT_FACILITY (no branch clause). br-a2 claims (a different
        //    facility's branch) still see every fac-a1 row under fac-a1
        //    claims + the branch claim br-a2.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA2]), $c);
        $brRows = $c->select($selectFacility.' order by s.full_name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$sAarav, $sBina, $sChandra, $sDawa]);

        // 5. Org-level claims (facility NULL): every facility of the tenant
        //    — the `! isPlatform && facilityId() !== null` parity. Only the
        //    org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by s.full_name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$sAarav, $sBina, $sChandra, $sDawa, $sErika])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($sFemi);

        // 6. fac-a2 claims: exactly the Erika row with its Surgery dept ref.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by s.full_name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$sErika])
            ->and($fac2Rows[0]->dept_id_ref)->toBe($d2)
            ->and($fac2Rows[0]->dept_name)->toBe('Surgery');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by s.full_name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$sFemi])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update staff set status = ? where id = ?', ['on_leave', $sAarav]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from staff where id = ?', [$sAarav])->status)->toBe('active')
            ->and($c->selectOne('select status from staff where id = ?', [$sDawa])->status)->toBe('departed');
    });
});
it('organizations:services — the claims-scoped services read is RLS-gated, dept-ref-scoped, ordered, and mutation-free (Phase 40)', function () {
    // The exact RLS-scoped services read organizations:services runs is
    // proven on the REAL app-role connection (swasthya_app, NOBYPASSRLS),
    // mirroring ServiceController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the services SELECT is visible ONLY under matching claims —
    //     services is TENANT_FACILITY (NOT TENANT_FACILITY_BRANCH):
    //     `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
    //     NULL)` — there is NO branch clause (services has no branch_id
    //     column; a branch proposal does NOT narrow) — an org-level claim
    //     (facility NULL) sees every facility of the tenant (the `!
    //     isPlatform && facilityId() !== null` parity: no facility filter),
    //     a facility claim narrows to that facility;
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; **services ARE
    //     soft-deletable** — the SoftDeletes model scope excludes
    //     `deleted_at is not null` rows (the deleted_at filter IS present);
    //     the exact projection is the present() map — facility_id is NOT
    //     NULL and HYDRATED (real value), department_id nullable (the
    //     composite FK allows NULL — a service may be department-less),
    //     default_duration_minutes/default_charge_minor/currency nullable
    //     (money is integer minor units); the department ref is
    //     eager-loaded (the exact `with('department:id,code,name')`)
    //     carrying exactly id/code/name while tenant_id/created_at/
    //     updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + the branch graph (departments.branch_id carries the
        // composite FK to branches — services itself has NO branch_id). The
        // departments are seeded for the services composite FK (tenant,
        // facility, department_id) → departments (tenant_id, facility_id,
        // id) — the FK allows NULL department_id. Services seeded OUT of
        // name order: OPD Consultation + Procedure (facA1, dept D1), Lab
        // Investigation (facA1, DEPARTMENT-LESS + INACTIVE — the nullable
        // departmentId + no-status-filter proof), Deleted Follow-up (facA1,
        // SOFT-DELETED — the SoftDeletes-scope exclusion proof), Surgery
        // (facA2, dept D2 — facility proof), Oncology Consult (facB, dept
        // DB — tenant proof). Codes unique per (tenant, facility) among
        // live rows — uq_services_tenant_facility_code (partial, where
        // deleted_at is null).
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $d1 = (string) Str::uuid();
        $d2 = (string) Str::uuid();
        $dB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-svc-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'svc-br-'.$i, 'active']
            );
        }
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$d1, $t['tenantA'], $t['facilityA'], $brA1, 'Cardiology', 'svc-d1', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$d2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'svc-d2', 'active']
        );
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$dB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology', 'svc-db', 'active']
        );

        $sOpd = (string) Str::uuid();
        $sProc = (string) Str::uuid();
        $sLab = (string) Str::uuid();
        $sDel = (string) Str::uuid();
        $sSur = (string) Str::uuid();
        $sOnc = (string) Str::uuid();

        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'opd_consultation', 'active', 15, 50000, 'NPR')",
            [$sOpd, $t['tenantA'], $t['facilityA'], $d1, 'OPD Consultation', 'SVC-OPD']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'procedure', 'active', 60, 250000, 'NPR')",
            [$sProc, $t['tenantA'], $t['facilityA'], $d1, 'Procedure', 'SVC-PROC']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'investigation', 'inactive', NULL, NULL, NULL)",
            [$sLab, $t['tenantA'], $t['facilityA'], null, 'Lab Investigation', 'SVC-LAB']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency, deleted_at) values (?, ?, ?, ?, ?, ?, 'follow_up', 'active', 10, 10000, 'NPR', now())",
            [$sDel, $t['tenantA'], $t['facilityA'], $d1, 'Deleted Follow-up', 'SVC-FU']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'procedure', 'active', 120, 800000, 'NPR')",
            [$sSur, $t['tenantA'], $facA2, $d2, 'Surgery', 'SVC-SUR']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'opd_consultation', 'active', 30, 150000, 'NPR')",
            [$sOnc, $t['tenantB'], $t['facilityB'], $dB, 'Oncology Consult', 'SVC-ONC']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the services SELECT with the department ref LEFT JOIN (the
        // facility clause applied ONLY when the caller has a facility claim
        // — org-level claims see every facility of the tenant; NO branch
        // clause — services is TENANT_FACILITY; the deleted_at filter IS
        // present — services are soft-deletable).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select s.id, s.facility_id, s.department_id, s.name, s.code, s.service_type, s.status,\n                        s.default_duration_minutes, s.default_charge_minor, s.currency,\n                        d.id as dept_id_ref, d.code as dept_code, d.name as dept_name\n                   from public.services s\n                   left join public.departments d\n                     on d.tenant_id = s.tenant_id and d.facility_id = s.facility_id and d.id = s.department_id and d.deleted_at is null\n                  where s.tenant_id = ? and s.deleted_at is null";
        $selectFacility = $select.' and s.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: exactly the fac-a1 LIVE rows (the soft-deleted
        //    Follow-up EXCLUDED), ordered by name ASC; the exact projection
        //    with the HYDRATED facility/department ids (real values) and the
        //    eager department ref (id/code/name); the inactive Lab row IS
        //    present (no status filter) with its NULL departmentId + NULL
        //    duration/charge/currency (department-less service); money is
        //    integer minor units.
        $rows = $c->select($selectFacility.' order by s.name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$sLab, $sOpd, $sProc])
            ->and($rows[0]->name)->toBe('Lab Investigation')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->department_id)->toBeNull()
            ->and($rows[0]->dept_id_ref)->toBeNull()
            ->and($rows[0]->status)->toBe('inactive')
            ->and($rows[0]->default_duration_minutes)->toBeNull()
            ->and($rows[0]->default_charge_minor)->toBeNull()
            ->and($rows[0]->currency)->toBeNull()
            ->and($rows[1]->name)->toBe('OPD Consultation')
            ->and($rows[1]->department_id)->toBe($d1)
            ->and($rows[1]->dept_id_ref)->toBe($d1)
            ->and($rows[1]->dept_code)->toBe('svc-d1')
            ->and($rows[1]->dept_name)->toBe('Cardiology')
            ->and($rows[1]->service_type)->toBe('opd_consultation')
            ->and($rows[1]->default_duration_minutes)->toBe(15)
            ->and($rows[1]->default_charge_minor)->toBe(50000)
            ->and($rows[1]->currency)->toBe('NPR')
            ->and($rows[2]->name)->toBe('Procedure')
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse();

        // 3. The soft-deleted Follow-up row is invisible (SoftDeletes scope);
        //    the fac-a2 Surgery and org-b Oncology rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($sDel)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($sSur)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($sOnc);

        // 4. A branch proposal does NOT narrow the read — services is
        //    TENANT_FACILITY (no branch clause). br-a2 claims (a different
        //    facility's branch) still see every fac-a1 live row under
        //    fac-a1 claims + the branch claim br-a2.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA2]), $c);
        $brRows = $c->select($selectFacility.' order by s.name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$sLab, $sOpd, $sProc]);

        // 5. Org-level claims (facility NULL): every facility of the tenant
        //    — the `! isPlatform && facilityId() !== null` parity. Only the
        //    org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by s.name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$sLab, $sOpd, $sProc, $sSur])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($sOnc)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($sDel);

        // 6. fac-a2 claims: exactly the Surgery row with its Surgery dept ref.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by s.name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$sSur])
            ->and($fac2Rows[0]->dept_id_ref)->toBe($d2)
            ->and($fac2Rows[0]->dept_name)->toBe('Surgery');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by s.name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$sOnc])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update services set status = ? where id = ?', ['inactive', $sOpd]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged, the soft-deleted row
        //     stays deleted).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from services where id = ?', [$sOpd])->status)->toBe('active')
            ->and($c->selectOne('select status from services where id = ?', [$sLab])->status)->toBe('inactive')
            ->and($c->selectOne('select deleted_at from services where id = ?', [$sDel])->deleted_at)->not->toBeNull();
    });
});
it('organizations:payers — the claims-scoped payer read is RLS-gated, tenant-wide, ordered, and mutation-free (Phase 41)', function () {
    // The exact RLS-scoped payer read organizations:payers runs is proven on
    // the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // PayerController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the payers SELECT is visible ONLY under matching claims — payers
    //     is **TENANT_ONLY** (NO facility_id column at all — a policy
    //     covers a patient at ANY facility of the tenant, so the select
    //     policy is just `tenant_id = TENANT` — there is NO facility clause
    //     AND NO facility filter in the Laravel query, so even a
    //     facility-scoped caller sees every tenant payer — the material
    //     TENANT_ONLY difference from the TENANT_FACILITY catalog reads);
    //  3. ordering is `name` ascending (the exact `->orderBy('name')`);
    //     NO status filter — active AND inactive rows return; payers has NO
    //     SoftDeletes — nothing is excluded; the exact projection is the
    //     present() map — id/name/code/payer_type/status while
    //     tenant_id/created_at/updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Payer rows seeded OUT of name order with all four payer_type
        // values (government/private/tpa/other) and one INACTIVE (the
        // NO-status-filter proof): 4 tenant-A payers + 1 tenant-B payer
        // (tenant isolation — payers has NO facility dimension, so there is
        // no facility fixture at all). Codes unique per tenant —
        // uq_payers_tenant_code.
        $pGov = (string) Str::uuid();
        $pPriv = (string) Str::uuid();
        $pTpa = (string) Str::uuid();
        $pOther = (string) Str::uuid();
        $pB = (string) Str::uuid();

        $c->insert(
            "insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, 'government', 'active')",
            [$pGov, $t['tenantA'], 'Government Health Fund', 'GHF']
        );
        $c->insert(
            "insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, 'private', 'active')",
            [$pPriv, $t['tenantA'], 'National Insurance', 'NIC']
        );
        $c->insert(
            "insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, 'tpa', 'active')",
            [$pTpa, $t['tenantA'], 'Star TPA', 'STAR']
        );
        $c->insert(
            "insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, 'other', 'inactive')",
            [$pOther, $t['tenantA'], 'Walk-in Self Pay', 'SELF']
        );
        $c->insert(
            "insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, 'tpa', 'active')",
            [$pB, $t['tenantB'], 'Sagarmatha TPA', 'SAG']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the payers SELECT — `where tenant_id = ?` ONLY (NO facility
        // clause — payers is TENANT_ONLY; NO deleted_at filter — payers has
        // no SoftDeletes).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = 'select id, name, code, payer_type, status from public.payers where tenant_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: EVERY tenant-A payer (TENANT_ONLY — the
        //    facility claim does NOT narrow; even a facility-scoped caller
        //    sees every tenant payer), ordered by name ASC; the exact
        //    projection id/name/code/payer_type/status; the INACTIVE row IS
        //    present (no status filter); the tenant-B row is NOT.
        $rows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$pGov, $pPriv, $pTpa, $pOther])
            ->and($rows[0]->name)->toBe('Government Health Fund')
            ->and($rows[0]->code)->toBe('GHF')
            ->and($rows[0]->payer_type)->toBe('government')
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[1]->name)->toBe('National Insurance')
            ->and($rows[1]->code)->toBe('NIC')
            ->and($rows[1]->payer_type)->toBe('private')
            ->and($rows[2]->name)->toBe('Star TPA')
            ->and($rows[2]->code)->toBe('STAR')
            ->and($rows[2]->payer_type)->toBe('tpa')
            ->and($rows[3]->name)->toBe('Walk-in Self Pay')
            ->and($rows[3]->code)->toBe('SELF')
            ->and($rows[3]->payer_type)->toBe('other')
            ->and($rows[3]->status)->toBe('inactive')
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($pB)
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. A branch proposal does NOT narrow the read — payers is
        //    TENANT_ONLY (no branch clause, no branch column at all).
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => (string) Str::uuid()]), $c);
        $brRows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$pGov, $pPriv, $pTpa, $pOther]);

        // 4. Org-level claims (facility NULL): the same four rows — the org
        //    gate parity (no facility filter in the Laravel query at all).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$pGov, $pPriv, $pTpa, $pOther])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($pB);

        // 5. Other-tenant claims: exactly the tenant-B row — the tenant-A
        //    rows are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($select.' order by name asc', [$t['tenantB']])))->toBe([$pB])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update payers set status = ? where id = ?', ['inactive', $pGov]))->toBe(0);

        // 6. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 7. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 8. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged; each row read under its
        //     own tenant's claims — RLS keeps them invisible otherwise).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from payers where id = ?', [$pGov])->status)->toBe('active')
            ->and($c->selectOne('select status from payers where id = ?', [$pOther])->status)->toBe('inactive');
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->selectOne('select status from payers where id = ?', [$pB])->status)->toBe('active');
    });
});
it('organizations:medications — the claims-scoped formulary read is RLS-gated, facility-scoped, ordered, and mutation-free (Phase 42)', function () {
    // The exact RLS-scoped formulary read organizations:medications runs is
    // proven on the REAL app-role connection (swasthya_app, NOBYPASSRLS),
    // mirroring MedicationController::index + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the medications SELECT is visible ONLY under matching claims —
    //     medications is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH):
    //     `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
    //     NULL)` — there is NO branch clause (medications has no branch_id
    //     column; a branch proposal does NOT narrow) — an org-level claim
    //     (facility NULL) sees every facility of the tenant (the `!
    //     isPlatform && facilityId() !== null` parity: no facility filter),
    //     a facility claim narrows to that facility;
    //  3. ordering is `generic_name` ascending (the exact
    //     `->orderBy('generic_name')`); NO status filter — active AND
    //     inactive rows return; **medications ARE soft-deletable** — the
    //     SoftDeletes model scope excludes `deleted_at is not null` rows
    //     (the deleted_at filter IS present); the exact projection is the
    //     present() map — facility_id is NOT NULL and HYDRATED (real
    //     value), brand_name nullable (the only nullable text field),
    //     strength/form/unit NOT NULL (form defaults to 'tablet'),
    //     price_minor integer minor units (>= 0 CHECK), currency 3-char,
    //     is_controlled boolean; lock_version/tenant_id/created_at/
    //     updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 fixture. Medications seeded OUT of generic_name order:
        // Amoxicillin + Ibuprofen (INACTIVE — the no-status-filter proof) +
        // Metformin (NULL brand — the nullable proof, NOT a controlled row)
        // at facA1, Delisted Syrup (SOFT-DELETED — the SoftDeletes-scope
        // exclusion proof) at facA1, Paracetamol (facA2 — facility proof),
        // Insulin (facB — tenant proof; CONTROLLED — the is_controlled
        // boolean proof). Codes unique per (tenant, facility) among live
        // rows — uq_medications_tenant_facility_code (partial, where
        // deleted_at is null); the composite FK (tenant, facility) →
        // facilities.
        $facA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-med-a2', 'active', 'UTC', '{}', '{}']
        );

        $mAmox = (string) Str::uuid();
        $mIbu = (string) Str::uuid();
        $mMet = (string) Str::uuid();
        $mDel = (string) Str::uuid();
        $mPara = (string) Str::uuid();
        $mIns = (string) Str::uuid();

        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, 'active')",
            [$mAmox, $t['tenantA'], $t['facilityA'], 'MED-AMOX', 'Amoxicillin', 'Amoxil', '250mg', 'capsule', 'cap', 30000, 'NPR']
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, 'inactive')",
            [$mIbu, $t['tenantA'], $t['facilityA'], 'MED-IBU', 'Ibuprofen', 'Brufen', '400mg', 'tablet', 'tab', 25000, 'NPR']
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, false, 'active')",
            [$mMet, $t['tenantA'], $t['facilityA'], 'MED-MET', 'Metformin', '500mg', 'tablet', 'tab', 15000, 'NPR']
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status, deleted_at) values (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, false, 'active', now())",
            [$mDel, $t['tenantA'], $t['facilityA'], 'MED-DEL', 'Delisted Syrup', '120ml', 'syrup', 'bottle', 90000, 'NPR']
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false, 'active')",
            [$mPara, $t['tenantA'], $facA2, 'MED-PARA', 'Paracetamol', 'Calpol', '500mg', 'tablet', 'tab', 10000, 'NPR']
        );
        $c->insert(
            "insert into medications (id, tenant_id, facility_id, code, generic_name, brand_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true, 'active')",
            [$mIns, $t['tenantB'], $t['facilityB'], 'MED-INS', 'Insulin', 'Humulin', '100IU', 'injection', 'vial', 120000, 'NPR']
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the medications SELECT (the facility clause applied ONLY when
        // the caller has a facility claim — org-level claims see every
        // facility of the tenant; NO branch clause — medications is
        // TENANT_FACILITY; the deleted_at filter IS present — medications
        // are soft-deletable).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select m.id, m.facility_id, m.code, m.generic_name, m.brand_name, m.strength, m.form, m.unit,\n                        m.price_minor, m.currency, m.is_controlled, m.status\n                   from public.medications m\n                  where m.tenant_id = ? and m.deleted_at is null";
        $selectFacility = $select.' and m.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: exactly the fac-a1 LIVE rows (the soft-deleted
        //    Delisted Syrup EXCLUDED), ordered by generic_name ASC; the
        //    exact projection with the HYDRATED facility id (real value)
        //    and the formulary fields (brand_name nullable, strength/form/
        //    unit NOT NULL, price_minor integer minor units, currency
        //    3-char, is_controlled boolean); the inactive Ibuprofen row IS
        //    present (no status filter).
        $rows = $c->select($selectFacility.' order by m.generic_name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$mAmox, $mIbu, $mMet])
            ->and($rows[0]->generic_name)->toBe('Amoxicillin')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->brand_name)->toBe('Amoxil')
            ->and($rows[0]->strength)->toBe('250mg')
            ->and($rows[0]->form)->toBe('capsule')
            ->and($rows[0]->unit)->toBe('cap')
            ->and($rows[0]->price_minor)->toBe(30000)
            ->and($rows[0]->currency)->toBe('NPR')
            ->and($rows[0]->is_controlled)->toBe(false)
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[1]->generic_name)->toBe('Ibuprofen')
            ->and($rows[1]->status)->toBe('inactive')
            ->and($rows[2]->generic_name)->toBe('Metformin')
            ->and($rows[2]->brand_name)->toBeNull()
            ->and(property_exists($rows[0], 'lock_version'))->toBeFalse()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The soft-deleted Delisted Syrup row is invisible (SoftDeletes
        //    scope); the fac-a2 Paracetamol and org-b Insulin rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($mDel)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($mPara)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($mIns);

        // 4. A branch proposal does NOT narrow the read — medications is
        //    TENANT_FACILITY (no branch clause).
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => (string) Str::uuid()]), $c);
        $brRows = $c->select($selectFacility.' order by m.generic_name asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$mAmox, $mIbu, $mMet]);

        // 5. Org-level claims (facility NULL): every facility of the tenant
        //    — the `! isPlatform && facilityId() !== null` parity. Only the
        //    org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by m.generic_name asc', [$t['tenantA']]);
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$mAmox, $mIbu, $mMet, $mPara])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($mIns)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($mDel);

        // 6. fac-a2 claims: exactly the Paracetamol row.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by m.generic_name asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$mPara])
            ->and($fac2Rows[0]->facility_id)->toBe($facA2);

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by m.generic_name asc', [$t['tenantB'], $t['facilityB']])))->toBe([$mIns])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update medications set status = ? where id = ?', ['inactive', $mAmox]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged, the soft-deleted row
        //     stays deleted).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from medications where id = ?', [$mAmox])->status)->toBe('active')
            ->and($c->selectOne('select status from medications where id = ?', [$mIbu])->status)->toBe('inactive')
            ->and($c->selectOne('select deleted_at from medications where id = ?', [$mDel])->deleted_at)->not->toBeNull();
    });
});
it('organizations:schedule-templates — the claims-scoped schedule-template read is RLS-gated, staff-ref-scoped, ordered, and mutation-free (Phase 43)', function () {
    // The exact RLS-scoped schedule-template read organizations:schedule-
    // templates runs is proven on the REAL app-role connection
    // (swasthya_app, NOBYPASSRLS), mirroring ScheduleController::templates
    // + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the schedule_templates SELECT is visible ONLY under matching
    //     claims — schedule_templates is **TENANT_FACILITY** (NOT
    //     TENANT_FACILITY_BRANCH): `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL)` — there is NO branch clause
    //     (schedule_templates has no branch_id column; a branch proposal
    //     does NOT narrow) — an org-level claim (facility NULL) sees every
    //     facility of the tenant (the `! isPlatform && facilityId() !==
    //     null` parity: no facility filter), a facility claim narrows to
    //     that facility;
    //  3. ordering is `day_of_week` ascending (the exact
    //     `->orderBy('day_of_week')`); NO status filter — active AND
    //     inactive rows return; **schedule templates ARE soft-deletable** —
    //     the SoftDeletes model scope excludes `deleted_at is not null`
    //     rows (the deleted_at filter IS present); the exact projection is
    //     the presentTemplate map — facility_id/staff_id NOT NULL and
    //     HYDRATED (real values), service_id nullable (the composite FK
    //     allows NULL — a service-less template), starts_at/ends_at are
    //     TIME columns formatted H:i, valid_from/valid_to DATE columns
    //     (valid_to nullable), day_of_week 0..6 (ISO 8601),
    //     slot_minutes/capacity integers; the staff ref is eager-loaded
    //     (the exact `with('staff:id,full_name,designation')`) carrying
    //     exactly id/full_name/designation while tenant_id/created_at/
    //     updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // Facility-A2 + a staff graph: schedule_templates carries the
        // composite FKs (tenant, facility, staff_id) → staff and (tenant,
        // facility, service_id) → services (NULL-allowed service_id). The
        // staff rows are seeded for the FK; services for the service_id FK.
        // Templates seeded OUT of day_of_week order: Sunday (Nurse ref) +
        // Monday (Cardiologist ref) + Tuesday (INACTIVE — the no-status-
        // filter proof, NULL valid_to — the nullable-date proof) +
        // No-Service (NULL service_id — the nullable proof) at facA1,
        // Delisted Thursday (SOFT-DELETED template — the SoftDeletes-scope
        // exclusion proof) at facA1, Thursday (facA2 — facility proof),
        // Sunday (facB — tenant proof).
        $facA2 = (string) Str::uuid();
        $sDr = (string) Str::uuid();
        $sNr = (string) Str::uuid();
        $sGd = (string) Str::uuid();
        $sIcu = (string) Str::uuid();
        $sOnc = (string) Str::uuid();
        $svcA1 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-sch-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'sch-br-'.$i, 'active']
            );
        }
        // departments for the staff composite FK (tenant, facility,
        // department_id) → departments.
        $d1 = (string) Str::uuid();
        $d2 = (string) Str::uuid();
        $dB = (string) Str::uuid();
        foreach ([
            [$d1, $t['tenantA'], $t['facilityA'], $brA1, 'Cardiology', 'sch-d1', 'active'],
            [$d2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'sch-d2', 'active'],
            [$dB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology', 'sch-db', 'active'],
        ] as $i => $dept) {
            $c->insert(
                'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
                $dept
            );
        }
        $svcA2 = (string) Str::uuid();
        $svcB = (string) Str::uuid();
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'opd_consultation', 'active', 15, 50000, 'NPR')",
            [$svcA1, $t['tenantA'], $t['facilityA'], $d1, 'OPD Consultation', 'SCH-SVC']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'procedure', 'active', 120, 800000, 'NPR')",
            [$svcA2, $t['tenantA'], $facA2, $d2, 'Surgery', 'SCH-SVC2']
        );
        $c->insert(
            "insert into services (id, tenant_id, facility_id, department_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency) values (?, ?, ?, ?, ?, ?, 'opd_consultation', 'active', 30, 150000, 'NPR')",
            [$svcB, $t['tenantB'], $t['facilityB'], $dB, 'Oncology Consult', 'SCH-SVCB']
        );
        // staff rows — staff has NO SoftDeletes (Phase 39) and the
        // composite FK (tenant, facility, staff_id) → staff is RESTRICT, so
        // the eager staff ref always resolves in a consistent DB (the
        // Laravel `?: null` is unreachable in practice).
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sDr, $t['tenantA'], $t['facilityA'], $d1, 'SCH-001', 'Aarav Sharma', 'Cardiologist', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sNr, $t['tenantA'], $t['facilityA'], $d1, 'SCH-002', 'Bina Gurung', 'Nurse', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sGd, $t['tenantA'], $t['facilityA'], $d1, 'SCH-003', 'Dawa Sherpa', 'Nurse', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sIcu, $t['tenantA'], $facA2, $d2, 'SCH-101', 'Erika Tamang', 'ICU Specialist', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sOnc, $t['tenantB'], $t['facilityB'], $dB, 'SCH-501', 'Femi Joshi', 'Oncologist', 'active']
        );

        $tmSun = (string) Str::uuid();
        $tmMon = (string) Str::uuid();
        $tmTue = (string) Str::uuid();
        $tmNosvc = (string) Str::uuid();
        $tmDel = (string) Str::uuid();
        $tmThu = (string) Str::uuid();
        $tmBSun = (string) Str::uuid();

        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, ?, 0, '08:00', '10:00', 20, 3, '2026-01-01', '2026-06-30', 'active')",
            [$tmSun, $t['tenantA'], $t['facilityA'], $sNr, $svcA1]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, ?, 1, '09:00', '12:00', 30, 2, '2026-01-01', '2026-12-31', 'active')",
            [$tmMon, $t['tenantA'], $t['facilityA'], $sDr, $svcA1]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, ?, 2, '13:00', '16:00', 45, 1, '2026-01-01', NULL, 'inactive')",
            [$tmTue, $t['tenantA'], $t['facilityA'], $sDr, $svcA1]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, NULL, 4, '09:30', '11:30', 30, 1, '2026-02-01', NULL, 'active')",
            [$tmNosvc, $t['tenantA'], $t['facilityA'], $sGd]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status, deleted_at) values (?, ?, ?, ?, ?, 3, '10:00', '11:00', 30, 1, '2026-01-01', NULL, 'active', now())",
            [$tmDel, $t['tenantA'], $t['facilityA'], $sDr, $svcA1]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, ?, 4, '09:00', '13:00', 60, 4, '2026-01-01', '2026-12-31', 'active')",
            [$tmThu, $t['tenantA'], $facA2, $sIcu, $svcA2]
        );
        $c->insert(
            "insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, valid_to, status) values (?, ?, ?, ?, ?, 0, '09:00', '12:00', 30, 2, '2026-01-01', NULL, 'active')",
            [$tmBSun, $t['tenantB'], $t['facilityB'], $sOnc, $svcB]
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the schedule_templates SELECT with the staff ref LEFT JOIN
        // (the facility clause applied ONLY when the caller has a facility
        // claim — org-level claims see every facility of the tenant; NO
        // branch clause — schedule_templates is TENANT_FACILITY; the
        // deleted_at filter IS present — schedule templates are
        // soft-deletable).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = "select t.id, t.facility_id, t.staff_id, t.service_id, t.day_of_week,\n                        to_char(t.starts_at, 'HH24:MI') as starts_at, to_char(t.ends_at, 'HH24:MI') as ends_at,\n                        t.slot_minutes, t.capacity, t.valid_from, t.valid_to, t.status,\n                        s.id as staff_id_ref, s.full_name as staff_full_name, s.designation as staff_designation\n                   from public.schedule_templates t\n                   left join public.staff s\n                     on s.tenant_id = t.tenant_id and s.facility_id = t.facility_id and s.id = t.staff_id\n                  where t.tenant_id = ? and t.deleted_at is null";
        $selectFacility = $select.' and t.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: exactly the fac-a1 LIVE rows (the soft-deleted
        //    template EXCLUDED), ordered by day_of_week ASC; the exact
        //    projection with the HYDRATED facility/staff ids (real values)
        //    and the eager staff ref (id/full_name/designation); the
        //    inactive Tuesday row IS present (no status filter) with its
        //    NULL valid_to; the No-Service row has a NULL service_id (the
        //    nullable proof) with its staff ref ALWAYS resolvable (staff
        //    has no SoftDeletes — the composite FK RESTRICT); times are
        //    H:i, dates YYYY-MM-DD, day_of_week 0..6.
        $rows = $c->select($selectFacility.' order by t.day_of_week asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$tmSun, $tmMon, $tmTue, $tmNosvc])
            ->and($rows[0]->day_of_week)->toBe(0)
            ->and($rows[0]->staff_id_ref)->toBe($sNr)
            ->and($rows[0]->staff_full_name)->toBe('Bina Gurung')
            ->and($rows[0]->staff_designation)->toBe('Nurse')
            ->and($rows[0]->starts_at)->toBe('08:00')
            ->and($rows[0]->ends_at)->toBe('10:00')
            ->and($rows[0]->valid_from)->toBe('2026-01-01')
            ->and($rows[0]->valid_to)->toBe('2026-06-30')
            ->and($rows[0]->slot_minutes)->toBe(20)
            ->and($rows[0]->capacity)->toBe(3)
            ->and($rows[1]->day_of_week)->toBe(1)
            ->and($rows[1]->staff_id_ref)->toBe($sDr)
            ->and($rows[1]->staff_full_name)->toBe('Aarav Sharma')
            ->and($rows[1]->staff_designation)->toBe('Cardiologist')
            ->and($rows[1]->facility_id)->toBe($t['facilityA'])
            ->and($rows[1]->status)->toBe('active')
            ->and($rows[2]->day_of_week)->toBe(2)
            ->and($rows[2]->status)->toBe('inactive')
            ->and($rows[2]->valid_to)->toBeNull()
            ->and($rows[3]->day_of_week)->toBe(4)
            ->and($rows[3]->service_id)->toBeNull()
            ->and($rows[3]->staff_id_ref)->toBe($sGd)
            ->and($rows[3]->staff_full_name)->toBe('Dawa Sherpa')
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The soft-deleted template row is invisible (SoftDeletes
        //    scope); the fac-a2 Thursday and org-b Sunday rows too.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($tmDel)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($tmThu)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($tmBSun);

        // 4. A branch proposal does NOT narrow the read — schedule_templates
        //    is TENANT_FACILITY (no branch clause). br-a2 claims (a
        //    different facility's branch) still see every fac-a1 live row
        //    under fac-a1 claims + the branch claim br-a2.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA2]), $c);
        $brRows = $c->select($selectFacility.' order by t.day_of_week asc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$tmSun, $tmMon, $tmTue, $tmNosvc]);

        // 5. Org-level claims (facility NULL): every facility of the tenant
        //    — the `! isPlatform && facilityId() !== null` parity. Only the
        //    org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by t.day_of_week asc', [$t['tenantA']]);
        // The exact Laravel order is day_of_week ASC with NO secondary key —
        // the two day-4 rows (No-Service + fac-a2 Thursday) TIE, so only the
        // day sequence and the row SET are deterministic; the tie order is
        // PostgreSQL-unspecified (exactly as Laravel's `->orderBy` leaves
        // it).
        expect(array_map(fn ($r) => $r->day_of_week, $orgRows))->toBe([0, 1, 2, 4, 4])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->toContain($tmSun)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->toContain($tmMon)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->toContain($tmTue)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->toContain($tmNosvc)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->toContain($tmThu)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($tmBSun)
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($tmDel);

        // 6. fac-a2 claims: exactly the Thursday row with its ICU staff ref.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by t.day_of_week asc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$tmThu])
            ->and($fac2Rows[0]->staff_id_ref)->toBe($sIcu)
            ->and($fac2Rows[0]->staff_full_name)->toBe('Erika Tamang');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by t.day_of_week asc', [$t['tenantB'], $t['facilityB']])))->toBe([$tmBSun])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update schedule_templates set status = ? where id = ?', ['inactive', $tmMon]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged, the soft-deleted row
        //     stays deleted).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from schedule_templates where id = ?', [$tmMon])->status)->toBe('active')
            ->and($c->selectOne('select status from schedule_templates where id = ?', [$tmTue])->status)->toBe('inactive')
            ->and($c->selectOne('select deleted_at from schedule_templates where id = ?', [$tmDel])->deleted_at)->not->toBeNull();
    });
});
it('organizations:schedule-exceptions — the claims-scoped schedule-exception read is RLS-gated, ordered, and mutation-free (Phase 44)', function () {
    // The exact RLS-scoped schedule-exception read organizations:schedule-
    // exceptions runs is proven on the REAL app-role connection
    // (swasthya_app, NOBYPASSRLS), mirroring ScheduleController::exceptions
    // + AccessCheck::organization:
    //  1. the organization gate resolves the org by id (organizations is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the schedule_exceptions SELECT is visible ONLY under matching
    //     claims — schedule_exceptions is **TENANT_FACILITY** (NOT
    //     TENANT_FACILITY_BRANCH): `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL)` — there is NO branch clause
    //     (schedule_exceptions has no branch_id column; a branch proposal
    //     does NOT narrow) — an org-level claim (facility NULL) sees every
    //     facility of the tenant (the `! isPlatform && facilityId() !==
    //     null` parity: no facility filter), a facility claim narrows to
    //     that facility;
    //  3. ordering is `exception_date` DESCENDING (the exact
    //     `->orderByDesc('exception_date')`); NO status filter — active AND
    //     cancelled rows return (the CHECK-constrained lifecycle statuses);
    //     **schedule exceptions are NOT soft-deletable** — the
    //     ScheduleException model has NO SoftDeletes trait and the table
    //     has NO `deleted_at` column, so there is NO soft-delete filter;
    //     the exact projection is the presentException map —
    //     facility_id/staff_id NOT NULL and HYDRATED (real values),
    //     exception_date a DATE column (YYYY-MM-DD), reason ∈
    //     leave/holiday/block (the CHECK constraint), status ∈
    //     active/cancelled (the CHECK constraint); the staff eager load is
    //     a query-level detail — the staff reference is NOT presented
    //     (presentException exposes no staff ref) and the template_id
    //     column is never projected — tenant_id/created_at/created_by/
    //     updated_by never leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // A staff graph for the composite FK (tenant, facility, staff_id) →
        // staff (RESTRICT). Exceptions seeded OUT of exception_date order:
        // Leave (Mar 5, active) + Holiday-Cancelled (Feb 14, CANCELLED —
        // the no-status-filter proof; reason holiday) + Block (Apr 1,
        // active — the newest date) at facA1, Leave (Feb 20 — facA2 —
        // facility proof), Holiday (Mar 1 — facB — tenant proof). Each
        // (tenant_id, staff_id, exception_date) triple is unique (the
        // uq_schedule_exceptions_tenant_staff_date index).
        $sDr = (string) Str::uuid();
        $sNr = (string) Str::uuid();
        $sIcu = (string) Str::uuid();
        $sOnc = (string) Str::uuid();
        $facA2 = (string) Str::uuid();
        $brA1 = (string) Str::uuid();
        $brA2 = (string) Str::uuid();
        $brB = (string) Str::uuid();
        $d1 = (string) Str::uuid();
        $d2 = (string) Str::uuid();
        $dB = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-exc-a2', 'active', 'UTC', '{}', '{}']
        );
        foreach ([
            [$brA1, $t['tenantA'], $t['facilityA'], 'Branch A1'],
            [$brA2, $t['tenantA'], $facA2, 'Branch A2'],
            [$brB, $t['tenantB'], $t['facilityB'], 'Branch B'],
        ] as $i => $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch[0], $branch[1], $branch[2], $branch[3], 'exc-br-'.$i, 'active']
            );
        }
        // departments for the staff composite FK (tenant, facility,
        // department_id) → departments.
        foreach ([
            [$d1, $t['tenantA'], $t['facilityA'], $brA1, 'Cardiology', 'exc-d1', 'active'],
            [$d2, $t['tenantA'], $facA2, $brA2, 'Surgery', 'exc-d2', 'active'],
            [$dB, $t['tenantB'], $t['facilityB'], $brB, 'Oncology', 'exc-db', 'active'],
        ] as $i => $dept) {
            $c->insert(
                'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
                $dept
            );
        }
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sDr, $t['tenantA'], $t['facilityA'], $d1, 'EXC-001', 'Aarav Sharma', 'Cardiologist', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sNr, $t['tenantA'], $t['facilityA'], $d1, 'EXC-002', 'Bina Gurung', 'Nurse', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sIcu, $t['tenantA'], $facA2, $d2, 'EXC-101', 'Erika Tamang', 'ICU Specialist', 'active']
        );
        $c->insert(
            'insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, user_id) values (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [$sOnc, $t['tenantB'], $t['facilityB'], $dB, 'EXC-501', 'Femi Joshi', 'Oncologist', 'active']
        );

        $exLeave = (string) Str::uuid();
        $exHolCxl = (string) Str::uuid();
        $exBlock = (string) Str::uuid();
        $exA2 = (string) Str::uuid();
        $exB = (string) Str::uuid();

        $c->insert(
            "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-03-05', 'leave', 'active')",
            [$exLeave, $t['tenantA'], $t['facilityA'], $sDr]
        );
        $c->insert(
            "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-02-14', 'holiday', 'cancelled')",
            [$exHolCxl, $t['tenantA'], $t['facilityA'], $sNr]
        );
        $c->insert(
            "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-04-01', 'block', 'active')",
            [$exBlock, $t['tenantA'], $t['facilityA'], $sDr]
        );
        $c->insert(
            "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-02-20', 'leave', 'active')",
            [$exA2, $t['tenantA'], $facA2, $sIcu]
        );
        $c->insert(
            "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-03-01', 'holiday', 'active')",
            [$exB, $t['tenantB'], $t['facilityB'], $sOnc]
        );

        // The exact edge queries: the org gate (id is a resource selector;
        // organizations is un-scoped — the 404 classes are the app layer)
        // and the schedule_exceptions SELECT (the facility clause applied
        // ONLY when the caller has a facility claim — org-level claims see
        // every facility of the tenant; NO branch clause — schedule_
        // exceptions is TENANT_FACILITY; NO deleted_at filter — exceptions
        // are NOT soft-deletable; only the presented columns are selected —
        // the template_id/tenant/timestamp/audit columns never leave).
        $gate = 'select id from public.organizations where id = ? limit 1';
        $select = 'select t.id, t.facility_id, t.staff_id, t.exception_date, t.reason, t.status from public.schedule_exceptions t where t.tenant_id = ?';
        $selectFacility = $select.' and t.facility_id = ?';

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => $brA1,
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The org gate resolves the selector (the org exists).
        expect($c->selectOne($gate, [$t['tenantA']]))->not->toBeNull();

        // 2. fac-a1 claims: exactly the fac-a1 rows, ordered by
        //    exception_date DESC (Apr 1, Mar 5, Feb 14); the exact
        //    projection with the HYDRATED facility/staff ids (real values);
        //    the CANCELLED holiday row IS present (no status filter) with
        //    its reason; the Block row carries reason block; the staff
        //    reference is NOT presented (presentException exposes no staff
        //    ref — unlike the templates read) and the template_id/tenant/
        //    timestamp/audit columns never leave.
        $rows = $c->select($selectFacility.' order by t.exception_date desc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $rows))->toBe([$exBlock, $exLeave, $exHolCxl])
            ->and($rows[0]->exception_date)->toBe('2026-04-01')
            ->and($rows[0]->reason)->toBe('block')
            ->and($rows[0]->status)->toBe('active')
            ->and($rows[0]->facility_id)->toBe($t['facilityA'])
            ->and($rows[0]->staff_id)->toBe($sDr)
            ->and($rows[1]->exception_date)->toBe('2026-03-05')
            ->and($rows[1]->reason)->toBe('leave')
            ->and($rows[1]->status)->toBe('active')
            ->and($rows[2]->exception_date)->toBe('2026-02-14')
            ->and($rows[2]->reason)->toBe('holiday')
            ->and($rows[2]->status)->toBe('cancelled')
            ->and(property_exists($rows[0], 'staff_full_name'))->toBeFalse()
            ->and(property_exists($rows[0], 'template_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse();

        // 3. The fac-a2 and org-b rows are invisible to fac-a1 claims.
        expect(array_map(fn ($r) => $r->id, $rows))->not->toContain($exA2)
            ->and(array_map(fn ($r) => $r->id, $rows))->not->toContain($exB);

        // 4. A branch proposal does NOT narrow the read — schedule_exceptions
        //    is TENANT_FACILITY (no branch clause). br-a2 claims (a
        //    different facility's branch) still see every fac-a1 row under
        //    fac-a1 claims + the branch claim br-a2.
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => $brA2]), $c);
        $brRows = $c->select($selectFacility.' order by t.exception_date desc', [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->id, $brRows))->toBe([$exBlock, $exLeave, $exHolCxl]);

        // 5. Org-level claims (facility NULL): every facility of the tenant
        //    — the `! isPlatform && facilityId() !== null` parity. Only the
        //    org-b row stays invisible.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        $orgRows = $c->select($select.' order by t.exception_date desc', [$t['tenantA']]);
        // exception_date DESC is fully deterministic here — every date is
        // distinct (no tie, unlike the templates read).
        expect(array_map(fn ($r) => $r->id, $orgRows))->toBe([$exBlock, $exLeave, $exA2, $exHolCxl])
            ->and(array_map(fn ($r) => $r->id, $orgRows))->not->toContain($exB);

        // 6. fac-a2 claims: exactly the fac-a2 row.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $facA2,
            'app_branch_id' => $brA2,
            'app_is_platform' => 'false',
        ], $c);
        $fac2Rows = $c->select($selectFacility.' order by t.exception_date desc', [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->id, $fac2Rows))->toBe([$exA2])
            ->and($fac2Rows[0]->staff_id)->toBe($sIcu)
            ->and($fac2Rows[0]->reason)->toBe('leave');

        // 7. Other-tenant claims: exactly the org-b row — the tenantA rows
        //    are invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => $brB,
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->id, $c->select($selectFacility.' order by t.exception_date desc', [$t['tenantB'], $t['facilityB']])))->toBe([$exB])
            ->and($c->select($select, [$t['tenantA']]))->toBe([])
            ->and($c->update('update schedule_exceptions set status = ? where id = ?', ['cancelled', $exLeave]))->toBe(0);

        // 8. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 9. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA']]))->toBe([]);

        // 10. The read mutates nothing — every row is untouched after the
        //     full claims matrix (statuses unchanged).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select status from schedule_exceptions where id = ?', [$exLeave])->status)->toBe('active')
            ->and($c->selectOne('select status from schedule_exceptions where id = ?', [$exHolCxl])->status)->toBe('cancelled')
            ->and($c->selectOne('select reason from schedule_exceptions where id = ?', [$exBlock])->reason)->toBe('block');

        // 11. The composite FK graph is enforced: a duplicate
        //     (tenant_id, staff_id, exception_date) violates the
        //     uq_schedule_exceptions_tenant_staff_date unique index (23505),
        //     and a staff-less row violates the composite (tenant,
        //     facility, staff_id) → staff RESTRICT FK (23503). Each failed
        //     statement is isolated in its own savepoint so the outer
        //     transaction survives (the established Phase 9 pattern).
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-03-05', 'leave', 'active')",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], $sDr]
            );
            expect(true)->toBeFalse('the duplicate (tenant, staff, date) must violate uq_schedule_exceptions_tenant_staff_date');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, exception_date, reason, status) values (?, ?, ?, ?, '2026-05-01', 'leave', 'active')",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], (string) Str::uuid()]
            );
            expect(true)->toBeFalse('the staff-less row must violate the composite (tenant, facility, staff_id) FK');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23503');
            $c->rollBack();
        }
    });
});
it('facilities:settings — the claims-scoped settings read is RLS-gated, key-ordered, and mutation-free (Phase 45)', function () {
    // The exact RLS-scoped settings read facilities:settings runs is proven
    // on the REAL app-role connection (swasthya_app, NOBYPASSRLS), mirroring
    // FacilitySettingsController::index + AccessCheck::facility:
    //  1. the facility gate resolves the facility by id (facilities is
    //     un-scoped; the SCOPE decision — nonexistent vs out-of-scope — is
    //     the app layer, proven at the harness tier);
    //  2. the facility_settings SELECT is visible ONLY under matching
    //     claims — facility_settings is **TENANT_FACILITY** (NOT
    //     TENANT_FACILITY_BRANCH): `tenant_id = TENANT AND (facility_id =
    //     FACILITY OR FACILITY IS NULL)` — there is NO branch clause
    //     (facility_settings has no branch_id column; a branch proposal
    //     does NOT narrow) — an org-level claim (facility NULL) sees the
    //     whole tenant and the VERIFIED-facility binding narrows the read
    //     to exactly that facility (the `! isPlatform && facilityId() !==
    //     null` parity + the exact `->where('facility_id', ...)`);
    //  3. ordering is `key` ascending (the exact `->orderBy('key')`); the
    //     result is the mapWithKeys OBJECT keyed by setting key, each
    //     entry {value, version, updatedAt} — value is the decoded jsonb
    //     payload (the 'array' cast), version the integer counter,
    //     updatedAt formatted exactly like Carbon's toIso8601String
    //     ('YYYY-MM-DDTHH:MM:SS+00:00') or NULL (the `?->` guard); NO
    //     status column exists, NO deleted_at — facility_settings is never
    //     soft-deleted (removing a key is an audited state change) — so
    //     nothing is ever excluded; tenant_id/updated_by/created_at never
    //     leave the read;
    //  4. the read never mutates and forged/missing claims expose zero rows.
    // (The no-audit contract and the 404 classes are proven at the harness
    // tier — the handler tier.)
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        $facA2 = (string) Str::uuid();

        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facA2, $t['tenantA'], 'Fac A2', 'code-set-a2', 'active', 'UTC', '{}', '{}']
        );

        // Five settings across the scope matrix: three fac-a1 rows (one with
        // a NULL updated_at — the nullable-timestamp proof; the versioned
        // jsonb values), a fac-a2 row (facility isolation), and an org-b row
        // (tenant isolation). Keys seeded OUT of alphabetical order — the
        // read must order by key ASC (the exact `->orderBy('key')`). The
        // unique (tenant_id, facility_id, key) index is respected.
        $sA1Buf = (string) Str::uuid();
        $sA1Cur = (string) Str::uuid();
        $sA1Name = (string) Str::uuid();
        $sA2Tok = (string) Str::uuid();
        $sBHrs = (string) Str::uuid();

        $c->insert(
            "insert into facility_settings (id, tenant_id, facility_id, key, value, version, updated_at) values (?, ?, ?, ?, ?, 2, '2026-03-10 08:30:00+00')",
            [$sA1Buf, $t['tenantA'], $t['facilityA'], 'appointment.bufferMinutes', json_encode(['minutes' => 10], JSON_THROW_ON_ERROR)]
        );
        $c->insert(
            'insert into facility_settings (id, tenant_id, facility_id, key, value, version, updated_at) values (?, ?, ?, ?, ?, 1, NULL)',
            [$sA1Cur, $t['tenantA'], $t['facilityA'], 'billing.defaultCurrency', json_encode('NPR', JSON_THROW_ON_ERROR)]
        );
        $c->insert(
            "insert into facility_settings (id, tenant_id, facility_id, key, value, version, updated_at) values (?, ?, ?, ?, ?, 3, '2026-04-01 12:00:00+00')",
            [$sA1Name, $t['tenantA'], $t['facilityA'], 'clinic.name', json_encode(['displayName' => 'Fac A1 Clinic'], JSON_THROW_ON_ERROR)]
        );
        $c->insert(
            "insert into facility_settings (id, tenant_id, facility_id, key, value, version, updated_at) values (?, ?, ?, ?, ?, 1, '2026-02-01 09:00:00+00')",
            [$sA2Tok, $t['tenantA'], $facA2, 'reception.tokens', json_encode(['tokenPrefix' => 'F2'], JSON_THROW_ON_ERROR)]
        );
        $c->insert(
            "insert into facility_settings (id, tenant_id, facility_id, key, value, version, updated_at) values (?, ?, ?, ?, ?, 1, '2026-01-15 07:00:00+00')",
            [$sBHrs, $t['tenantB'], $t['facilityB'], 'pharmacy.hours', json_encode(['open' => '09:00'], JSON_THROW_ON_ERROR)]
        );

        // The exact edge queries: the facility gate (id is a resource
        // selector; facilities is un-scoped — the 404 classes are the app
        // layer) and the facility_settings SELECT (the verified-facility
        // binding — the exact `->where('facility_id', ...)`; NO branch
        // clause — facility_settings is TENANT_FACILITY; NO deleted_at
        // filter — settings are never soft-deleted; only the presented
        // entry is selected — tenant_id/updated_by/created_at never leave).
        $gate = 'select id, tenant_id from public.facilities where id = ? limit 1';
        $select = "select key, value, version,\n                        case when updated_at is null then null\n                             else to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS+00:00')\n                        end as updated_at\n                   from public.facility_settings\n                  where tenant_id = ? and facility_id = ?\n                  order by key asc";

        $claimsFacA1 = [
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => 'br-a1',
            'app_is_platform' => 'false',
        ];
        DatabaseTenantContext::setClaims($claimsFacA1, $c);

        // 1. The facility gate resolves the selector (the facility exists
        //    and its tenant is resolved).
        $fac = $c->selectOne($gate, [$t['facilityA']]);
        expect($fac)->not->toBeNull()
            ->and($fac->tenant_id)->toBe($t['tenantA']);

        // 2. fac-a1 claims: exactly the fac-a1 settings as the keyed map,
        //    ordered by key ASC (the exact Laravel order); the exact entry
        //    projection — the decoded jsonb value, the integer version, the
        //    toIso8601String updatedAt ('+00:00') and the NULL updatedAt for
        //    the never-updated setting; tenant_id/updated_by/created_at
        //    never leave the read.
        $rows = $c->select($select, [$t['tenantA'], $t['facilityA']]);
        expect(array_map(fn ($r) => $r->key, $rows))->toBe(['appointment.bufferMinutes', 'billing.defaultCurrency', 'clinic.name'])
            ->and(json_decode($rows[0]->value, true))->toBe(['minutes' => 10])
            ->and($rows[0]->version)->toBe(2)
            ->and($rows[0]->updated_at)->toBe('2026-03-10T08:30:00+00:00')
            ->and(json_decode($rows[1]->value, true))->toBe('NPR')
            ->and($rows[1]->version)->toBe(1)
            ->and($rows[1]->updated_at)->toBeNull()
            ->and(json_decode($rows[2]->value, true))->toBe(['displayName' => 'Fac A1 Clinic'])
            ->and($rows[2]->version)->toBe(3)
            ->and($rows[2]->updated_at)->toBe('2026-04-01T12:00:00+00:00')
            ->and(property_exists($rows[0], 'tenant_id'))->toBeFalse()
            ->and(property_exists($rows[0], 'updated_by'))->toBeFalse()
            ->and(property_exists($rows[0], 'created_at'))->toBeFalse()
            ->and(property_exists($rows[0], 'id'))->toBeFalse();

        // 3. The fac-a2 and org-b rows are invisible to fac-a1 claims.
        expect(array_map(fn ($r) => $r->key, $rows))->not->toContain('reception.tokens')
            ->and(array_map(fn ($r) => $r->key, $rows))->not->toContain('pharmacy.hours');

        // 4. A branch proposal does NOT narrow the read — facility_settings
        //    is TENANT_FACILITY (no branch clause).
        DatabaseTenantContext::setClaims(array_merge($claimsFacA1, ['app_branch_id' => (string) Str::uuid()]), $c);
        expect(array_map(fn ($r) => $r->key, $c->select($select, [$t['tenantA'], $t['facilityA']])))->toBe(['appointment.bufferMinutes', 'billing.defaultCurrency', 'clinic.name']);

        // 5. Org-level claims (facility NULL): the VERIFIED-facility binding
        //    still narrows the read to the requested facility — the exact
        //    `->where('facility_id', ...)`; only the org-b row stays
        //    invisible (bound to another tenant).
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => '',
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->key, $c->select($select, [$t['tenantA'], $t['facilityA']])))->toBe(['appointment.bufferMinutes', 'billing.defaultCurrency', 'clinic.name']);
        $orgRows = $c->select($select, [$t['tenantA'], $facA2]);
        expect(array_map(fn ($r) => $r->key, $orgRows))->toBe(['reception.tokens'])
            ->and(json_decode($orgRows[0]->value, true))->toBe(['tokenPrefix' => 'F2']);

        // 6. Other-tenant claims: the org-b row visible, the tenantA rows
        //    invisible AND mutation-immune.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect(array_map(fn ($r) => $r->key, $c->select($select, [$t['tenantB'], $t['facilityB']])))->toBe(['pharmacy.hours'])
            ->and($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([])
            ->and($c->update('update facility_settings set version = ? where key = ?', [99, 'clinic.name']))->toBe(0);

        // 7. Forged cross-tenant claims: zero rows, zero mutation.
        DatabaseTenantContext::setClaims([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], $c);
        expect($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([]);

        // 8. Missing claims: fail closed to zero rows.
        DatabaseTenantContext::setClaims([], $c);
        expect($c->select($select, [$t['tenantA'], $t['facilityA']]))->toBe([]);

        // 9. The read mutates nothing — every row is untouched after the
        //    full claims matrix (values + versions unchanged, NULL updated_at
        //    still NULL).
        DatabaseTenantContext::setClaims($claimsFacA1, $c);
        expect($c->selectOne('select value, version from facility_settings where key = ?', ['clinic.name'])->version)->toBe(3)
            ->and(json_decode($c->selectOne('select value from facility_settings where key = ?', ['billing.defaultCurrency'])->value, true))->toBe('NPR')
            ->and($c->selectOne('select updated_at from facility_settings where key = ?', ['billing.defaultCurrency'])->updated_at)->toBeNull();

        // 10. The unique (tenant_id, facility_id, key) index is the write
        //     backstop — a duplicate key for the same facility violates it
        //     (23505); the composite (tenant, facility) → facilities FK is
        //     RESTRICT — a facility-less row violates it (23503). Each failed
        //     statement is isolated in its own savepoint so the outer
        //     transaction survives (the established Phase 9 pattern).
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into facility_settings (id, tenant_id, facility_id, key, value, version) values (?, ?, ?, ?, '{}', 1)",
                [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'clinic.name']
            );
            expect(true)->toBeFalse('the duplicate (tenant, facility, key) must violate the unique index');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
            $c->rollBack();
        }
        $c->beginTransaction();
        try {
            $c->insert(
                "insert into facility_settings (id, tenant_id, facility_id, key, value, version) values (?, ?, ?, 'ghost.key', '{}', 1)",
                [(string) Str::uuid(), $t['tenantA'], (string) Str::uuid()]
            );
            expect(true)->toBeFalse('the facility-less row must violate the composite (tenant, facility) FK');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23503');
            $c->rollBack();
        }
    });
});
