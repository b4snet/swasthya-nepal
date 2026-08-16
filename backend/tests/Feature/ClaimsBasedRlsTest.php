<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2 — Supabase-native RLS verification.
 *
 * These tests connect as the APPLICATION role (swasthya_app — no ownership,
 * no BYPASSRLS) on the dedicated least-privilege connection and set ONLY the
 * Supabase-compatible `request.jwt.claims` GUC — never the legacy `app.*`
 * GUCs — proving the re-keyed policies (2026_08_13_100200) enforce the same
 * isolation guarantees purely from the claims payload.
 *
 * The feature suite runs as the schema owner (RLS bypassed), so this file and
 * DatabaseRowLevelSecurityTest are the only places the policies are actually
 * exercised end-to-end. Every test runs in a transaction on the app-role
 * connection and rolls back in all paths: no fixtures leak.
 */
it('re-keys every RLS policy to the claims helpers (204 policies, zero GUC references)', function () {
    $policies = DB::connection('pgsql')->select(
        <<<'SQL'
        select count(*) as total,
               count(*) filter (where not (qual = 'true' or with_check = 'true')
                                  and (coalesce(qual, '') || coalesce(with_check, '')) not like '%swasthya_rls_%') as not_claims,
               count(*) filter (where (coalesce(qual, '') || coalesce(with_check, '')) like '%current_setting(''app.%') as still_guc
        from pg_policies
        where schemaname = 'public'
        SQL
    )[0];

    expect((int) $policies->total)->toBe(204)
        ->and((int) $policies->not_claims)->toBe(0)
        ->and((int) $policies->still_guc)->toBe(0);

    // The six claim helper functions exist (and are executable by the
    // application role — proven by every test below running as swasthya_app).
    $functions = DB::connection('pgsql')->select(
        "select proname from pg_proc where pronamespace = 'public'::regnamespace and proname like 'swasthya_rls_%' order by 1"
    );

    expect(array_column($functions, 'proname'))->toBe([
        'swasthya_rls_branch_id', 'swasthya_rls_claim', 'swasthya_rls_facility_id',
        'swasthya_rls_is_platform', 'swasthya_rls_tenant_id', 'swasthya_rls_user_id',
    ]);
});

it('keeps the RLS matrix intact: 52 scoped on, 15 off, none on-without-policies', function () {
    $matrix = DB::connection('pgsql')->selectOne(
        <<<'SQL'
        select count(*) filter (where relrowsecurity) as rls_on,
               count(*) filter (where not relrowsecurity) as rls_off,
               count(*) filter (where relrowsecurity and not exists (
                   select 1 from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname
               )) as on_without_policies
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r'
        SQL
    );

    // 67 tables total: 52 tenant-scoped (RLS on, FORCE on) + 15 off. The 15
    // are the framework/identity/public tables: users, roles, permissions,
    // role_permissions, organizations (tenant root — no tenant column to scope
    // by), migrations, jobs, job_batches, failed_jobs, cache, cache_locks,
    // personal_access_tokens, refresh_tokens, mfa_challenges, and
    // password_reset_tokens (the last three are pre-tenant public-route flows).
    // +4 since slice 13: transfer_events, nursing_notes, mar_entries,
    // vital_observations.
    expect((int) $matrix->rls_on)->toBe(52)
        ->and((int) $matrix->rls_off)->toBe(15)
        ->and((int) $matrix->on_without_policies)->toBe(0);
});

it('grants zero access when claims are absent or empty', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-EMPTY', 'Empty Claims', '1990-01-01', 'female', 'active']
        );

        // All-empty claims JSON.
        claimsSet($c, []);
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);

        // Completely absent claims GUC (never set in this transaction).
        $c->statement("select set_config('request.jwt.claims', '', true)");
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
    });
});

it('denies cross-tenant access purely from claims (read, update, delete)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-CA', 'Claims Tenant A', '1990-01-01', 'female', 'active']
        );

        // Tenant A claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();

        // Tenant B claims → invisible; update and delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull()
            ->and($c->update('update patients set status = ? where id = ?', ['merged', $patientA]))->toBe(0)
            ->and($c->delete('delete from patients where id = ?', [$patientA]))->toBe(0);

        // Row untouched.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from patients where id = ?', [$patientA])->status)->toBe('active');
    });
});

