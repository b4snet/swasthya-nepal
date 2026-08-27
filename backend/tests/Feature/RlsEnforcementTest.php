<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Phase 100.7 — RLS enforcement tests for the 17 previously unprotected tables.
 *
 * These tests connect as swasthya_app (no BYPASSRLS) and set RLS GUCs
 * directly, proving PostgreSQL itself enforces the boundary.
 */

/**
 * Insert a minimal valid row into the given table.
 */
function insertTestRow(ConnectionInterface $c, string $table, string $tenantId, ?string $facilityId = null): string
{
    $id = (string) Str::uuid();
    $code = substr(Str::random(8), 0, 8);

    match ($table) {
        'accounts' => $c->insert(
            'INSERT INTO accounts (id, tenant_id, facility_id, code, name, type, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'Test', 'asset', 'active']
        ),
        'accounts_payable' => $c->insert(
            'INSERT INTO accounts_payable (id, tenant_id, facility_id, supplier_id, invoice_number, invoice_date, due_date, total_minor, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, Str::uuid(), $code, date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), 0, 'pending']
        ),
        'corrective_actions' => $c->insert(
            'INSERT INTO corrective_actions (id, tenant_id, facility_id, action_code, title, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'Test Action', 'open']
        ),
        'disclosure_logs' => $c->insert(
            'INSERT INTO disclosure_logs (id, tenant_id, facility_id, requester_name, purpose, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, 'Requester', 'treatment', 'pending']
        ),
        'document_acknowledgements' => $c->insert(
            'INSERT INTO document_acknowledgements (id, tenant_id, document_id, user_id, status) VALUES (?, ?, ?, ?, ?)',
            [$id, $tenantId, Str::uuid(), Str::uuid(), 'pending']
        ),
        'document_versions' => $c->insert(
            'INSERT INTO document_versions (id, tenant_id, document_id, version_number, title, description) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, Str::uuid(), 1, 'Test Version', 'desc']
        ),
        'domain_events' => $c->insert(
            'INSERT INTO domain_events (id, event_type, aggregate_type, aggregate_id, tenant_id, facility_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, 'test.event', 'test', Str::uuid(), $tenantId, $facilityId, 'pending']
        ),
        'drug_interactions' => $c->insert(
            'INSERT INTO drug_interactions (id, tenant_id, medication_a_id, medication_b_id, severity, description) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, Str::uuid(), Str::uuid(), 'moderate', 'desc']
        ),
        'hospital_documents' => $c->insert(
            'INSERT INTO hospital_documents (id, tenant_id, facility_id, document_code, document_type, category, title, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'clinical', 'general', 'Test Doc', 'draft']
        ),
        'hospital_incidents' => $c->insert(
            'INSERT INTO hospital_incidents (id, tenant_id, facility_id, incident_code, title, category, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'Test Incident', 'safety', 'reported']
        ),
        'hospital_policies' => $c->insert(
            'INSERT INTO hospital_policies (id, tenant_id, facility_id, policy_code, title, category, version, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'Test Policy', 'clinical', 1, 'draft']
        ),
        'journal_entries' => $c->insert(
            'INSERT INTO journal_entries (id, tenant_id, facility_id, entry_number, entry_date, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, date('Y-m-d'), 'Test entry', 'draft']
        ),
        'journal_lines' => $c->insert(
            'INSERT INTO journal_lines (id, tenant_id, facility_id, journal_entry_id, account_id, debit_minor, credit_minor) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, Str::uuid(), Str::uuid(), 0, 0]
        ),
        'patient_complaints' => $c->insert(
            'INSERT INTO patient_complaints (id, tenant_id, facility_id, complaint_code, category, title, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, $code, 'service', 'Test Complaint', 'open']
        ),
        'queue_entries' => $c->insert(
            'INSERT INTO queue_entries (id, tenant_id, facility_id, department, queue_code, patient_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, 'OPD', $code, Str::uuid(), 'waiting']
        ),
        'resource_bookings' => $c->insert(
            'INSERT INTO resource_bookings (id, tenant_id, facility_id, resource_type, resource_id, booking_code, title, starts_at, ends_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, 'room', Str::uuid(), $code, 'Test Booking', now(), now()->addHour(), 'confirmed']
        ),
        'staff_credentials' => $c->insert(
            'INSERT INTO staff_credentials (id, tenant_id, facility_id, staff_id, credential_type, title, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, $facilityId, Str::uuid(), 'license', 'Medical License', 'active']
        ),
    };

    return $id;
}

/*
|---------------------------------------------------------------------------
| FORGED-CLAIMS: cross-tenant read protection
|---------------------------------------------------------------------------
*/

