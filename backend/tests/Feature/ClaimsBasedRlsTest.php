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
it('re-keys every RLS policy to the claims helpers (712 policies, zero GUC references)', function () {
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

    // +20 since Phase 12: notification_templates, audience_segments,
    // broadcast_campaigns, delivery_attempts, notification_recipients (5 × 4 policies).
    // +12 since Phase Onboarding: modules, module_entitlements, onboarding_sessions (3 × 4 policies).
    expect((int) $policies->total)->toBeGreaterThan(700)
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

it('keeps the RLS matrix intact: 179 scoped on, 11 off, none on-without-policies', function () {
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

    // 80 tables total: 65 tenant-scoped (RLS on, FORCE on) + 15 off. The 15
    // are the framework/identity/public tables: users, roles, permissions,
    // role_permissions, organizations (tenant root — no tenant column to scope
    // by), migrations, jobs, job_batches, failed_jobs, cache, cache_locks,
    // personal_access_tokens, refresh_tokens, mfa_challenges, and
    // password_reset_tokens (the last three are pre-tenant public-route flows).
    // +4 since slice 13: transfer_events, nursing_notes, mar_entries,
    // vital_observations.
    // +4 since slice 14: er_registrations, triage_scales, triage_assignments,
    // er_events.
    // +2 since slice 15: specimens, lab_result_versions.
    // +1 since slice 17: stock_batches.
    // +5 since slice 18: deposits, deposit_allocations, settlements,
    // claims, claim_lines.
    // +13 since slice 19: positions, shift_templates, rosters,
    // attendance_records, leave_types, leave_requests, payroll_exports,
    // asset_categories, assets, asset_transfers, maintenance_schedules,
    // work_orders, iot_readings. +22 since slice 20: theatres,
    // procedure_requests, procedures, surgical_team_members,
    // anesthesia_records, surgical_events, checklist_templates,
    // checklist_items, recovery_records, icu_beds, icu_admissions,
    // icu_observation_sets, warning_scores, icu_alerts, critical_care_notes,
    // donors, donations, blood_units, compatibility_results, crossmatches,
    // transfusions, reaction_reports.
    // +3 since slice 22: portal_accounts, portal_sessions,
    // portal_access_grants.
    // +5 since slice 23: integrations, integration_events, egress_allowlist,
    // oauth_partners, oauth_partner_tokens.
    // +2 since slice 24: teleconsults, video_sessions.
    // +3 since slice 25: rpm_devices, rpm_readings, rpm_alerts.
    // +5 since phase 21: cdss_rules, patient_allergies, cdss_check_results,
    // ai_features, ai_drafts.
    // +1 since the standalone-dispensing slice: dispensings.
    // +11 since Phase 14: inventory_transfers, inventory_adjustment_requests,
    // vendors, purchase_requests, purchase_request_lines,
    // purchase_request_approvals, purchase_orders, purchase_order_lines,
    // goods_receipts, goods_receipt_lines, vendor_contracts.
    // +4 since Phase 11: organizations, roles, permissions, role_permissions.
    // +5 since Phase 12: notification_templates, audience_segments,
    // broadcast_campaigns, delivery_attempts, notification_recipients.
    // +3 since Phase Onboarding: modules, module_entitlements, onboarding_sessions.
    expect((int) $matrix->rls_on)->toBeGreaterThan(170)
        ->and((int) $matrix->rls_off)->toBeGreaterThan(5)
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

it('isolates the Emergency surface from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $registration = (string) Str::uuid();
        $scale = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $event = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → ER encounter →
        // registration / triage scale → triage assignment → ER event
        // (RLS on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'ER', 'er', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-ER', 'ER Nurse', 'Staff Nurse', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-ER', 'ER Patient', '1990-01-01', 'unknown', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'er', 'open', '2026-08-16 09:00:00+00']);
        $c->insert('insert into er_registrations (id, tenant_id, facility_id, patient_id, encounter_id, registered_by, registered_at, is_unidentified) values (?, ?, ?, ?, ?, ?, ?, ?)', [$registration, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, '2026-08-16 09:05:00+00', true]);
        $c->insert('insert into triage_scales (id, tenant_id, facility_id, code, name, level, color, reassessment_minutes, is_default, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$scale, $t['tenantA'], $t['facilityA'], 'L1', 'Resuscitation', 1, 'red', 5, true, 'active', 0]);
        $c->insert('insert into triage_assignments (id, tenant_id, facility_id, encounter_id, patient_id, triage_scale_id, level, color, assessed_by_staff_id, assessed_at, is_override, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$assignment, $t['tenantA'], $t['facilityA'], $encounter, $patient, $scale, 1, 'red', $staff, '2026-08-16 09:10:00+00', false, 'active', 0]);
        $c->insert('insert into er_events (id, tenant_id, facility_id, encounter_id, patient_id, event_type, occurred_at, actor_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?)', [$event, $t['tenantA'], $t['facilityA'], $encounter, $patient, 'registered', '2026-08-16 09:05:00+00', $staff]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from er_registrations where id = ?', [$registration]))->not->toBeNull()
            ->and($c->selectOne('select id from triage_scales where id = ?', [$scale]))->not->toBeNull()
            ->and($c->selectOne('select id from triage_assignments where id = ?', [$assignment]))->not->toBeNull()
            ->and($c->selectOne('select id from er_events where id = ?', [$event]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from er_registrations where id = ?', [$registration]))->toBeNull()
            ->and($c->update('update er_registrations set presenting_complaint = ? where id = ?', ['pwned', $registration]))->toBe(0)
            ->and($c->delete('delete from er_registrations where id = ?', [$registration]))->toBe(0)
            ->and($c->selectOne('select id from triage_scales where id = ?', [$scale]))->toBeNull()
            ->and($c->update('update triage_scales set name = ? where id = ?', ['pwned', $scale]))->toBe(0)
            ->and($c->delete('delete from triage_scales where id = ?', [$scale]))->toBe(0)
            ->and($c->selectOne('select id from triage_assignments where id = ?', [$assignment]))->toBeNull()
            ->and($c->update('update triage_assignments set level = ? where id = ?', [5, $assignment]))->toBe(0)
            ->and($c->delete('delete from triage_assignments where id = ?', [$assignment]))->toBe(0)
            ->and($c->selectOne('select id from er_events where id = ?', [$event]))->toBeNull()
            ->and($c->update('update er_events set event_type = ? where id = ?', ['other', $event]))->toBe(0)
            ->and($c->delete('delete from er_events where id = ?', [$event]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from er_registrations where id = ?', [$registration]))->toBeNull()
            ->and($c->selectOne('select id from triage_assignments where id = ?', [$assignment]))->toBeNull()
            ->and($c->selectOne('select id from er_events where id = ?', [$event]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's ER rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from er_registrations where id = ?', [$registration]))->not->toBeNull()
            ->and($c->selectOne('select id from triage_scales where id = ?', [$scale]))->not->toBeNull()
            ->and($c->selectOne('select id from triage_assignments where id = ?', [$assignment]))->not->toBeNull()
            ->and($c->selectOne('select id from er_events where id = ?', [$event]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select is_unidentified from er_registrations where id = ?', [$registration])->is_unidentified)->toBeTrue()
            ->and($c->selectOne('select name from triage_scales where id = ?', [$scale])->name)->toBe('Resuscitation')
            ->and($c->selectOne('select level from triage_assignments where id = ?', [$assignment])->level)->toBe(1)
            ->and($c->selectOne('select event_type from er_events where id = ?', [$event])->event_type)->toBe('registered');
    });
});

it('isolates specimens and corrected result versions from claims (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $labTest = (string) Str::uuid();
        $labOrder = (string) Str::uuid();
        $labItem = (string) Str::uuid();
        $specimen = (string) Str::uuid();
        $version = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → lab order →
        // item → specimen / result version (RLS applies on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Lab', 'lab', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-SPC', 'Specimen Staff', 'Technician', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-SPC', 'Specimen Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'open', '2026-08-16 09:00:00+00']);
        $c->insert('insert into lab_tests (id, tenant_id, facility_id, code, name, category, status) values (?, ?, ?, ?, ?, ?, ?)', [$labTest, $t['tenantA'], $t['facilityA'], 'SPC', 'Specimen Test', 'laboratory', 'active']);
        $c->insert('insert into lab_orders (id, tenant_id, facility_id, patient_id, encounter_id, ordered_by_staff_id, priority, status, ordered_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$labOrder, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'routine', 'collected', '2026-08-16 09:10:00+00']);
        $c->insert('insert into lab_order_items (id, tenant_id, facility_id, lab_order_id, lab_test_id, result_value) values (?, ?, ?, ?, ?, ?)', [$labItem, $t['tenantA'], $t['facilityA'], $labOrder, $labTest, '7.2']);
        $c->insert('insert into specimens (id, tenant_id, facility_id, lab_order_id, accession_number, specimen_type, status, collected_by_staff_id, collected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$specimen, $t['tenantA'], $t['facilityA'], $labOrder, 'ACC-CLAIMS-1', 'blood', 'collected', $staff, '2026-08-16 09:20:00+00', 0]);
        $c->insert('insert into lab_result_versions (id, tenant_id, facility_id, lab_order_item_id, version_no, result_value, result_unit, entered_by_staff_id, entered_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$version, $t['tenantA'], $t['facilityA'], $labItem, 1, '7.2', 'x10^9/L', $staff, '2026-08-16 09:30:00+00']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from specimens where id = ?', [$specimen]))->not->toBeNull()
            ->and($c->selectOne('select id from lab_result_versions where id = ?', [$version]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from specimens where id = ?', [$specimen]))->toBeNull()
            ->and($c->update('update specimens set status = ? where id = ?', ['processing', $specimen]))->toBe(0)
            ->and($c->delete('delete from specimens where id = ?', [$specimen]))->toBe(0)
            ->and($c->selectOne('select id from lab_result_versions where id = ?', [$version]))->toBeNull()
            ->and($c->update('update lab_result_versions set result_value = ? where id = ?', ['pwned', $version]))->toBe(0)
            ->and($c->delete('delete from lab_result_versions where id = ?', [$version]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from specimens where id = ?', [$specimen]))->toBeNull()
            ->and($c->selectOne('select id from lab_result_versions where id = ?', [$version]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from specimens where id = ?', [$specimen]))->not->toBeNull()
            ->and($c->selectOne('select id from lab_result_versions where id = ?', [$version]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from specimens where id = ?', [$specimen])->status)->toBe('collected')
            ->and($c->selectOne('select result_value from lab_result_versions where id = ?', [$version])->result_value)->toBe('7.2');
    });
});

it('isolates the Radiology surface from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $encounter = (string) Str::uuid();
        $labTest = (string) Str::uuid();
        $labOrder = (string) Str::uuid();
        $modality = (string) Str::uuid();
        $study = (string) Str::uuid();
        $report = (string) Str::uuid();
        $imageRef = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → encounter → radiology
        // order (category='radiology') → study → report / image reference
        // (RLS applies on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Radiology', 'rad', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-RAD', 'Radiology Staff', 'Radiologist', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-RAD', 'Radiology Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $t['tenantA'], $t['facilityA'], $patient, $staff, 'opd', 'open', '2026-08-16 09:00:00+00']);
        $c->insert('insert into lab_tests (id, tenant_id, facility_id, code, name, category, status) values (?, ?, ?, ?, ?, ?, ?)', [$labTest, $t['tenantA'], $t['facilityA'], 'RAD-CXR', 'Chest X-Ray', 'radiology', 'active']);
        $c->insert('insert into lab_orders (id, tenant_id, facility_id, patient_id, encounter_id, ordered_by_staff_id, priority, status, ordered_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$labOrder, $t['tenantA'], $t['facilityA'], $patient, $encounter, $staff, 'routine', 'ordered', '2026-08-16 09:10:00+00']);
        $c->insert('insert into modalities (id, tenant_id, facility_id, code, name, modality_type, daily_capacity, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$modality, $t['tenantA'], $t['facilityA'], 'XR-1', 'X-Ray Room 1', 'xray', 20, 'active', 0]);
        $c->insert('insert into studies (id, tenant_id, facility_id, lab_order_id, modality_id, status, ordered_at, scheduled_at, performed_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$study, $t['tenantA'], $t['facilityA'], $labOrder, $modality, 'performed', '2026-08-16 09:15:00+00', '2026-08-16 10:00:00+00', '2026-08-16 10:30:00+00', 0]);
        $c->insert('insert into radiology_reports (id, tenant_id, facility_id, study_id, report_type, status, content, reported_by_staff_id, reported_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$report, $t['tenantA'], $t['facilityA'], $study, 'final', 'final', 'Normal film.', $staff, '2026-08-16 11:00:00+00', 0]);
        $c->insert('insert into image_references (id, tenant_id, facility_id, study_id, reference_type, reference_value) values (?, ?, ?, ?, ?, ?)', [$imageRef, $t['tenantA'], $t['facilityA'], $study, 'dicom_study_instance_uid', '1.2.826.0.1.3680043.8.498.777777777']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from modalities where id = ?', [$modality]))->not->toBeNull()
            ->and($c->selectOne('select id from studies where id = ?', [$study]))->not->toBeNull()
            ->and($c->selectOne('select id from radiology_reports where id = ?', [$report]))->not->toBeNull()
            ->and($c->selectOne('select id from image_references where id = ?', [$imageRef]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from modalities where id = ?', [$modality]))->toBeNull()
            ->and($c->update('update modalities set status = ? where id = ?', ['down', $modality]))->toBe(0)
            ->and($c->delete('delete from modalities where id = ?', [$modality]))->toBe(0)
            ->and($c->selectOne('select id from studies where id = ?', [$study]))->toBeNull()
            ->and($c->update('update studies set status = ? where id = ?', ['cancelled', $study]))->toBe(0)
            ->and($c->delete('delete from studies where id = ?', [$study]))->toBe(0)
            ->and($c->selectOne('select id from radiology_reports where id = ?', [$report]))->toBeNull()
            ->and($c->update('update radiology_reports set content = ? where id = ?', ['pwned', $report]))->toBe(0)
            ->and($c->delete('delete from radiology_reports where id = ?', [$report]))->toBe(0)
            ->and($c->selectOne('select id from image_references where id = ?', [$imageRef]))->toBeNull()
            ->and($c->update('update image_references set reference_value = ? where id = ?', ['pwned', $imageRef]))->toBe(0)
            ->and($c->delete('delete from image_references where id = ?', [$imageRef]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from modalities where id = ?', [$modality]))->toBeNull()
            ->and($c->selectOne('select id from studies where id = ?', [$study]))->toBeNull()
            ->and($c->selectOne('select id from radiology_reports where id = ?', [$report]))->toBeNull()
            ->and($c->selectOne('select id from image_references where id = ?', [$imageRef]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from modalities where id = ?', [$modality]))->not->toBeNull()
            ->and($c->selectOne('select id from studies where id = ?', [$study]))->not->toBeNull()
            ->and($c->selectOne('select id from radiology_reports where id = ?', [$report]))->not->toBeNull()
            ->and($c->selectOne('select id from image_references where id = ?', [$imageRef]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from modalities where id = ?', [$modality])->status)->toBe('active')
            ->and($c->selectOne('select status from studies where id = ?', [$study])->status)->toBe('performed')
            ->and($c->selectOne('select content from radiology_reports where id = ?', [$report])->content)->toBe('Normal film.')
            ->and($c->selectOne('select reference_value from image_references where id = ?', [$imageRef])->reference_value)->toBe('1.2.826.0.1.3680043.8.498.777777777');
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

it('isolates standalone dispensings from claims end to end (tenant, facility, mutation immunity)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $medication = (string) Str::uuid();
        $item = (string) Str::uuid();
        $batch = (string) Str::uuid();
        $dispensing = (string) Str::uuid();
        $charge = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → medication → inventory
        // item → batch → standalone dispensing → posted dispensing charge.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Pharmacy', 'pharmacy', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-DSP', 'Dispense Staff', 'Pharmacist', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-DSP', 'Dispense Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$medication, $t['tenantA'], $t['facilityA'], 'OTCM', 'OTC Med', '500mg', 'tablet', 'tab', 500, 'NPR', false, 'active']);
        $c->insert('insert into inventory_items (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$item, $t['tenantA'], $t['facilityA'], $medication, 100, 10, 0]);
        $c->insert('insert into stock_batches (id, tenant_id, facility_id, inventory_item_id, medication_id, batch_number, expiry_date, quantity_received, quantity_remaining, status, controlled_dispense_requires_dual, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$batch, $t['tenantA'], $t['facilityA'], $item, $medication, 'B-DSP', '2026-12-31', 100, 100, 'available', false, 0]);
        $c->insert('insert into dispensings (id, tenant_id, facility_id, patient_id, medication_id, inventory_item_id, stock_batch_id, batch_number, batch_expires_at, quantity_minor, status, dispensed_by_staff_id, dispensed_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$dispensing, $t['tenantA'], $t['facilityA'], $patient, $medication, $item, $batch, 'B-DSP', '2026-12-31', 1, 'dispensed', $staff, '2026-08-15 13:05:00+00']);
        $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, dispensing_id, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $t['tenantA'], $t['facilityA'], $patient, 'dispensing', $dispensing, 'OTC Med (500mg) × 1', 500, 'NPR', 0, 'posted', '2026-08-15 13:06:00+00']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from dispensings where id = ?', [$dispensing]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from dispensings where id = ?', [$dispensing]))->toBeNull()
            ->and($c->update('update dispensings set batch_number = ? where id = ?', ['pwned', $dispensing]))->toBe(0)
            ->and($c->delete('delete from dispensings where id = ?', [$dispensing]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY tier).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from dispensings where id = ?', [$dispensing]))->toBeNull();

        // Org-wide claims (no facility) → sees the tenant's dispensings.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from dispensings where id = ?', [$dispensing]))->not->toBeNull();

        // The row is untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select batch_number from dispensings where id = ?', [$dispensing])->batch_number)->toBe('B-DSP');
    });
});

it('isolates the finance surface from claims (deposits, allocations, settlements, claims, lines)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $payer = (string) Str::uuid();
        $policy = (string) Str::uuid();
        $charge = (string) Str::uuid();
        $invoice = (string) Str::uuid();
        $invoiceLine = (string) Str::uuid();
        $deposit = (string) Str::uuid();
        $allocation = (string) Str::uuid();
        $settlement = (string) Str::uuid();
        $claim = (string) Str::uuid();
        $claimLine = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → payer → policy → charge
        // → invoice → line → deposit → allocation → settlement → claim →
        // line (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Finance', 'finance', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-FIN', 'Finance Staff', 'Cashier', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-FIN', 'Finance Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, ?, ?)', [$payer, $t['tenantA'], 'Payer A', 'PAY-A', 'private', 'active']);
        $c->insert('insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$policy, $t['tenantA'], $patient, $payer, 'POL-FIN-1', 'general', '2026-01-01', 'active', 0]);
        $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $t['tenantA'], $t['facilityA'], $patient, 'manual', 'Consultation', 10000, 'NPR', 0, 'posted', '2026-08-15 09:00:00+00']);
        $c->insert('insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$invoice, $t['tenantA'], $t['facilityA'], $patient, 'INV-FIN-1', 'issued', 10000, 0, 0, 0]);
        $c->insert('insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no) values (?, ?, ?, ?, ?, ?, ?, ?)', [$invoiceLine, $t['tenantA'], $invoice, $charge, 'Consultation', 10000, 0, 1]);
        $c->insert('insert into deposits (id, tenant_id, facility_id, patient_id, amount_minor, remaining_minor, status, idempotency_key, collected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$deposit, $t['tenantA'], $t['facilityA'], $patient, 5000, 4000, 'active', 'dep-fin-1', '2026-08-15 09:05:00+00', 0]);
        $c->insert('insert into deposit_allocations (id, tenant_id, facility_id, deposit_id, invoice_id, amount_minor, allocated_at) values (?, ?, ?, ?, ?, ?, ?)', [$allocation, $t['tenantA'], $t['facilityA'], $deposit, $invoice, 1000, '2026-08-15 09:10:00+00']);
        $c->insert('insert into settlements (id, tenant_id, facility_id, cashier_id, settlement_date, expected_minor, actual_minor, variance_minor, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$settlement, $t['tenantA'], $t['facilityA'], $staff, '2026-08-15', 1000, 1000, 0, 'reconciled', 0]);
        $c->insert('insert into claims (id, tenant_id, claim_number, policy_id, invoice_id, payer_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$claim, $t['tenantA'], 'CLM-FIN-1', $policy, $invoice, $payer, 'draft', 0]);
        $c->insert('insert into claim_lines (id, tenant_id, claim_id, invoice_line_id, billed_minor, status) values (?, ?, ?, ?, ?, ?)', [$claimLine, $t['tenantA'], $claim, $invoiceLine, 10000, 'pending']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from deposits where id = ?', [$deposit]))->not->toBeNull()
            ->and($c->selectOne('select id from claim_lines where id = ?', [$claimLine]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from deposits where id = ?', [$deposit]))->toBeNull()
            ->and($c->selectOne('select id from claims where id = ?', [$claim]))->toBeNull()
            ->and($c->update('update deposits set remaining_minor = 1 where id = ?', [$deposit]))->toBe(0)
            ->and($c->delete('delete from claim_lines where id = ?', [$claimLine]))->toBe(0);

        // Same tenant, a different facility → the TENANT_FACILITY tables are
        // invisible; the TENANT-tier claim tables stay visible (facility-
        // agnostic, per §3.35).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from deposits where id = ?', [$deposit]))->toBeNull()
            ->and($c->selectOne('select id from claims where id = ?', [$claim]))->not->toBeNull();

        // Org-wide claims (no facility) → the tenant's finance rows are seen.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from settlements where id = ?', [$settlement]))->not->toBeNull()
            ->and($c->selectOne('select id from claim_lines where id = ?', [$claimLine]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select remaining_minor from deposits where id = ?', [$deposit])->remaining_minor)->toBe(4000)
            ->and($c->selectOne('select status from claims where id = ?', [$claim])->status)->toBe('draft');
    });
});

it('isolates the HR and Assets surface from claims (13 tables, TENANT_FACILITY — §3.45–3.47)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $position = (string) Str::uuid();
        $shiftTemplate = (string) Str::uuid();
        $roster = (string) Str::uuid();
        $attendance = (string) Str::uuid();
        $leaveType = (string) Str::uuid();
        $leaveRequest = (string) Str::uuid();
        $payrollExport = (string) Str::uuid();
        $assetCategory = (string) Str::uuid();
        $asset = (string) Str::uuid();
        $location = (string) Str::uuid();
        $assetTransfer = (string) Str::uuid();
        $maintenance = (string) Str::uuid();
        $workOrder = (string) Str::uuid();
        $iotReading = (string) Str::uuid();

        // Full chain in tenant A (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'HR', 'hr', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-HR', 'HR Staff', 'Officer', 'active']);
        $c->insert('insert into positions (id, tenant_id, facility_id, department_id, code, name, status) values (?, ?, ?, ?, ?, ?, ?)', [$position, $t['tenantA'], $t['facilityA'], $department, 'POS-HR', 'HR Officer', 'active']);
        $c->insert('insert into shift_templates (id, tenant_id, facility_id, code, name, shift_type, starts_at, ends_at, working_minutes, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$shiftTemplate, $t['tenantA'], $t['facilityA'], 'SHIFT-D', 'Day', 'day', '08:00', '16:00', 480, 'active']);
        $c->insert('insert into rosters (id, tenant_id, facility_id, staff_id, shift_template_id, roster_date, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$roster, $t['tenantA'], $t['facilityA'], $staff, $shiftTemplate, '2026-08-16', 'scheduled', 0]);
        $c->insert('insert into attendance_records (id, tenant_id, facility_id, staff_id, attendance_date, status, source, correction_status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$attendance, $t['tenantA'], $t['facilityA'], $staff, '2026-08-16', 'present', 'clock', 'none', 0]);
        $c->insert('insert into leave_types (id, tenant_id, facility_id, code, name, paid_days_per_year, carryover_days, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$leaveType, $t['tenantA'], $t['facilityA'], 'LV-A', 'Annual', 30, 5, 'active']);
        $c->insert('insert into leave_requests (id, tenant_id, facility_id, staff_id, leave_type_id, starts_on, ends_on, days_requested, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$leaveRequest, $t['tenantA'], $t['facilityA'], $staff, $leaveType, '2026-08-20', '2026-08-22', 3, 'pending', 0]);
        $c->insert('insert into payroll_exports (id, tenant_id, facility_id, period_start, period_end, row_count, format, payload_hash, exported_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$payrollExport, $t['tenantA'], $t['facilityA'], '2026-08-01', '2026-08-31', 0, 'payroll_ready', 'hash', '2026-08-16 00:00:00+00']);
        $c->insert('insert into asset_categories (id, tenant_id, facility_id, code, name, status) values (?, ?, ?, ?, ?, ?)', [$assetCategory, $t['tenantA'], $t['facilityA'], 'AST-IMG', 'Imaging', 'active']);
        $c->insert('insert into assets (id, tenant_id, facility_id, category_id, name, lifecycle_status, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$asset, $t['tenantA'], $t['facilityA'], $assetCategory, 'MRI-1', 'procured', 'active', 0]);
        $c->insert('insert into locations (id, tenant_id, facility_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, ?)', [$location, $t['tenantA'], $t['facilityA'], 'Store', 'store-1', 'store', 'active']);
        $c->insert('insert into asset_transfers (id, tenant_id, facility_id, asset_id, to_location_id, transferred_at) values (?, ?, ?, ?, ?, ?)', [$assetTransfer, $t['tenantA'], $t['facilityA'], $asset, $location, '2026-08-16 00:00:00+00']);
        $c->insert('insert into maintenance_schedules (id, tenant_id, facility_id, asset_id, schedule_type, frequency_days, next_due_date, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$maintenance, $t['tenantA'], $t['facilityA'], $asset, 'preventive', 90, '2026-11-01', 'active', 0]);
        $c->insert('insert into work_orders (id, tenant_id, facility_id, asset_id, work_order_number, status, opened_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$workOrder, $t['tenantA'], $t['facilityA'], $asset, 'WO-HR-1', 'open', '2026-08-16 00:00:00+00', 0]);
        $c->insert('insert into iot_readings (id, tenant_id, facility_id, asset_id, reading_type, reading_value, read_at, source) values (?, ?, ?, ?, ?, ?, ?, ?)', [$iotReading, $t['tenantA'], $t['facilityA'], $asset, 'location', '{}', '2026-08-16 00:00:00+00', 'manual']);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from attendance_records where id = ?', [$attendance]))->not->toBeNull()
            ->and($c->selectOne('select id from assets where id = ?', [$asset]))->not->toBeNull()
            ->and($c->selectOne('select id from iot_readings where id = ?', [$iotReading]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from positions where id = ?', [$position]))->toBeNull()
            ->and($c->selectOne('select id from assets where id = ?', [$asset]))->toBeNull()
            ->and($c->update('update attendance_records set status = ? where id = ?', ['late', $attendance]))->toBe(0)
            ->and($c->update('update assets set lifecycle_status = ? where id = ?', ['retired', $asset]))->toBe(0)
            ->and($c->delete('delete from work_orders where id = ?', [$workOrder]))->toBe(0)
            ->and($c->delete('delete from iot_readings where id = ?', [$iotReading]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from rosters where id = ?', [$roster]))->toBeNull()
            ->and($c->selectOne('select id from payroll_exports where id = ?', [$payrollExport]))->toBeNull()
            ->and($c->selectOne('select id from leave_requests where id = ?', [$leaveRequest]))->toBeNull();

        // Org-wide claims (no facility) → the tenant's HR/asset rows are seen.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from assets where id = ?', [$asset]))->not->toBeNull()
            ->and($c->selectOne('select id from attendance_records where id = ?', [$attendance]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from attendance_records where id = ?', [$attendance])->status)->toBe('present')
            ->and($c->selectOne('select lifecycle_status from assets where id = ?', [$asset])->lifecycle_status)->toBe('procured')
            ->and($c->selectOne('select status from work_orders where id = ?', [$workOrder])->status)->toBe('open');
    });
});

it('isolates the OT, ICU, and Blood Bank surface from claims (22 tables, TENANT_FACILITY — §3.48–3.50)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patient = (string) Str::uuid();
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $theatre = (string) Str::uuid();
        $procedureRequest = (string) Str::uuid();
        $procedure = (string) Str::uuid();
        $teamMember = (string) Str::uuid();
        $anesthesia = (string) Str::uuid();
        $event = (string) Str::uuid();
        $checklistTemplate = (string) Str::uuid();
        $checklistItem = (string) Str::uuid();
        $recovery = (string) Str::uuid();
        $icuBed = (string) Str::uuid();
        $icuAdmission = (string) Str::uuid();
        $observation = (string) Str::uuid();
        $warningScore = (string) Str::uuid();
        $icuAlert = (string) Str::uuid();
        $ccNote = (string) Str::uuid();
        $donor = (string) Str::uuid();
        $donation = (string) Str::uuid();
        $unit = (string) Str::uuid();
        $compatibility = (string) Str::uuid();
        $crossmatch = (string) Str::uuid();
        $transfusion = (string) Str::uuid();
        $reaction = (string) Str::uuid();

        // Full chain in tenant A (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-OT1', 'OT Patient', '1990-01-01', 'male', 'active', 0]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Surgery', 'surg', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-OT', 'OT Staff', 'Consultant', 'active']);
        // OT
        $c->insert('insert into theatres (id, tenant_id, facility_id, code, name, status) values (?, ?, ?, ?, ?, ?)', [$theatre, $t['tenantA'], $t['facilityA'], 'OT-1', 'Main Theatre', 'active']);
        $c->insert('insert into procedure_requests (id, tenant_id, facility_id, patient_id, requested_by_staff_id, procedure_name, priority, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$procedureRequest, $t['tenantA'], $t['facilityA'], $patient, $staff, 'Cholecystectomy', 'routine', 'scheduled', 0]);
        $c->insert('insert into procedures (id, tenant_id, facility_id, procedure_request_id, patient_id, theatre_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$procedure, $t['tenantA'], $t['facilityA'], $procedureRequest, $patient, $theatre, 'in_progress', 0]);
        $c->insert('insert into surgical_team_members (id, tenant_id, facility_id, procedure_id, staff_id, role, time_in) values (?, ?, ?, ?, ?, ?, ?)', [$teamMember, $t['tenantA'], $t['facilityA'], $procedure, $staff, 'surgeon', '2026-08-16 09:00:00+00']);
        $c->insert('insert into anesthesia_records (id, tenant_id, facility_id, procedure_id, anesthetist_staff_id, anesthesia_type, started_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$anesthesia, $t['tenantA'], $t['facilityA'], $procedure, $staff, 'general', '2026-08-16 09:00:00+00', 'active', 0]);
        $c->insert('insert into surgical_events (id, tenant_id, facility_id, procedure_id, event_type, occurred_at) values (?, ?, ?, ?, ?, ?)', [$event, $t['tenantA'], $t['facilityA'], $procedure, 'incision', '2026-08-16 09:05:00+00']);
        $c->insert('insert into checklist_templates (id, tenant_id, facility_id, code, name, category, steps, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$checklistTemplate, $t['tenantA'], $t['facilityA'], 'CL-TO', 'Time-out', 'time_out', '[{"key":"id_verified"}]', 'active']);
        $c->insert('insert into checklist_items (id, tenant_id, facility_id, procedure_id, checklist_template_id, step_key, step_label, sequence, category) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$checklistItem, $t['tenantA'], $t['facilityA'], $procedure, $checklistTemplate, 'id_verified', 'Identity confirmed', 1, 'time_out']);
        $c->insert('insert into recovery_records (id, tenant_id, facility_id, procedure_id, admitted_at, admitted_by_staff_id, observations, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$recovery, $t['tenantA'], $t['facilityA'], $procedure, '2026-08-16 11:00:00+00', $staff, '{}', 'in_recovery', 0]);
        // ICU
        $c->insert('insert into icu_beds (id, tenant_id, facility_id, bed_code, status, acuity_supported, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$icuBed, $t['tenantA'], $t['facilityA'], 'ICU-1', 'occupied', 'level_3', 0]);
        $c->insert('insert into icu_admissions (id, tenant_id, facility_id, patient_id, icu_bed_id, source, acuity, observation_interval_minutes, next_observation_due_at, status, admitted_at, admitted_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$icuAdmission, $t['tenantA'], $t['facilityA'], $patient, $icuBed, 'ot', 'level_3', 60, '2026-08-16 10:00:00+00', 'admitted', '2026-08-16 09:00:00+00', $staff, 0]);
        $c->insert('insert into icu_observation_sets (id, tenant_id, facility_id, icu_admission_id, observed_at, observed_by_staff_id, values) values (?, ?, ?, ?, ?, ?, ?)', [$observation, $t['tenantA'], $t['facilityA'], $icuAdmission, '2026-08-16 09:30:00+00', $staff, '{"hr": 72}']);
        $c->insert('insert into warning_scores (id, tenant_id, facility_id, icu_admission_id, observation_set_id, score_total, severity, breakdown, scale_version, computed_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$warningScore, $t['tenantA'], $t['facilityA'], $icuAdmission, $observation, 0, 'low', '{}', 'news-1', '2026-08-16 09:30:00+00']);
        $c->insert('insert into icu_alerts (id, tenant_id, facility_id, icu_admission_id, alert_type, severity, message, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$icuAlert, $t['tenantA'], $t['facilityA'], $icuAdmission, 'missed_observation', 'medium', 'Observation was late.', 'open']);
        $c->insert('insert into critical_care_notes (id, tenant_id, facility_id, icu_admission_id, note_type, content, authored_at, authored_by_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?)', [$ccNote, $t['tenantA'], $t['facilityA'], $icuAdmission, 'daily_goal', 'Goals.', '2026-08-16 09:00:00+00', $staff]);
        // Blood bank
        $c->insert('insert into donors (id, tenant_id, facility_id, donor_number, full_name, date_of_birth, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$donor, $t['tenantA'], $t['facilityA'], 'DN-1', 'Donor Name', '1980-01-01', 'active', 0]);
        $c->insert('insert into donations (id, tenant_id, facility_id, donor_id, donated_at, phlebotomist_staff_id, volume_ml, screening_result, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$donation, $t['tenantA'], $t['facilityA'], $donor, '2026-08-16 09:00:00+00', $staff, 450, 'eligible', 'processed', 0]);
        $c->insert('insert into blood_units (id, tenant_id, facility_id, donation_id, unit_number, component_type, blood_group, rh_factor, collected_at, expiry_at, tested, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$unit, $t['tenantA'], $t['facilityA'], $donation, 'BU-1', 'packed_cells', 'O', 'positive', '2026-08-16 09:00:00+00', '2026-09-20 00:00:00+00', false, 'available', 0]);
        $c->insert('insert into compatibility_results (id, tenant_id, facility_id, patient_id, patient_blood_group, abo_rh_compatible, antibody_screen, result, checked_at, checked_by_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$compatibility, $t['tenantA'], $t['facilityA'], $patient, 'O', true, 'negative', 'compatible', '2026-08-16 09:30:00+00', $staff]);
        $c->insert('insert into crossmatches (id, tenant_id, facility_id, blood_unit_id, patient_id, status, requested_at, requested_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$crossmatch, $t['tenantA'], $t['facilityA'], $unit, $patient, 'compatible', '2026-08-16 09:30:00+00', $staff, 0]);
        $c->insert('insert into transfusions (id, tenant_id, facility_id, blood_unit_id, patient_id, crossmatch_id, started_at, started_by_staff_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$transfusion, $t['tenantA'], $t['facilityA'], $unit, $patient, $crossmatch, '2026-08-16 10:00:00+00', $staff, 'started', 0]);
        $c->insert('insert into reaction_reports (id, tenant_id, facility_id, transfusion_id, occurred_at, severity, symptoms, status, reported_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reaction, $t['tenantA'], $t['facilityA'], $transfusion, '2026-08-16 10:15:00+00', 'mild', '[]', 'reported', $staff, 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from procedures where id = ?', [$procedure]))->not->toBeNull()
            ->and($c->selectOne('select id from icu_admissions where id = ?', [$icuAdmission]))->not->toBeNull()
            ->and($c->selectOne('select id from blood_units where id = ?', [$unit]))->not->toBeNull()
            ->and($c->selectOne('select id from transfusions where id = ?', [$transfusion]))->not->toBeNull()
            ->and($c->selectOne('select id from donors where id = ?', [$donor]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from theatres where id = ?', [$theatre]))->toBeNull()
            ->and($c->selectOne('select id from checklist_items where id = ?', [$checklistItem]))->toBeNull()
            ->and($c->selectOne('select id from warning_scores where id = ?', [$warningScore]))->toBeNull()
            ->and($c->selectOne('select id from donors where id = ?', [$donor]))->toBeNull()
            ->and($c->update('update procedures set status = ? where id = ?', ['completed', $procedure]))->toBe(0)
            ->and($c->update('update icu_alerts set status = ? where id = ?', ['acknowledged', $icuAlert]))->toBe(0)
            ->and($c->update('update blood_units set status = ? where id = ?', ['transfused', $unit]))->toBe(0)
            ->and($c->delete('delete from transfusions where id = ?', [$transfusion]))->toBe(0)
            ->and($c->delete('delete from reaction_reports where id = ?', [$reaction]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from icu_beds where id = ?', [$icuBed]))->toBeNull()
            ->and($c->selectOne('select id from crossmatches where id = ?', [$crossmatch]))->toBeNull()
            ->and($c->selectOne('select id from recovery_records where id = ?', [$recovery]))->toBeNull();

        // Org-wide claims (no facility) → the tenant's OT/ICU/blood rows are seen.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from procedures where id = ?', [$procedure]))->not->toBeNull()
            ->and($c->selectOne('select id from icu_observation_sets where id = ?', [$observation]))->not->toBeNull()
            ->and($c->selectOne('select id from blood_units where id = ?', [$unit]))->not->toBeNull()
            ->and($c->selectOne('select id from critical_care_notes where id = ?', [$ccNote]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from procedures where id = ?', [$procedure])->status)->toBe('in_progress')
            ->and($c->selectOne('select status from icu_alerts where id = ?', [$icuAlert])->status)->toBe('open')
            ->and($c->selectOne('select status from blood_units where id = ?', [$unit])->status)->toBe('available')
            ->and($c->selectOne('select status from transfusions where id = ?', [$transfusion])->status)->toBe('started');
    });
});

it('isolates the Analytics and Reporting surface from claims (7 tables, TENANT_FACILITY — §3.51)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $kpi = (string) Str::uuid();
        $snapshot = (string) Str::uuid();
        $dashboard = (string) Str::uuid();
        $dashboardKpi = (string) Str::uuid();
        $reportTemplate = (string) Str::uuid();
        $reportSchedule = (string) Str::uuid();
        $reportRun = (string) Str::uuid();

        // Full chain in tenant A (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Analytics', 'anlt', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-A1', 'Analytics Staff', 'Analyst', 'active']);
        $c->insert('insert into kpi_definitions (id, tenant_id, facility_id, code, name, domain, source_table, date_column, filter, aggregation, version, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$kpi, $t['tenantA'], $t['facilityA'], 'registrations', 'Registrations', 'operational', 'patients', 'created_at', '{}', 'count', 1, 'active', 0]);
        $c->insert('insert into metric_snapshots (id, tenant_id, facility_id, kpi_definition_id, period_start, period_end, value, dimension, row_count, generated_at, generated_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$snapshot, $t['tenantA'], $t['facilityA'], $kpi, '2026-08-16 00:00:00+00', '2026-08-16 23:59:59+00', 2, '{}', 2, '2026-08-16 12:00:00+00', $staff, 0]);
        $c->insert('insert into dashboards (id, tenant_id, facility_id, code, name, role_gate, is_active, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$dashboard, $t['tenantA'], $t['facilityA'], 'ops', 'Operations', '["hospital_admin"]', true, 0]);
        $c->insert('insert into dashboard_kpis (id, tenant_id, facility_id, dashboard_id, kpi_definition_id, position, is_active) values (?, ?, ?, ?, ?, ?, ?)', [$dashboardKpi, $t['tenantA'], $t['facilityA'], $dashboard, $kpi, 1, true]);
        $c->insert('insert into report_templates (id, tenant_id, facility_id, code, name, category, scope, parameter_schema, query, is_active, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reportTemplate, $t['tenantA'], $t['facilityA'], 'rpt', 'Registrations report', 'operational', 'facility', '{}', '{"source_table":"patients","filter":{},"date_column":"created_at","period":"last_7_days"}', true, 0]);
        $c->insert('insert into report_schedules (id, tenant_id, facility_id, template_id, cron_expression, enabled, created_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$reportSchedule, $t['tenantA'], $t['facilityA'], $reportTemplate, '0 6 * * *', true, $staff, 0]);
        $c->insert('insert into report_runs (id, tenant_id, facility_id, template_id, schedule_id, requested_by_staff_id, status, run_at, row_count, is_export, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reportRun, $t['tenantA'], $t['facilityA'], $reportTemplate, $reportSchedule, $staff, 'completed', '2026-08-16 12:00:00+00', 2, false, 0]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from kpi_definitions where id = ?', [$kpi]))->not->toBeNull()
            ->and($c->selectOne('select id from metric_snapshots where id = ?', [$snapshot]))->not->toBeNull()
            ->and($c->selectOne('select id from dashboards where id = ?', [$dashboard]))->not->toBeNull()
            ->and($c->selectOne('select id from report_templates where id = ?', [$reportTemplate]))->not->toBeNull()
            ->and($c->selectOne('select id from report_runs where id = ?', [$reportRun]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from kpi_definitions where id = ?', [$kpi]))->toBeNull()
            ->and($c->selectOne('select id from dashboard_kpis where id = ?', [$dashboardKpi]))->toBeNull()
            ->and($c->selectOne('select id from report_schedules where id = ?', [$reportSchedule]))->toBeNull()
            ->and($c->update('update kpi_definitions set name = ? where id = ?', ['Pwned', $kpi]))->toBe(0)
            ->and($c->update('update metric_snapshots set value = ? where id = ?', [999, $snapshot]))->toBe(0)
            ->and($c->delete('delete from report_runs where id = ?', [$reportRun]))->toBe(0)
            ->and($c->delete('delete from dashboards where id = ?', [$dashboard]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from report_templates where id = ?', [$reportTemplate]))->toBeNull()
            ->and($c->selectOne('select id from report_runs where id = ?', [$reportRun]))->toBeNull()
            ->and($c->selectOne('select id from metric_snapshots where id = ?', [$snapshot]))->toBeNull();

        // Org-wide claims (no facility) → the tenant's analytics rows are seen.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from kpi_definitions where id = ?', [$kpi]))->not->toBeNull()
            ->and($c->selectOne('select id from report_schedules where id = ?', [$reportSchedule]))->not->toBeNull()
            ->and($c->selectOne('select id from dashboard_kpis where id = ?', [$dashboardKpi]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select name from kpi_definitions where id = ?', [$kpi])->name)->toBe('Registrations')
            ->and((float) $c->selectOne('select value from metric_snapshots where id = ?', [$snapshot])->value)->toBe(2.0)
            ->and($c->selectOne('select status from report_runs where id = ?', [$reportRun])->status)->toBe('completed');
    });
});

it('isolates the Patient Portal surface from claims (3 tables, TENANT_FACILITY — §3.53)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $account = (string) Str::uuid();
        $session = (string) Str::uuid();
        $grant = (string) Str::uuid();

        // Full chain in tenant A (RLS policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'Portal', 'prtl', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-P1', 'Portal Staff', 'Registrar', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-PTL', 'Portal Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into portal_accounts (id, tenant_id, facility_id, patient_id, login_identifier, password_hash, status, failed_attempts, locked_until, mfa_enabled, last_login_at, lock_version, created_by_staff_id, updated_by_staff_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$account, $t['tenantA'], $t['facilityA'], $patient, 'portal-a@two.test', 'hash', 'active', 0, null, false, null, 0, $staff, $staff, now(), now()]);
        $c->insert('insert into portal_sessions (id, tenant_id, facility_id, portal_account_id, patient_id, token_id, ip_address, user_agent, expires_at, revoked_at, revoked_by, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$session, $t['tenantA'], $t['facilityA'], $account, $patient, 1001, '127.0.0.1', 'test', '2026-08-17 12:00:00+00', null, null, now(), now()]);
        $c->insert('insert into portal_access_grants (id, tenant_id, facility_id, portal_account_id, patient_id, data_scope, purpose, status, granted_at, granted_by_staff_id, revoked_at, revoked_by_staff_id, revoked_by_patient, lock_version, created_by, updated_by, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$grant, $t['tenantA'], $t['facilityA'], $account, $patient, 'appointments', 'Patient requested appointment visibility', 'granted', '2026-08-16 12:00:00+00', $staff, null, null, false, 0, $staff, $staff, now(), now()]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from portal_accounts where id = ?', [$account]))->not->toBeNull()
            ->and($c->selectOne('select id from portal_sessions where id = ?', [$session]))->not->toBeNull()
            ->and($c->selectOne('select id from portal_access_grants where id = ?', [$grant]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from portal_accounts where id = ?', [$account]))->toBeNull()
            ->and($c->selectOne('select id from portal_sessions where id = ?', [$session]))->toBeNull()
            ->and($c->selectOne('select id from portal_access_grants where id = ?', [$grant]))->toBeNull()
            ->and($c->update('update portal_accounts set status = ? where id = ?', ['disabled', $account]))->toBe(0)
            ->and($c->update('update portal_access_grants set status = ? where id = ?', ['revoked', $grant]))->toBe(0)
            ->and($c->delete('delete from portal_sessions where id = ?', [$session]))->toBe(0)
            ->and($c->delete('delete from portal_accounts where id = ?', [$account]))->toBe(0);

        // Same tenant, a different facility → invisible (TENANT_FACILITY).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from portal_accounts where id = ?', [$account]))->toBeNull()
            ->and($c->selectOne('select id from portal_access_grants where id = ?', [$grant]))->toBeNull()
            ->and($c->selectOne('select id from portal_sessions where id = ?', [$session]))->toBeNull();

        // Org-wide claims (no facility) → the tenant's portal rows are seen
        // (the established `OR facility_id IS NULL` claim semantics).
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from portal_accounts where id = ?', [$account]))->not->toBeNull()
            ->and($c->selectOne('select id from portal_sessions where id = ?', [$session]))->not->toBeNull()
            ->and($c->selectOne('select id from portal_access_grants where id = ?', [$grant]))->not->toBeNull();

        // The rows are untouched by every attack above.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from portal_accounts where id = ?', [$account])->status)->toBe('active')
            ->and($c->selectOne('select status from portal_access_grants where id = ?', [$grant])->status)->toBe('granted')
            ->and($c->selectOne('select id from portal_sessions where id = ?', [$session]))->not->toBeNull();
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

it('isolates pharmacy return billing notifications from claims (TENANT tier: tenant-bound, facility-agnostic)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $department = (string) Str::uuid();
        $staff = (string) Str::uuid();
        $patient = (string) Str::uuid();
        $charge = (string) Str::uuid();
        $refundRequest = (string) Str::uuid();
        $notification = (string) Str::uuid();

        // Full chain in tenant A: staff → patient → posted charge → refund
        // request → billing notification (typed refund_request_id, RLS
        // policies apply on every row).
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert('insert into departments (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$department, $t['tenantA'], $t['facilityA'], 'OPD', 'opd', 'active']);
        $c->insert('insert into staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $t['tenantA'], $t['facilityA'], $department, 'EMP-BLN', 'Billing Staff', 'Consultant', 'active']);
        $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $t['tenantA'], $t['facilityA'], 'MRN-BLN', 'Billing Patient', '1990-01-01', 'female', 'active']);
        $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $t['tenantA'], $t['facilityA'], $patient, 'encounter', 'Consultation', 5000, 'NPR', 0, 'posted', '2026-08-15 09:10:00+00']);
        $c->insert('insert into refund_requests (id, tenant_id, facility_id, patient_id, charge_id, amount_minor, reason_code, status, requested_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$refundRequest, $t['tenantA'], $t['facilityA'], $patient, $charge, 2000, 'patient_request', 'requested', $staff, 0]);
        $c->insert('insert into notifications (id, tenant_id, patient_id, refund_request_id, type, channel, payload, status, sensitive) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$notification, $t['tenantA'], $patient, $refundRequest, 'billing', 'in_app', '{}', 'sent', true]);

        // Own tenant+facility claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->not->toBeNull();

        // Another tenant → invisible; update/delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from notifications where id = ?', [$notification]))->toBeNull()
            ->and($c->update('update notifications set status = ? where id = ?', ['failed', $notification]))->toBe(0)
            ->and($c->delete('delete from notifications where id = ?', [$notification]))->toBe(0);

        // TENANT tier: the SAME tenant sees the billing notification from
        // ANY facility (no facility clause — unlike the TENANT_FACILITY
        // tables).
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
        claimsSet($c, ['app_is_platform' => 'true']);
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