it('isolates lab orders from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $test = (string) Str::uuid();
        $order = (string) Str::uuid();
        $item = (string) Str::uuid();

        // Full lab chain in tenant A: staff → patient → encounter → catalog
        // test → order → item (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Pathology', 'pathology', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-LAB', 'Lab Staff', 'Technician', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-LAB', 'Lab Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'open', '2026-08-15 09:00:00+00']);
        $c->insert('insert into lab_tests (id, tenant_id, facility_id, code, name, category, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$test, $t['tenantA'], $t['facilityA'], 'CBC', 'Complete Blood Count', 'laboratory', 'active', 0]);
        $c->insert('insert into lab_orders (id, tenant_id, facility_id, patient_id, encounter_id, ordered_by_staff_id, priority, status, ordered_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$order, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'routine', 'ordered', '2026-08-15 09:10:00+00', 0]);
        $c->insert('insert into lab_order_items (id, tenant_id, facility_id, lab_order_id, lab_test_id) values (?, ?, ?, ?, ?)', [$item, $t['tenantA'], $t['facilityA'], $order, $test]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from lab_orders where id = ?', [$order]))->not->toBeNull()
            ->and($c->selectOne('select id from lab_order_items where id = ?', [$item]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from lab_orders where id = ?', [$order]))->toBeNull()
            ->and($c->update('update lab_orders set status = ? where id = ?', ['reported', $order]))->toBe(0)
            ->and($c->delete('delete from lab_orders where id = ?', [$order]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from lab_orders where id = ?', [$order]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's orders.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from lab_orders where id = ?', [$order]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from lab_orders where id = ?', [$order])->status)->toBe('ordered');
    });
});

it('isolates pharmacy inventory from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $medication = (string) Str::uuid();
        $item = (string) Str::uuid();
        $movement = (string) Str::uuid();

        // Full pharmacy chain in tenant A: medication → inventory item →
        // movement (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$medication, $t['tenantA'], $t['facilityA'], 'PARA', 'Paracetamol', '500mg', 'tablet', 'tab', 500, 'NPR', false, 'active', 0]);
        $c->insert('insert into inventory_items (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$item, $t['tenantA'], $t['facilityA'], $medication, 100, 10, 0]);
        $c->insert('insert into inventory_movements (id, tenant_id, facility_id, inventory_item_id, movement_type, quantity_delta, reason, occurred_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$movement, $t['tenantA'], $t['facilityA'], $item, 'receipt', 100, 'Claims receipt', '2026-08-15 09:00:00+00']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from inventory_items where id = ?', [$item]))->not->toBeNull()
            ->and($c->selectOne('select id from inventory_movements where id = ?', [$movement]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from inventory_items where id = ?', [$item]))->toBeNull()
            ->and($c->update('update inventory_items set reorder_level = ? where id = ?', [20, $item]))->toBe(0)
            ->and($c->delete('delete from inventory_items where id = ?', [$item]))->toBe(0)
            ->and($c->update('update inventory_movements set reason = ? where id = ?', ['pwned', $movement]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from inventory_items where id = ?', [$item]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's inventory.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from inventory_items where id = ?', [$item]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select quantity_on_hand from inventory_items where id = ?', [$item])->quantity_on_hand)->toBe(100)
            ->and($c->selectOne('select movement_type from inventory_movements where id = ?', [$movement])->movement_type)->toBe('receipt');
    });
});

it('isolates follow-up plans from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $followUp = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → follow-up
        // (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-FU', 'Follow-Up Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-FU', 'Follow-Up Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'signed', '2026-08-15 09:00:00+00']);
        $c->insert('insert into follow_ups (id, tenant_id, facility_id, patient_id, encounter_id, provider_staff_id, follow_up_type, planned_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$followUp, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'return_visit', '2026-08-22 09:00:00+00', 'planned', 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from follow_ups where id = ?', [$followUp]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from follow_ups where id = ?', [$followUp]))->toBeNull()
            ->and($c->update('update follow_ups set status = ? where id = ?', ['cancelled', $followUp]))->toBe(0)
            ->and($c->delete('delete from follow_ups where id = ?', [$followUp]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from follow_ups where id = ?', [$followUp]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's plans.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from follow_ups where id = ?', [$followUp]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from follow_ups where id = ?', [$followUp])->status)->toBe('planned');
    });
});