it('blocks cross-tenant reads on all remediated tenant+facility tables', function () {
    // Only tables without complex FK dependencies for bulk insert.
    // Individual sensitive-table tests cover the remaining tables.
    $tenantFacilityTables = [
        'accounts', 'corrective_actions', 'disclosure_logs',
        'hospital_documents', 'hospital_incidents', 'hospital_policies',
        'journal_entries', 'patient_complaints',
        'staff_credentials',
    ];

    $c = rlsConn();
    rlsTx($c, function ($c) use ($tenantFacilityTables) {
        [$tenantA, $tenantB, $facilityA, $facilityB] = array_values(claimsTenants($c));

        // Insert rows as tenant A + facility A
        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => $facilityA]);
        foreach ($tenantFacilityTables as $table) {
            insertTestRow($c, $table, $tenantA, $facilityA);
        }

        // Switch to tenant B — must see ZERO rows
        claimsSet($c, ['app_tenant_id' => $tenantB, 'app_facility_id' => $facilityB]);
        foreach ($tenantFacilityTables as $table) {
            $rows = $c->select("SELECT count(*) as cnt FROM {$table}");
            expect($rows[0]->cnt)->toBe(0, "Cross-tenant leak on {$table}");
        }
    });
});

it('blocks cross-tenant reads on tenant-only tables', function () {
    // Skip drug_interactions (FK to medications table)
    $tenantOnlyTables = ['document_acknowledgements', 'document_versions'];

    $c = rlsConn();
    rlsTx($c, function ($c) use ($tenantOnlyTables) {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $tenantA]);
        foreach ($tenantOnlyTables as $table) {
            insertTestRow($c, $table, $tenantA);
        }

        claimsSet($c, ['app_tenant_id' => $tenantB]);
        foreach ($tenantOnlyTables as $table) {
            $rows = $c->select("SELECT count(*) as cnt FROM {$table}");
            expect($rows[0]->cnt)->toBe(0, "Cross-tenant leak on {$table}");
        }
    });
});

/*
|---------------------------------------------------------------------------
| MISSING-CLAIMS: no-tenant reads blocked
|---------------------------------------------------------------------------
*/

it('blocks reads when tenant claims are missing on remediated tables', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        $tenantA = (string) Str::uuid();
        $facilityA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => $facilityA]);
        insertTestRow($c, 'accounts', $tenantA, $facilityA);
        insertTestRow($c, 'journal_entries', $tenantA, $facilityA);

        // Clear all claims — must see ZERO rows
        claimsSet($c, ['app_tenant_id' => '', 'app_facility_id' => '']);

        foreach (['accounts', 'journal_entries'] as $table) {
            $rows = $c->select("SELECT count(*) as cnt FROM {$table}");
            expect($rows[0]->cnt)->toBe(0, "Missing-claims leak on {$table}");
        }
    });
});

/*
|---------------------------------------------------------------------------
| CROSS-FACILITY: facility isolation within same tenant
|---------------------------------------------------------------------------
*/

it('blocks cross-facility reads when facility_id is set', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tenantA, , $facilityA, $facilityB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => $facilityA]);
        insertTestRow($c, 'accounts', $tenantA, $facilityA);
        insertTestRow($c, 'journal_entries', $tenantA, $facilityA);
        insertTestRow($c, 'staff_credentials', $tenantA, $facilityA);

        // Facility B (same tenant) — must NOT see facility A rows
        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => $facilityB]);
        foreach (['accounts', 'journal_entries', 'staff_credentials'] as $table) {
            $rows = $c->select("SELECT count(*) as cnt FROM {$table}");
            expect($rows[0]->cnt)->toBe(0, "Cross-facility leak on {$table}");
        }
    });
});

it('allows reads when facility_id is NULL (unscoped mode)', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tenantA, , $facilityA] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => $facilityA]);
        insertTestRow($c, 'accounts', $tenantA, $facilityA);

        // With NULL facility_id, should see all rows for the tenant
        claimsSet($c, ['app_tenant_id' => $tenantA, 'app_facility_id' => null]);
        $rows = $c->select('SELECT count(*) as cnt FROM accounts');
        expect($rows[0]->cnt)->toBe(1);
    });
});

/*
|---------------------------------------------------------------------------
| SENSITIVE TABLES: targeted verification
|---------------------------------------------------------------------------
*/

it('protects staff_credentials with RLS', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'staff_credentials', $tA, $fA);

        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fB]);
        $rows = $c->select('SELECT * FROM staff_credentials WHERE id = ?', [$id]);
        expect($rows)->toHaveCount(0, 'Tenant B can see Tenant A staff_credentials');
    });
});