it('isolates refund requests from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $charge = (string) Str::uuid();
        $refundRequest = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → posted
        // charge → refund request (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-RR', 'Refund Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-RR', 'Refund Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'signed', '2026-08-15 09:00:00+00']);
        $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $t['tenantA'], $t['facilityA'], $patient, 'encounter', 'Consultation', 5000, 'NPR', 0, 'posted', '2026-08-15 09:10:00+00']);
        $c->insert('insert into refund_requests (id, tenant_id, facility_id, patient_id, charge_id, amount_minor, reason_code, status, requested_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$refundRequest, $t['tenantA'], $t['facilityA'], $patient, $charge, 2000, 'overcharge', 'requested', $staff, 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from refund_requests where id = ?', [$refundRequest]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from refund_requests where id = ?', [$refundRequest]))->toBeNull()
            ->and($c->update('update refund_requests set status = ? where id = ?', ['approved', $refundRequest]))->toBe(0)
            ->and($c->delete('delete from refund_requests where id = ?', [$refundRequest]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from refund_requests where id = ?', [$refundRequest]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's requests.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from refund_requests where id = ?', [$refundRequest]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from refund_requests where id = ?', [$refundRequest])->status)->toBe('requested');
    });
});

it('isolates admissions from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $admission = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → admission
        // (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'IPD', 'ipd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-ADM', 'Admission Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-ADM', 'Admission Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'ipd', 'open', '2026-08-15 09:00:00+00']);
        $c->insert('insert into admissions (id, tenant_id, facility_id, patient_id, encounter_id, admission_number, admission_type, admitting_diagnosis, admitted_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$admission, $t['tenantA'], $t['facilityA'], $patient, $encounter, 'ADM-001', 'emergency', 'Admitted for observation', '2026-08-15 09:30:00+00', 'admitted', 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from admissions where id = ?', [$admission]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from admissions where id = ?', [$admission]))->toBeNull()
            ->and($c->update('update admissions set status = ? where id = ?', ['discharged', $admission]))->toBe(0)
            ->and($c->delete('delete from admissions where id = ?', [$admission]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from admissions where id = ?', [$admission]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's admissions.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from admissions where id = ?', [$admission]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from admissions where id = ?', [$admission])->status)->toBe('admitted');
    });
});

it('isolates the IPD nursing surface from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $admission = (string) Str::uuid();
        $ward = (string) Str::uuid();
        $room = (string) Str::uuid();
        $bed = (string) Str::uuid();
        $medication = (string) Str::uuid();
        $prescription = (string) Str::uuid();
        $line = (string) Str::uuid();
        $transferEvent = (string) Str::uuid();
        $note = (string) Str::uuid();
        $marEntry = (string) Str::uuid();
        $vital = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → admission
        // (occupying a bed in ward/room) → medication → prescription line →
        // transfer_event / nursing note / MAR entry / vital observation
        // (RLS on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'IPD', 'ipd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-IPD', 'IPD Nurse', 'Staff Nurse', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-IPD', 'IPD Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'ipd', 'open', '2026-08-16 09:00:00+00']);
        $c->insert('insert into admissions (id, tenant_id, facility_id, patient_id, encounter_id, admission_number, admission_type, admitting_diagnosis, admitted_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$admission, $t['tenantA'], $t['facilityA'], $patient, $encounter, 'ADM-IPD', 'emergency', 'Admitted', '2026-08-16 09:30:00+00', 'admitted', 0]);
        $c->insert('insert into wards (id, tenant_id, facility_id, name, code, ward_type, status) values (?, ?, ?, ?, ?, ?, ?)', [$ward, $t['tenantA'], $t['facilityA'], 'IPD Ward', 'ipd-ward', 'general', 'active']);
        $c->insert('insert into rooms (id, tenant_id, facility_id, ward_id, name, code, room_type, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$room, $t['tenantA'], $t['facilityA'], $ward, 'Room 1', 'room-ipd', 'general', 'active']);
        $c->insert('insert into beds (id, tenant_id, facility_id, room_id, bed_code, status, current_admission_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$bed, $t['tenantA'], $t['facilityA'], $room, 'B-IPD', 'occupied', $admission, 1]);
        $c->insert('insert into medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$medication, $t['tenantA'], $t['facilityA'], 'PARA-IPD', 'Paracetamol', '500mg', 'tablet', 'tab', 500, 'NPR', false, 'active']);
        $c->insert('insert into prescriptions (id, tenant_id, patient_id, encounter_id, prescriber_staff_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$prescription, $t['tenantA'], $patient, $encounter, $staff, 'active', 0]);
        $c->insert('insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, line_no, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$line, $t['tenantA'], $prescription, $medication, '1 tab', 'oral', 'tid', 1, 'ordered']);
        $c->insert('insert into transfer_events (id, tenant_id, facility_id, admission_id, from_bed_id, to_bed_id, reason, transferred_by, transferred_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$transferEvent, $t['tenantA'], $t['facilityA'], $admission, $bed, $bed, 'Moved', $staff, '2026-08-16 10:00:00+00']);
        $c->insert('insert into nursing_notes (id, tenant_id, facility_id, admission_id, author_staff_id, content, status) values (?, ?, ?, ?, ?, ?, ?)', [$note, $t['tenantA'], $t['facilityA'], $admission, $staff, '{}', 'draft']);
        $c->insert('insert into mar_entries (id, tenant_id, facility_id, admission_id, prescription_line_id, scheduled_at, status) values (?, ?, ?, ?, ?, ?, ?)', [$marEntry, $t['tenantA'], $t['facilityA'], $admission, $line, '2026-08-16 12:00:00+00', 'scheduled']);
        $c->insert('insert into vital_observations (id, tenant_id, facility_id, admission_id, encounter_id, patient_id, type, value, measured_at, measured_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$vital, $t['tenantA'], $t['facilityA'], $admission, $encounter, $patient, 'bp', '{"systolic": 120, "diastolic": 80}', '2026-08-16 10:30:00+00', $staff]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from transfer_events where id = ?', [$transferEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from nursing_notes where id = ?', [$note]))->not->toBeNull()
            ->and($c->selectOne('select id from mar_entries where id = ?', [$marEntry]))->not->toBeNull()
            ->and($c->selectOne('select id from vital_observations where id = ?', [$vital]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from transfer_events where id = ?', [$transferEvent]))->toBeNull()
            ->and($c->update('update transfer_events set reason = ? where id = ?', ['pwned', $transferEvent]))->toBe(0)
            ->and($c->delete('delete from transfer_events where id = ?', [$transferEvent]))->toBe(0)
            ->and($c->selectOne('select id from nursing_notes where id = ?', [$note]))->toBeNull()
            ->and($c->update('update nursing_notes set status = ? where id = ?', ['signed', $note]))->toBe(0)
            ->and($c->delete('delete from nursing_notes where id = ?', [$note]))->toBe(0)
            ->and($c->selectOne('select id from mar_entries where id = ?', [$marEntry]))->toBeNull()
            ->and($c->update('update mar_entries set status = ? where id = ?', ['given', $marEntry]))->toBe(0)
            ->and($c->delete('delete from mar_entries where id = ?', [$marEntry]))->toBe(0)
            ->and($c->selectOne('select id from vital_observations where id = ?', [$vital]))->toBeNull()
            ->and($c->update('update vital_observations set type = ? where id = ?', ['temp', $vital]))->toBe(0)
            ->and($c->delete('delete from vital_observations where id = ?', [$vital]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from nursing_notes where id = ?', [$note]))->toBeNull()
            ->and($c->selectOne('select id from mar_entries where id = ?', [$marEntry]))->toBeNull()
            ->and($c->selectOne('select id from vital_observations where id = ?', [$vital]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's nursing rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from transfer_events where id = ?', [$transferEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from nursing_notes where id = ?', [$note]))->not->toBeNull()
            ->and($c->selectOne('select id from mar_entries where id = ?', [$marEntry]))->not->toBeNull()
            ->and($c->selectOne('select id from vital_observations where id = ?', [$vital]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from nursing_notes where id = ?', [$note])->status)->toBe('draft')
            ->and($c->selectOne('select status from mar_entries where id = ?', [$marEntry])->status)->toBe('scheduled')
            ->and($c->selectOne('select type from vital_observations where id = ?', [$vital])->type)->toBe('bp')
            ->and($c->selectOne('select reason from transfer_events where id = ?', [$transferEvent])->reason)->toBe('Moved');
    });
});

it('isolates critical-value events from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $labTest = (string) Str::uuid();
        $labOrder = (string) Str::uuid();
        $labItem = (string) Str::uuid();
        $criticalEvent = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → lab order →
        // flagged item → critical-value event (RLS applies on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Lab', 'lab', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-CV', 'Critical Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-CV', 'Critical Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'open', '2026-08-15 09:00:00+00']);
        $c->insert('insert into lab_tests (id, tenant_id, facility_id, code, name, category, status) values (?, ?, ?, ?, ?, ?, ?)', [$labTest, $t['tenantA'], $t['facilityA'], 'CVT', 'Critical Test', 'laboratory', 'active']);
        $c->insert('insert into lab_orders (id, tenant_id, facility_id, patient_id, encounter_id, ordered_by_staff_id, priority, status, ordered_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$labOrder, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'stat', 'results_entered', '2026-08-15 09:10:00+00']);
        $c->insert('insert into lab_order_items (id, tenant_id, facility_id, lab_order_id, lab_test_id, result_value) values (?, ?, ?, ?, ?, ?)', [$labItem, $t['tenantA'], $t['facilityA'], $labOrder, $labTest, '18.9']);
        $c->insert('insert into critical_value_events (id, tenant_id, facility_id, lab_order_item_id, patient_id, encounter_id, target_staff_id, status, detected_by_staff_id, detected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$criticalEvent, $t['tenantA'], $t['facilityA'], $labItem, $patient, $encounter, $staff, 'triggered', $staff, '2026-08-15 09:30:00+00', 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from critical_value_events where id = ?', [$criticalEvent]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from critical_value_events where id = ?', [$criticalEvent]))->toBeNull()
            ->and($c->update('update critical_value_events set status = ? where id = ?', ['acknowledged', $criticalEvent]))->toBe(0)
            ->and($c->delete('delete from critical_value_events where id = ?', [$criticalEvent]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from critical_value_events where id = ?', [$criticalEvent]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's events.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from critical_value_events where id = ?', [$criticalEvent]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from critical_value_events where id = ?', [$criticalEvent])->status)->toBe('triggered');
    });
});

it('isolates pharmacy returns from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $medication = (string) Str::uuid();
        $item = (string) Str::uuid();
        $prescription = (string) Str::uuid();
        $line = (string) Str::uuid();
        $charge = (string) Str::uuid();
        $pharmacyReturn = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → medication →
        // inventory item → prescription → dispensed line → posted charge →
        // return (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Pharmacy', 'pharmacy', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-RET', 'Return Staff', 'Pharmacist', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-RET', 'Return Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'signed', '2026-08-15 09:00:00+00']);
        $c->insert('insert into medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$medication, $t['tenantA'], $t['facilityA'], 'PARA', 'Paracetamol', '500mg', 'tablet', 'tab', 500, 'NPR', false, 'active']);
        $c->insert('insert into inventory_items (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$item, $t['tenantA'], $t['facilityA'], $medication, 100, 10, 0]);
        $c->insert('insert into prescriptions (id, tenant_id, patient_id, encounter_id, prescriber_staff_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$prescription, $t['tenantA'], $patient, $encounter, $staff, 'dispensed', 0]);
        $c->insert('insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, quantity_minor, status, line_no) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$line, $t['tenantA'], $prescription, $medication, '1 tab', 'oral', 'tid', 1, 'dispensed', 1]);
        $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, prescription_id, prescription_line_id, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $t['tenantA'], $t['facilityA'], $patient, 'prescription', $prescription, $line, 'Paracetamol (500mg) × 1', 500, 'NPR', 0, 'posted', '2026-08-15 09:10:00+00']);
        $c->insert('insert into pharmacy_returns (id, tenant_id, facility_id, prescription_line_id, prescription_id, charge_id, quantity_minor, reason_code, returned_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pharmacyReturn, $t['tenantA'], $t['facilityA'], $line, $prescription, $charge, 1, 'patient_return', '2026-08-15 09:30:00+00']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from pharmacy_returns where id = ?', [$pharmacyReturn]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from pharmacy_returns where id = ?', [$pharmacyReturn]))->toBeNull()
            ->and($c->update('update pharmacy_returns set reason_note = ? where id = ?', ['pwned', $pharmacyReturn]))->toBe(0)
            ->and($c->delete('delete from pharmacy_returns where id = ?', [$pharmacyReturn]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from pharmacy_returns where id = ?', [$pharmacyReturn]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's returns.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from pharmacy_returns where id = ?', [$pharmacyReturn]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select reason_code from pharmacy_returns where id = ?', [$pharmacyReturn])->reason_code)->toBe('patient_return');
    });
});

it('isolates follow-up reminder notifications from claims (TENANT tier: tenant-bound, facility-agnostic)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $followUp = (string) Str::uuid();
        $notification = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → follow-up →
        // reminder notification (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-RMD', 'Reminder Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-RMD', 'Reminder Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'open', '2026-08-15 09:00:00+00']);
        $c->insert('insert into follow_ups (id, tenant_id, facility_id, patient_id, encounter_id, provider_staff_id, follow_up_type, planned_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$followUp, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'return_visit', '2026-08-22 09:00:00+00', 'planned', 0]);
        $c->insert('insert into notifications (id, tenant_id, patient_id, follow_up_id, type, channel, payload, status, sensitive) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$notification, $t['tenantA'], $patient, $followUp, 'appointment_reminder', 'in_app', '{}', 'sent', true]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->toBeNull()
            ->and($c->update('update notifications set status = ? where id = ?', ['failed', $notification]))->toBe(0)
            ->and($c->delete('delete from notifications where id = ?', [$notification]))->toBe(0);

        // TENANT tier: the SAME tenant sees the reminder from ANY facility
        // (no facility clause — unlike the TENANT_FACILITY tables).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->not->toBeNull();

        // Org-wide claims (no facility) → still visible (purely tenant-bound).
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from notifications where id = ?', [$notification])->status)->toBe('sent');
    });
});

it('enforces facility isolation from claims within a tenant', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-claims', 'active', 'UTC', '{}', '{}']
        );

        $patient = (string) Str::uuid();
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-FA', 'Facility A', '1985-05-05', 'male', 'active']
        );

        // Facility A claims → visible.
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();

        // Facility A-2 claims (same tenant) → invisible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $facilityA2]);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull();

        // Org-wide claims (no facility) → sees all facilities of the tenant.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();
    });
});

it('enforces branch isolation from claims on branch-scoped catalogs', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $branch1 = (string) Str::uuid();
        $branch2 = (string) Str::uuid();
        $department = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        foreach ([$branch1, $branch2] as $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch, $t['tenantA'], $t['facilityA'], 'Branch '.$branch, 'br-'.substr($branch, 0, 6), 'active']
            );
        }
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], $branch1, 'Cardiology', 'cardiology', 'active']
        );

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_branch_id' => $branch1]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_branch_id' => $branch2]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->toBeNull();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();
    });
});