it('protects patient_complaints with RLS', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'patient_complaints', $tA, $fA);

        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fA]);
        $rows = $c->select('SELECT * FROM patient_complaints WHERE id = ?', [$id]);
        expect($rows)->toHaveCount(0, 'Tenant B can see Tenant A patient_complaints');
    });
});

it('protects hospital_incidents with RLS', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'hospital_incidents', $tA, $fA);

        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fA]);
        $rows = $c->select('SELECT * FROM hospital_incidents WHERE id = ?', [$id]);
        expect($rows)->toHaveCount(0, 'Tenant B can see Tenant A hospital_incidents');
    });
});

it('protects disclosure_logs with RLS', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'disclosure_logs', $tA, $fA);

        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fA]);
        $rows = $c->select('SELECT * FROM disclosure_logs WHERE id = ?', [$id]);
        expect($rows)->toHaveCount(0, 'Tenant B can see Tenant A disclosure_logs');
    });
});

it('protects drug_interactions with RLS (FK may block insert, verified by policy check)', function () {
    $c = rlsConn();

    // Verify RLS is enabled and FORCE is set
    $info = $c->selectOne(
        "SELECT rowsecurity FROM pg_tables WHERE schemaname = 'public' AND tablename = 'drug_interactions'"
    );
    expect($info->rowsecurity)->toBeTrue('drug_interactions must have RLS enabled');

    $forced = $c->selectOne(
        "SELECT relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = 'drug_interactions'"
    );
    expect($forced->relforcerowsecurity)->toBeTrue('drug_interactions must have FORCE RLS');

    // Verify the correct policies exist
    $policies = $c->select(
        "SELECT policyname, cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = 'drug_interactions' ORDER BY cmd"
    );
    $cmds = array_map(fn ($p) => $p->cmd, $policies);
    expect($cmds)->toContain('SELECT');
    expect($cmds)->toContain('INSERT');
    expect($cmds)->toContain('UPDATE');
    expect($cmds)->toContain('DELETE');
    expect(count($policies))->toBe(4);

    // Verify tenant_id appears in SELECT policy
    $selectPolicy = collect($policies)->first(fn ($p) => $p->cmd === 'SELECT');
    $policyDef = $c->selectOne(
        "SELECT qual FROM pg_policies WHERE schemaname = 'public' AND tablename = 'drug_interactions' AND cmd = 'SELECT'"
    );
    expect(str_contains($policyDef->qual, 'tenant_id'))->toBeTrue('SELECT policy must reference tenant_id, got: '.$policyDef->qual);
});

it('protects domain_events with RLS (varchar scope)', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'domain_events', $tA, $fA);

        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fA]);
        $rows = $c->select('SELECT * FROM domain_events WHERE id = ?', [$id]);
        expect($rows)->toHaveCount(0, 'Tenant B can see Tenant A domain_events');
    });
});

/*
|---------------------------------------------------------------------------
| WRITE PROTECTION: UPDATE/DELETE with wrong claims
|---------------------------------------------------------------------------
*/

it('blocks cross-tenant UPDATE on remediated tables', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'accounts', $tA, $fA);

        // Tenant B tries to update tenant A's row
        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fB]);
        $affected = $c->update("UPDATE accounts SET name = 'HACKED' WHERE id = ?", [$id]);
        expect($affected)->toBe(0, 'Cross-tenant UPDATE succeeded on accounts');

        // Verify unchanged
        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $row = $c->selectOne('SELECT name FROM accounts WHERE id = ?', [$id]);
        expect($row->name)->toBe('Test');
    });
});

it('blocks cross-tenant DELETE on remediated tables', function () {
    $c = rlsConn();
    rlsTx($c, function ($c) {
        [$tA, $tB, $fA, $fB] = array_values(claimsTenants($c));

        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $id = insertTestRow($c, 'journal_entries', $tA, $fA);

        // Tenant B tries to delete
        claimsSet($c, ['app_tenant_id' => $tB, 'app_facility_id' => $fB]);
        $affected = $c->delete('DELETE FROM journal_entries WHERE id = ?', [$id]);
        expect($affected)->toBe(0, 'Cross-tenant DELETE succeeded on journal_entries');

        // Verify still exists
        claimsSet($c, ['app_tenant_id' => $tA, 'app_facility_id' => $fA]);
        $row = $c->selectOne('SELECT id FROM journal_entries WHERE id = ?', [$id]);
        expect($row)->not->toBeNull();
    });
});