it('separates platform and tenant audit rows from claims', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $tenantEvent = (string) Str::uuid();
        $platformEvent = (string) Str::uuid();

        $insertEvent = fn (string $id, ?string $tenantId, ?string $facilityId): int => $c->insert(
            'insert into audit_events (id, tenant_id, occurred_at, actor_type, actor_id, actor_email, action, resource_type, resource_id, facility_id, payload, ip_address, correlation_id, prev_hash, event_hash) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, '2026-08-11 00:00:00+00', 'user', null, null, 'test.action', 'test', null, $facilityId, '{}', null, (string) Str::uuid(), null, hash('sha256', $id)]
        );

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        $insertEvent($tenantEvent, $t['tenantA'], $t['facilityA']);

        claimsSet($c, ['app_is_platform' => 'true']);
        $insertEvent($platformEvent, null, null);

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        expect($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->toBeNull();

        claimsSet($c, ['app_is_platform' => 'true']);
        expect($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->toBeNull();

        // Append-only holds from claims context.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        expect($c->update("update audit_events set payload = '{}' where id = ?", [$tenantEvent]))->toBe(0)
            ->and($c->delete('delete from audit_events where id = ?', [$tenantEvent]))->toBe(0);
    });
});

it('lets a principal resolve its own assignments and assigned facilities with only the user claim', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $role = (string) Str::uuid();
        $assignmentA = (string) Str::uuid();

        // A second facility the user is NOT assigned to.
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-user', 'active', 'UTC', '{}', '{}']
        );

        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$userA, 'claims-a@two.test', 'hash', 'active']);
        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$userB, 'claims-b@two.test', 'hash', 'active']);
        $c->insert('insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)', [$role, 'hospital_admin', 'Hospital Admin', 'facility', true]);
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$assignmentA, $userA, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $userB, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );

        // Only the user claim (login path — no tenant/facility claims).
        claimsSet($c, ['app_user_id' => $userA]);
        expect($c->selectOne('select id, user_id from role_assignments where id = ?', [$assignmentA]))->not->toBeNull()
            ->and($c->selectOne('select id, user_id from role_assignments where id = ?', [$assignmentA])->user_id)->toBe($userA);

        // The assigned facility resolves via the authorization join; the
        // unassigned facility in the same tenant does not.
        expect($c->selectOne('select id from facilities where id = ?', [$t['facilityA']]))->not->toBeNull()
            ->and($c->selectOne('select id from facilities where id = ?', [$facilityA2]))->toBeNull();

        // Without any user claim, nothing resolves (safe fail).
        claimsSet($c, []);
        expect($c->selectOne('select id from role_assignments where id = ?', [$assignmentA]))->toBeNull();
    });
});

it('keeps support sessions visible only to their owner or platform claims', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $user = (string) Str::uuid();
        $session = (string) Str::uuid();

        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$user, 'support-claims@two.test', 'hash', 'active']);

        claimsSet($c, ['app_user_id' => $user, 'app_is_platform' => 'true']);
        $c->insert(
            'insert into support_sessions (id, user_id, organization_id, facility_id, reason, status, opened_at, expires_at, correlation_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$session, $user, $t['tenantA'], $t['facilityA'], 'Test support session.', 'active', '2026-08-11 00:00:00+00', '2026-08-11 01:00:00+00', (string) Str::uuid()]
        );

        // A different user in tenant context cannot see it.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_user_id' => (string) Str::uuid(), 'app_is_platform' => 'false']);
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->toBeNull();

        // Platform claims see it.
        claimsSet($c, ['app_is_platform' => 'true']);
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->not->toBeNull();
    });
});

it('forged claims to an unknown tenant grant nothing (no broadening of visibility)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-FORGED', 'Forged', '1990-01-01', 'female', 'active']
        );

        // A forged claim naming a tenant that does not exist resolves to
        // nothing — RLS matches rows by tenant_id equality, so a fabricated
        // identifier can never widen access.
        claimsSet($c, ['app_tenant_id' => (string) Str::uuid(), 'app_facility_id' => $t['facilityA']]);
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);

        // The application role still has no RLS bypass — the claim mechanism
        // is the only way in, and it is server-issued (see class doc).
        $role = DB::connection('pgsql')->selectOne(
            'select rolbypassrls::text as bypass, rolsuper::text as superuser from pg_roles where rolname = ?',
            ['swasthya_app']
        );
        expect($role->bypass)->toBe('false')
            ->and($role->superuser)->toBe('false');
    });
});

it('keeps claims transaction-local — no context leaks across transactions', function () {
    $c = rlsConn();

    // Transaction 1: set claims and verify the row is visible. ROLLED BACK —
    // nothing may survive into the shared test database (fixtures are
    // inserted inside this same transaction, so they vanish with it).
    $c->beginTransaction();
    $t = claimsTenants($c);
    claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
    $c->insert(
        'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
        [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-TXL', 'Tx Local', '1990-01-01', 'female', 'active']
    );
    expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(1);
    $c->rollBack();

    // A NEW transaction on the SAME connection must start with zero context —
    // the LOCAL claims GUC died with the previous transaction. This is the
    // pooled-worker guarantee (TENANCY.md V2 §7) under the claims model.
    $c->beginTransaction();
    try {
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
    } finally {
        $c->rollBack();
    }
});

it('ignores legacy app.* GUCs after the re-key — claims are the only source', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-GUC', 'GUC Only', '1990-01-01', 'female', 'active']
        );

        // Set ONLY the legacy GUCs — no claims. The re-keyed policies must not
        // honor them: a regression guard proving the old source is dead.
        $c->statement('select set_config(?, ?, true)', ['app.tenant_id', $t['tenantA']]);
        $c->statement('select set_config(?, ?, true)', ['app.facility_id', $t['facilityA']]);
        $c->statement("select set_config('request.jwt.claims', '', true)");

        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull();

        // Claims restore visibility.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();
    });
});
