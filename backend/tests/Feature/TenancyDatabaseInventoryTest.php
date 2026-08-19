<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * PROGRAM PHASE 1 — systematic database-layer tenancy verification.
 *
 * Unlike the representative isolation suites (DatabaseRowLevelSecurityTest,
 * ClinicalIsolationTest, ...), this suite iterates the FULL set of 103
 * tenant-owned tables: it seeds a complete two-tenant fixture chain and then
 * probes every table for cross-tenant SELECT/UPDATE/DELETE isolation as the
 * least-privilege `swasthya_app` role (NOBYPASSRLS) under transaction-local
 * claims. It also records the current RLS inventory (enabled/forced/policies)
 * and the deliberately permissive INSERT boundary, so any change to the
 * policy matrix becomes a visible, reviewed regression.
 *
 * The 128-table set is the RLS-on matrix (ClaimsBasedRlsTest asserts 128
 * scoped + 15 unscoped); the 128 here must match it exactly.
 */

/**
 * The 128 tables with RLS enabled (the documented scoped set).
 *
 * @var list<string>
 */
const RLS_SCOPED_TABLES = [
    // TENANT_FACILITY_BRANCH
    'departments', 'locations', 'wards', 'rooms', 'beds',
    // TENANT_FACILITY
    'staff', 'services', 'facility_settings', 'schedule_templates',
    'schedule_exceptions', 'appointments', 'token_counters', 'encounters',
    'medications', 'charges', 'invoices', 'payments', 'branches', 'patients',
    // Phase 3 slice 2 — laboratory & radiology order lifecycle.
    'lab_tests', 'lab_orders', 'lab_order_items',
    // Phase 3 slice 15 — specimen custody + corrected result versions.
    'specimens', 'lab_result_versions',
    // Phase 3 slice 16 — Radiology: modality catalog, studies, reports,
    // and DICOM references.
    'modalities', 'studies', 'radiology_reports', 'image_references',
    // Phase 3 slice 3 — pharmacy dispensing & inventory.
    'inventory_items', 'inventory_movements',
    // Phase 3 slice 17 — pharmacy batch/expiry tracking.
    'stock_batches',
    // Phase 3 slice 8 — pharmacy returns & reversals.
    'pharmacy_returns',
    // Phase 3 — standalone dispensing records (dispensing without a
    // prescription; §3.30).
    'dispensings',
    // Phase 14 — inventory & procurement (DATABASE.md §3.31–3.32):
    // inter-facility transfers, approval-gated adjustments, and the
    // procurement chain (vendors, requests + approvals, orders, GRNs,
    // contracts).
    'inventory_transfers', 'inventory_adjustment_requests',
    'vendors', 'purchase_requests', 'purchase_request_lines',
    'purchase_request_approvals', 'purchase_orders', 'purchase_order_lines',
    'goods_receipts', 'goods_receipt_lines', 'vendor_contracts',
    // Phase 3 slice 4 — discharge & follow-up.
    'follow_ups',
    // Phase 3 slice 5 — billing refunds & adjustments.
    'refund_requests',
    // Phase 3 slice 18 — deposits, daily settlements, insurance claims
    // (claims/claim_lines are TENANT tier — no facility_id, §3.35).
    'deposits', 'deposit_allocations', 'settlements',
    'claims', 'claim_lines',
    // Phase 3 slice 6 — IPD admission/discharge with bed release.
    'admissions',
    // Phase 3 slice 7 — laboratory critical-value escalation.
    'critical_value_events',
    // Phase 3 slice 13 — the remaining documented IPD workflow: audited
    // transfers, nursing notes, MAR administration, vital observations.
    'transfer_events', 'nursing_notes', 'mar_entries', 'vital_observations',
    // Phase 3 slice 14 — Emergency: minimal-data registration, configurable
    // triage, time-stamped ER events.
    'er_registrations', 'triage_scales', 'triage_assignments', 'er_events',
    // Phase 3 slice 10 — follow-up reminders (TENANT tier, §3.37).
    'notifications',
    // Phase 3 slice 19 — HR (positions, shift templates, rosters,
    // attendance, leave types + requests, payroll exports) and Assets
    // (asset categories, assets, transfers, maintenance schedules, work
    // orders, iot readings) — DATABASE.md §3.45–3.47.
    'positions', 'shift_templates', 'rosters', 'attendance_records',
    'leave_types', 'leave_requests', 'payroll_exports',
    'asset_categories', 'assets', 'asset_transfers',
    'maintenance_schedules', 'work_orders', 'iot_readings',
    // Phase 3 slice 20 — OT (theatres, procedure requests, procedures,
    // team, anesthesia, events, checklists, recovery), ICU (beds,
    // admissions, observations, scores, alerts, notes), and Blood Bank
    // (donors, donations, units, compatibility, crossmatch, transfusions,
    // reactions) — DATABASE.md §3.48–3.50.
    'theatres', 'procedure_requests', 'procedures', 'surgical_team_members',
    'anesthesia_records', 'surgical_events', 'checklist_templates',
    'checklist_items', 'recovery_records',
    'icu_beds', 'icu_admissions', 'icu_observation_sets', 'warning_scores',
    'icu_alerts', 'critical_care_notes',
    'donors', 'donations', 'blood_units', 'compatibility_results',
    'crossmatches', 'transfusions', 'reaction_reports',
    // Phase 3 slice 21 — Analytics and Reporting (DATABASE.md §3.51):
    // versioned KPI definitions, observed metric snapshots, dashboards +
    // composition, and the audited report template/schedule/run surface.
    'kpi_definitions', 'metric_snapshots', 'dashboards', 'dashboard_kpis',
    'report_templates', 'report_schedules', 'report_runs',
    // Phase 3 slice 22 — Patient Portal (DATABASE.md §3.53): portal
    // identities, append-only session logs, and consent-bound grants.
    'portal_accounts', 'portal_sessions', 'portal_access_grants',
    // Phase 3 slice 23 — Interoperability readiness (DATABASE.md §3.54,
    // INTEROPERABILITY.md §13–14): the integration registry, measured-status
    // events, the egress allowlist, and the OAuth2 partner/token pair.
    'integrations', 'integration_events', 'egress_allowlist',
    'oauth_partners', 'oauth_partner_tokens',
    // Phase 3 slice 24 — Telehealth (DATABASE.md §3.55,
    // PRODUCT_REQUIREMENTS §6.20): the virtual-consultation record and its
    // secure video-session metadata (consent-gated, recording-policy-bound).
    'teleconsults', 'video_sessions',
    // Phase 3 slice 25 — RPM (DATABASE.md §3.56, ROADMAP Phase 20): device
    // adapters, validated/labeled readings (append-only), and
    // human-mediated threshold alerts.
    'rpm_devices', 'rpm_readings', 'rpm_alerts',
    // Phase 21 — CDSS/AI (DATABASE.md §3.57, AI_RULES.md §19): the versioned
    // knowledge base, documented patient allergies, persisted check results,
    // the AI feature registry, and grounded assistive drafts (sign-off-only).
    'cdss_rules', 'patient_allergies', 'cdss_check_results',
    'ai_features', 'ai_drafts',
    // TENANT_ONLY
    'payers', 'mrn_counters', 'patient_identifiers', 'patient_contacts',
    'insurance_policies', 'patient_documents', 'consents',
    'patient_timeline_entries', 'diagnoses', 'clinical_notes',
    'prescriptions', 'prescription_lines', 'invoice_lines',
    'payment_allocations',
    // Phase 12 — National Mass Notification Platform (DATABASE.md §3.58):
    // notification templates, audience segments, broadcast campaigns,
    // delivery attempts, and recipient tracking.
    'notification_templates', 'audience_segments', 'broadcast_campaigns',
    'delivery_attempts', 'notification_recipients',
    // special policies
    'facilities', 'audit_events', 'role_assignments', 'support_sessions',
];

/**
 * The 15 tables deliberately NOT RLS-scoped (identity/root/framework).
 *
 * @var list<string>
 */
const RLS_UNSCOPED_TABLES = [
    'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
    'migrations', 'personal_access_tokens',
    'refresh_tokens',
    'users',
    // Pre-tenant public-route flows (Phase 2): hash-only payloads, same
    // pattern as refresh_tokens.
    'mfa_challenges', 'password_reset_tokens',
];

/**
 * The 4 RBAC/org tables scoped in Phase 11 (organizations, roles,
 * permissions, role_permissions). These now have RLS + FORCE enabled.
 *
 * @var list<string>
 */
const RLS_RBAC_SCOPED_TABLES = [
    'organizations', 'roles', 'permissions', 'role_permissions',
];

/**
 * Insert the complete tenant-owned row chain for one tenant on the app-role
 * connection. Returns a table → id map.
 *
 * @return array<string, string>
 */
function seedTenantChain(ConnectionInterface $c, string $tenantId, string $facilityId): array
{
    $ids = [];
    $u = fn (string $prefix): string => $prefix.'-'.substr((string) Str::uuid(), 0, 8);

    rlsSet($c, 'tenant_id', $tenantId);
    rlsSet($c, 'facility_id', $facilityId);
    rlsSet($c, 'branch_id', '');
    rlsSet($c, 'user_id', '');
    rlsSet($c, 'is_platform', 'false');

    // Identity rows needed for FKs (users/roles are NOT RLS-scoped).
    $user = (string) Str::uuid();
    $role = (string) Str::uuid();
    $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$user, $u('user').'@chain.test', 'hash', 'active']);
    $c->insert('insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)', [$role, 'hospital_admin_'.substr((string) Str::uuid(), 0, 8), 'Hospital Admin', 'facility', true]);
    $ids['users'] = $user;

    $branch = (string) Str::uuid();
    $c->insert('insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)', [$branch, $tenantId, $facilityId, 'Branch', $u('br'), 'active']);
    $ids['branches'] = $branch;

    $department = (string) Str::uuid();
    $c->insert('insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)', [$department, $tenantId, $facilityId, $branch, 'Cardiology', $u('dep'), 'active']);
    $ids['departments'] = $department;

    $location = (string) Str::uuid();
    $c->insert('insert into locations (id, tenant_id, facility_id, branch_id, name, code, type, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$location, $tenantId, $facilityId, $branch, 'Waiting Area', $u('loc'), 'waiting_area', 'active']);
    $ids['locations'] = $location;

    $ward = (string) Str::uuid();
    $c->insert('insert into wards (id, tenant_id, facility_id, branch_id, name, code, ward_type, status, settings) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$ward, $tenantId, $facilityId, $branch, 'Ward 1', $u('ward'), 'general', 'active', '{}']);
    $ids['wards'] = $ward;

    $room = (string) Str::uuid();
    $c->insert('insert into rooms (id, tenant_id, facility_id, ward_id, branch_id, name, code, room_type, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$room, $tenantId, $facilityId, $ward, $branch, 'Room 101', $u('rm'), 'general', 'active']);
    $ids['rooms'] = $room;

    $bed = (string) Str::uuid();
    $c->insert('insert into beds (id, tenant_id, facility_id, room_id, branch_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$bed, $tenantId, $facilityId, $room, $branch, $u('bed'), 'available', 0]);
    $ids['beds'] = $bed;

    $staff = (string) Str::uuid();
    $c->insert('insert into staff (id, tenant_id, facility_id, department_id, user_id, employee_code, full_name, designation, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$staff, $tenantId, $facilityId, $department, $user, $u('emp'), 'Dr Chain', 'Consultant', 'active']);
    $ids['staff'] = $staff;

    $assignment = (string) Str::uuid();
    $c->insert('insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$assignment, $user, $role, $tenantId, $facilityId, null, 'facility', 'active', '2026-08-11 00:00:00+00']);
    $ids['role_assignments'] = $assignment;

    $patient = (string) Str::uuid();
    $c->insert('insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$patient, $tenantId, $facilityId, $u('mrn'), 'Chain Patient', '1990-01-01', 'female', 'active', 0]);
    $ids['patients'] = $patient;

    $identifier = (string) Str::uuid();
    $c->insert('insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, status) values (?, ?, ?, ?, ?, ?, ?)', [$identifier, $tenantId, $patient, 'national_id', 'enc', 'hash', 'active']);
    $ids['patient_identifiers'] = $identifier;

    $contact = (string) Str::uuid();
    $c->insert('insert into patient_contacts (id, tenant_id, patient_id, type, value, is_primary, status) values (?, ?, ?, ?, ?, ?, ?)', [$contact, $tenantId, $patient, 'phone', '9800000000', false, 'active']);
    $ids['patient_contacts'] = $contact;

    $payer = (string) Str::uuid();
    $c->insert('insert into payers (id, tenant_id, name, code, payer_type, status) values (?, ?, ?, ?, ?, ?)', [$payer, $tenantId, 'Chain Payer', $u('pay'), 'private', 'active']);
    $ids['payers'] = $payer;

    $policy = (string) Str::uuid();
    $c->insert('insert into insurance_policies (id, tenant_id, patient_id, payer_id, policy_number, coverage_type, valid_from, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$policy, $tenantId, $patient, $payer, $u('pol'), 'general', '2026-01-01', 'active', 0]);
    $ids['insurance_policies'] = $policy;

    $document = (string) Str::uuid();
    $c->insert('insert into patient_documents (id, tenant_id, patient_id, document_type, status) values (?, ?, ?, ?, ?)', [$document, $tenantId, $patient, 'other', 'staged']);
    $ids['patient_documents'] = $document;

    $consent = (string) Str::uuid();
    $c->insert('insert into consents (id, tenant_id, patient_id, consent_type, version, given_at, status, scope) values (?, ?, ?, ?, ?, ?, ?, ?)', [$consent, $tenantId, $patient, 'treatment', 1, '2026-08-11 00:00:00+00', 'active', '{}']);
    $ids['consents'] = $consent;

    $timeline = (string) Str::uuid();
    $c->insert('insert into patient_timeline_entries (id, tenant_id, patient_id, occurred_at, event_type, summary) values (?, ?, ?, ?, ?, ?)', [$timeline, $tenantId, $patient, '2026-08-11 00:00:00+00', 'registered', '{}']);
    $ids['patient_timeline_entries'] = $timeline;

    $c->insert('insert into mrn_counters (tenant_id, last_value) values (?, ?)', [$tenantId, 0]);
    $ids['mrn_counters'] = $tenantId; // mrn_counters has NO id column — keyed by tenant_id.

    $service = (string) Str::uuid();
    $c->insert('insert into services (id, tenant_id, facility_id, name, code, service_type, status) values (?, ?, ?, ?, ?, ?, ?)', [$service, $tenantId, $facilityId, 'Consultation', $u('svc'), 'opd_consultation', 'active']);
    $ids['services'] = $service;

    $medication = (string) Str::uuid();
    $c->insert('insert into medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$medication, $tenantId, $facilityId, $u('med'), 'Paracetamol', '500mg', 'tablet', 'tab', 500, 'NPR', false, 'active', 0]);
    $ids['medications'] = $medication;

    $setting = (string) Str::uuid();
    $c->insert('insert into facility_settings (id, tenant_id, facility_id, key, value, version) values (?, ?, ?, ?, ?, ?)', [$setting, $tenantId, $facilityId, 'chain.key', '{}', 1]);
    $ids['facility_settings'] = $setting;

    $template = (string) Str::uuid();
    $c->insert('insert into schedule_templates (id, tenant_id, facility_id, staff_id, service_id, day_of_week, starts_at, ends_at, slot_minutes, capacity, valid_from, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$template, $tenantId, $facilityId, $staff, $service, 1, '09:00', '17:00', 15, 1, '2026-01-01', 'active']);
    $ids['schedule_templates'] = $template;

    $exception = (string) Str::uuid();
    $c->insert('insert into schedule_exceptions (id, tenant_id, facility_id, staff_id, template_id, exception_date, reason, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$exception, $tenantId, $facilityId, $staff, $template, '2026-08-15', 'leave', 'active']);
    $ids['schedule_exceptions'] = $exception;

    $appointment = (string) Str::uuid();
    $c->insert('insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, service_id, appointment_type, starts_at, ends_at, status, source, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$appointment, $tenantId, $facilityId, $patient, $staff, $service, 'opd', '2026-08-15 10:00:00+00', '2026-08-15 10:15:00+00', 'booked', 'counter', 0]);
    $ids['appointments'] = $appointment;

    $counter = (string) Str::uuid();
    $c->insert('insert into token_counters (id, tenant_id, facility_id, provider_staff_id, queue_date, last_token) values (?, ?, ?, ?, ?, ?)', [$counter, $tenantId, $facilityId, $staff, '2026-08-15', 0]);
    $ids['token_counters'] = $counter;

    $encounter = (string) Str::uuid();
    $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id, type, status, started_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$encounter, $tenantId, $facilityId, $patient, $appointment, $staff, 'opd', 'open', '2026-08-15 10:15:00+00', 0]);
    $ids['encounters'] = $encounter;

    $diagnosis = (string) Str::uuid();
    $c->insert('insert into diagnoses (id, tenant_id, encounter_id, description, diagnosis_type, is_primary, status) values (?, ?, ?, ?, ?, ?, ?)', [$diagnosis, $tenantId, $encounter, 'Hypertension', 'provisional', true, 'active']);
    $ids['diagnoses'] = $diagnosis;

    $note = (string) Str::uuid();
    $c->insert('insert into clinical_notes (id, tenant_id, encounter_id, author_staff_id, note_type, content, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$note, $tenantId, $encounter, $staff, 'consultation', '{}', 'draft', 0]);
    $ids['clinical_notes'] = $note;

    $prescription = (string) Str::uuid();
    $c->insert('insert into prescriptions (id, tenant_id, patient_id, encounter_id, prescriber_staff_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$prescription, $tenantId, $patient, $encounter, $staff, 'drafted', 0]);
    $ids['prescriptions'] = $prescription;

    $line = (string) Str::uuid();
    $c->insert('insert into prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency, line_no, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$line, $tenantId, $prescription, $medication, '1 tab', 'oral', 'OD', 1, 'ordered']);
    $ids['prescription_lines'] = $line;

    $charge = (string) Str::uuid();
    $c->insert('insert into charges (id, tenant_id, facility_id, patient_id, source_type, description, amount_minor, currency, tax_rate_bps, status, charged_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$charge, $tenantId, $facilityId, $patient, 'manual', 'Consultation', 5000, 'NPR', 0, 'posted', '2026-08-15 10:30:00+00']);
    $ids['charges'] = $charge;

    $refundRequest = (string) Str::uuid();
    $c->insert('insert into refund_requests (id, tenant_id, facility_id, patient_id, charge_id, amount_minor, reason_code, status, requested_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$refundRequest, $tenantId, $facilityId, $patient, $charge, 1000, 'overcharge', 'requested', $user, 0]);
    $ids['refund_requests'] = $refundRequest;

    $invoice = (string) Str::uuid();
    $c->insert('insert into invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor, total_tax_minor, paid_minor, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$invoice, $tenantId, $facilityId, $patient, $u('inv'), 'draft', 5000, 0, 0, 0]);
    $ids['invoices'] = $invoice;

    $invoiceLine = (string) Str::uuid();
    $c->insert('insert into invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no) values (?, ?, ?, ?, ?, ?, ?, ?)', [$invoiceLine, $tenantId, $invoice, $charge, 'Consultation', 5000, 0, 1]);
    $ids['invoice_lines'] = $invoiceLine;

    $payment = (string) Str::uuid();
    $c->insert('insert into payments (id, tenant_id, facility_id, method, amount_minor, currency, status, idempotency_key, received_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$payment, $tenantId, $facilityId, 'cash', 5000, 'NPR', 'captured', $u('idem'), '2026-08-15 11:00:00+00']);
    $ids['payments'] = $payment;

    $allocation = (string) Str::uuid();
    $c->insert('insert into payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at) values (?, ?, ?, ?, ?, ?)', [$allocation, $tenantId, $payment, $invoice, 5000, '2026-08-15 11:00:00+00']);
    $ids['payment_allocations'] = $allocation;

    // Phase 3 slice 18 — finance chain: deposit chained to the patient,
    // allocation chained to the deposit + invoice, settlement chained to
    // the staff cashier, insurance claim chained to the policy + invoice,
    // claim line chained to the invoice line above.
    $deposit = (string) Str::uuid();
    $c->insert('insert into deposits (id, tenant_id, facility_id, patient_id, amount_minor, remaining_minor, status, idempotency_key, collected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$deposit, $tenantId, $facilityId, $patient, 5000, 5000, 'active', 'CHAIN-DEP-1', '2026-08-15 11:05:00+00', 0]);
    $ids['deposits'] = $deposit;

    $depositAllocation = (string) Str::uuid();
    $c->insert('insert into deposit_allocations (id, tenant_id, facility_id, deposit_id, invoice_id, amount_minor, allocated_at) values (?, ?, ?, ?, ?, ?, ?)', [$depositAllocation, $tenantId, $facilityId, $deposit, $invoice, 1000, '2026-08-15 11:10:00+00']);
    $ids['deposit_allocations'] = $depositAllocation;

    $settlement = (string) Str::uuid();
    $c->insert('insert into settlements (id, tenant_id, facility_id, cashier_id, settlement_date, expected_minor, actual_minor, variance_minor, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$settlement, $tenantId, $facilityId, $staff, '2026-08-15', 1000, 1000, 0, 'reconciled', 0]);
    $ids['settlements'] = $settlement;

    $claim = (string) Str::uuid();
    $c->insert('insert into claims (id, tenant_id, claim_number, policy_id, invoice_id, payer_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$claim, $tenantId, 'CHAIN-CLM-1', $policy, $invoice, $payer, 'draft', 0]);
    $ids['claims'] = $claim;

    $claimLine = (string) Str::uuid();
    $c->insert('insert into claim_lines (id, tenant_id, claim_id, invoice_line_id, billed_minor, status) values (?, ?, ?, ?, ?, ?)', [$claimLine, $tenantId, $claim, $invoiceLine, 5000, 'pending']);
    $ids['claim_lines'] = $claimLine;

    // Phase 3 slice 2 — laboratory & radiology (lab_tests → lab_orders →
    // lab_order_items, chained to the encounter/patient/staff above).
    $labTest = (string) Str::uuid();
    $c->insert('insert into lab_tests (id, tenant_id, facility_id, code, name, category, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$labTest, $tenantId, $facilityId, $u('lt'), 'Complete Blood Count', 'laboratory', 'active', 0]);
    $ids['lab_tests'] = $labTest;

    $labOrder = (string) Str::uuid();
    $c->insert('insert into lab_orders (id, tenant_id, facility_id, patient_id, encounter_id, ordered_by_staff_id, priority, status, ordered_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$labOrder, $tenantId, $facilityId, $patient, $encounter, $staff, 'routine', 'ordered', '2026-08-15 11:30:00+00', 0]);
    $ids['lab_orders'] = $labOrder;

    $labItem = (string) Str::uuid();
    $c->insert('insert into lab_order_items (id, tenant_id, facility_id, lab_order_id, lab_test_id) values (?, ?, ?, ?, ?)', [$labItem, $tenantId, $facilityId, $labOrder, $labTest]);
    $ids['lab_order_items'] = $labItem;

    // Phase 3 slice 15 — specimen custody chain + the append-only corrected
    // result version, both chained to the lab order item above.
    $specimen = (string) Str::uuid();
    $c->insert('insert into specimens (id, tenant_id, facility_id, lab_order_id, accession_number, specimen_type, status, collected_by_staff_id, collected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$specimen, $tenantId, $facilityId, $labOrder, $u('acc'), 'blood', 'collected', $staff, '2026-08-15 11:40:00+00', 0]);
    $ids['specimens'] = $specimen;

    $resultVersion = (string) Str::uuid();
    $c->insert('insert into lab_result_versions (id, tenant_id, facility_id, lab_order_item_id, version_no, result_value, result_unit, reference_range, entered_by_staff_id, entered_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$resultVersion, $tenantId, $facilityId, $labItem, 1, '7.2', 'x10^9/L', '4.0-11.0', $staff, '2026-08-15 11:45:00+00']);
    $ids['lab_result_versions'] = $resultVersion;

    // Phase 3 slice 16 — Radiology: a modality, the study chained to the
    // lab order above, a report on the study, and a DICOM image reference.
    $modality = (string) Str::uuid();
    $c->insert('insert into modalities (id, tenant_id, facility_id, code, name, modality_type, daily_capacity, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$modality, $tenantId, $facilityId, $u('mod'), 'X-Ray Room 1', 'xray', 20, 'active', 0]);
    $ids['modalities'] = $modality;

    $study = (string) Str::uuid();
    $c->insert('insert into studies (id, tenant_id, facility_id, lab_order_id, modality_id, status, ordered_at, scheduled_at, performed_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$study, $tenantId, $facilityId, $labOrder, $modality, 'performed', '2026-08-15 11:50:00+00', '2026-08-15 12:00:00+00', '2026-08-15 12:30:00+00', 0]);
    $ids['studies'] = $study;

    $radiologyReport = (string) Str::uuid();
    $c->insert('insert into radiology_reports (id, tenant_id, facility_id, study_id, report_type, status, content, reported_by_staff_id, reported_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$radiologyReport, $tenantId, $facilityId, $study, 'final', 'final', 'Normal film.', $staff, '2026-08-15 12:45:00+00', 0]);
    $ids['radiology_reports'] = $radiologyReport;

    $imageReference = (string) Str::uuid();
    $c->insert('insert into image_references (id, tenant_id, facility_id, study_id, reference_type, reference_value) values (?, ?, ?, ?, ?, ?)', [$imageReference, $tenantId, $facilityId, $study, 'dicom_study_instance_uid', '1.2.826.0.1.3680043.8.498.123456789']);
    $ids['image_references'] = $imageReference;

    // Phase 3 slice 3 — pharmacy inventory (inventory_items →
    // inventory_movements, chained to the medication above).
    $inventoryItem = (string) Str::uuid();
    $c->insert('insert into inventory_items (id, tenant_id, facility_id, medication_id, quantity_on_hand, reorder_level, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$inventoryItem, $tenantId, $facilityId, $medication, 100, 10, 0]);
    $ids['inventory_items'] = $inventoryItem;

    $movement = (string) Str::uuid();
    $c->insert('insert into inventory_movements (id, tenant_id, facility_id, inventory_item_id, movement_type, quantity_delta, reason, occurred_at) values (?, ?, ?, ?, ?, ?, ?, ?)', [$movement, $tenantId, $facilityId, $inventoryItem, 'receipt', 100, 'Chain receipt', '2026-08-15 12:00:00+00']);
    $ids['inventory_movements'] = $movement;

    // Phase 3 slice 17 — stock batch chained to the inventory item and
    // medication above; the ledger movement records the batch it touched.
    $batch = (string) Str::uuid();
    $c->insert('insert into stock_batches (id, tenant_id, facility_id, inventory_item_id, medication_id, batch_number, expiry_date, quantity_received, quantity_remaining, status, controlled_dispense_requires_dual, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$batch, $tenantId, $facilityId, $inventoryItem, $medication, 'B-CHAIN', '2026-12-31', 100, 100, 'available', false, 0]);
    $ids['stock_batches'] = $batch;
    $c->update('update inventory_movements set stock_batch_id = ? where id = ?', [$batch, $movement]);

    // Phase 14 — inventory & procurement chain (DATABASE.md §3.31–3.32),
    // chained to the inventory item, medication, and vendor above.
    $transfer = (string) Str::uuid();
    $c->insert('insert into inventory_transfers (id, tenant_id, facility_id, destination_facility_id, inventory_item_id, medication_id, quantity, reason, dispatched_by, dispatched_at, received_by, received_at, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$transfer, $tenantId, $facilityId, $facilityId, $inventoryItem, $medication, 10, 'Chain transfer', $user, '2026-08-15 12:10:00+00', $user, '2026-08-15 12:10:00+00', $user, $user]);
    $ids['inventory_transfers'] = $transfer;

    $adjustmentRequest = (string) Str::uuid();
    $c->insert('insert into inventory_adjustment_requests (id, tenant_id, facility_id, inventory_item_id, quantity_delta, reason, status, requested_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$adjustmentRequest, $tenantId, $facilityId, $inventoryItem, -5, 'Chain adjustment', 'requested', $user, 0]);
    $ids['inventory_adjustment_requests'] = $adjustmentRequest;

    $vendor = (string) Str::uuid();
    $c->insert('insert into vendors (id, tenant_id, facility_id, code, name, status) values (?, ?, ?, ?, ?, ?)', [$vendor, $tenantId, $facilityId, 'VND-CHAIN', 'Chain Supplier', 'active']);
    $ids['vendors'] = $vendor;

    $purchaseRequest = (string) Str::uuid();
    $c->insert('insert into purchase_requests (id, tenant_id, facility_id, request_number, requested_by, department_id, status, requested_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$purchaseRequest, $tenantId, $facilityId, 'PR-CHAIN', $user, $department, 'approved', '2026-08-15 12:15:00+00', 0]);
    $ids['purchase_requests'] = $purchaseRequest;

    $purchaseRequestLine = (string) Str::uuid();
    $c->insert('insert into purchase_request_lines (id, tenant_id, facility_id, purchase_request_id, medication_id, quantity, estimated_unit_price_minor) values (?, ?, ?, ?, ?, ?, ?)', [$purchaseRequestLine, $tenantId, $facilityId, $purchaseRequest, $medication, 20, 450]);
    $ids['purchase_request_lines'] = $purchaseRequestLine;

    $approval = (string) Str::uuid();
    $c->insert('insert into purchase_request_approvals (id, tenant_id, purchase_request_id, approver_id, decision, decided_at) values (?, ?, ?, ?, ?, ?)', [$approval, $tenantId, $purchaseRequest, $user, 'approved', '2026-08-15 12:20:00+00']);
    $ids['purchase_request_approvals'] = $approval;

    $purchaseOrder = (string) Str::uuid();
    $c->insert('insert into purchase_orders (id, tenant_id, facility_id, po_number, vendor_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$purchaseOrder, $tenantId, $facilityId, 'PO-CHAIN', $vendor, 'received', 0]);
    $ids['purchase_orders'] = $purchaseOrder;

    $purchaseOrderLine = (string) Str::uuid();
    $c->insert('insert into purchase_order_lines (id, tenant_id, facility_id, po_id, medication_id, quantity_ordered, unit_price_minor, received_quantity, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$purchaseOrderLine, $tenantId, $facilityId, $purchaseOrder, $medication, 20, 450, 20, 0]);
    $ids['purchase_order_lines'] = $purchaseOrderLine;

    $grn = (string) Str::uuid();
    $c->insert('insert into goods_receipts (id, tenant_id, facility_id, grn_number, po_id, received_by, received_at, status, match_status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$grn, $tenantId, $facilityId, 'GRN-CHAIN', $purchaseOrder, $user, '2026-08-15 12:30:00+00', 'matched', 'matched']);
    $ids['goods_receipts'] = $grn;

    $grnLine = (string) Str::uuid();
    $c->insert('insert into goods_receipt_lines (id, tenant_id, facility_id, grn_id, po_line_id, medication_id, quantity_received, unit_price_received) values (?, ?, ?, ?, ?, ?, ?, ?)', [$grnLine, $tenantId, $facilityId, $grn, $purchaseOrderLine, $medication, 20, 450]);
    $ids['goods_receipt_lines'] = $grnLine;

    $contract = (string) Str::uuid();
    $c->insert('insert into vendor_contracts (id, tenant_id, facility_id, vendor_id, medication_id, unit_price_minor, valid_from, valid_to, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$contract, $tenantId, $facilityId, $vendor, $medication, 450, '2026-01-01', '2026-12-31', 'active']);
    $ids['vendor_contracts'] = $contract;

    // Phase 3 slice 4 — follow-up plan chained to the encounter above.
    $followUp = (string) Str::uuid();
    $c->insert('insert into follow_ups (id, tenant_id, facility_id, patient_id, encounter_id, provider_staff_id, follow_up_type, planned_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$followUp, $tenantId, $facilityId, $patient, $encounter, $staff, 'return_visit', '2026-08-22 09:00:00+00', 'planned', 0]);
    $ids['follow_ups'] = $followUp;

    // Phase 3 slice 6 — IPD admission chained to the encounter above; the
    // admission claims the bed (beds.current_admission_id composite FK).
    $admission = (string) Str::uuid();
    $c->insert('insert into admissions (id, tenant_id, facility_id, patient_id, encounter_id, admission_number, admission_type, admitting_diagnosis, admitted_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$admission, $tenantId, $facilityId, $patient, $encounter, $u('adm'), 'emergency', 'Chain admission', '2026-08-15 12:30:00+00', 'admitted', 0]);
    $ids['admissions'] = $admission;
    $c->update('update beds set status = ?, current_admission_id = ?, lock_version = ? where id = ?', ['occupied', $admission, 1, $bed]);

    // Phase 3 slice 7 — critical-value escalation chained to the flagged
    // lab-order item above (targeted at the ordering clinician).
    $criticalEvent = (string) Str::uuid();
    $c->insert('insert into critical_value_events (id, tenant_id, facility_id, lab_order_item_id, patient_id, encounter_id, target_staff_id, status, detected_by_staff_id, detected_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$criticalEvent, $tenantId, $facilityId, $labItem, $patient, $encounter, $staff, 'triggered', $staff, '2026-08-15 12:45:00+00', 0]);
    $ids['critical_value_events'] = $criticalEvent;

    // Phase 3 slice 8 — pharmacy return chained to the dispensed line and
    // the posted charge above.
    $pharmacyReturn = (string) Str::uuid();
    $c->insert('insert into pharmacy_returns (id, tenant_id, facility_id, prescription_line_id, prescription_id, charge_id, quantity_minor, reason_code, reason_note, returned_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pharmacyReturn, $tenantId, $facilityId, $line, $prescription, $charge, 1, 'patient_return', 'Chain return', '2026-08-15 13:00:00+00']);
    $ids['pharmacy_returns'] = $pharmacyReturn;

    // Phase 3 — standalone dispensing chained to the batch, patient, item,
    // and staff above (dispensing without a prescription); the posted
    // dispensing charge carries dispensing_id.
    $dispensing = (string) Str::uuid();
    $c->insert('insert into dispensings (id, tenant_id, facility_id, patient_id, medication_id, inventory_item_id, stock_batch_id, batch_number, batch_expires_at, quantity_minor, status, dispensed_by_staff_id, dispensed_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$dispensing, $tenantId, $facilityId, $patient, $medication, $inventoryItem, $batch, 'B-CHAIN', '2026-12-31', 1, 'dispensed', $staff, '2026-08-15 13:05:00+00']);
    $ids['dispensings'] = $dispensing;
    $c->update('update charges set dispensing_id = ? where id = ?', [$dispensing, $charge]);

    // Phase 3 slice 10 — follow-up reminder chained to the plan above (TENANT
    // tier: tenant_id only, no facility_id — §3.37).
    $notification = (string) Str::uuid();
    $c->insert('insert into notifications (id, tenant_id, patient_id, follow_up_id, type, channel, payload, status, sensitive, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$notification, $tenantId, $patient, $followUp, 'appointment_reminder', 'in_app', '{}', 'sent', true, '2026-08-15 13:15:00+00', '2026-08-15 13:15:00+00']);
    $ids['notifications'] = $notification;

    // Phase 3 slice 13 — the remaining documented IPD workflow: the
    // transfer timeline, nursing notes, MAR entries, and vital observations
    // chained to the admission and prescription line above. A second bed is
    // created as the transfer target (the chain's main bed is occupied).
    $bedTarget = (string) Str::uuid();
    $c->insert('insert into beds (id, tenant_id, facility_id, room_id, bed_code, status, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$bedTarget, $tenantId, $facilityId, $room, $u('bed2'), 'available', 0]);

    $transferEvent = (string) Str::uuid();
    $c->insert('insert into transfer_events (id, tenant_id, facility_id, admission_id, from_bed_id, to_bed_id, reason, transferred_by, transferred_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$transferEvent, $tenantId, $facilityId, $admission, $bed, $bedTarget, 'Chain transfer', $staff, '2026-08-15 13:30:00+00']);
    $ids['transfer_events'] = $transferEvent;

    $nursingNote = (string) Str::uuid();
    $c->insert('insert into nursing_notes (id, tenant_id, facility_id, admission_id, author_staff_id, content, status) values (?, ?, ?, ?, ?, ?, ?)', [$nursingNote, $tenantId, $facilityId, $admission, $staff, '{}', 'draft']);
    $ids['nursing_notes'] = $nursingNote;

    $marEntry = (string) Str::uuid();
    $c->insert('insert into mar_entries (id, tenant_id, facility_id, admission_id, prescription_line_id, scheduled_at, status) values (?, ?, ?, ?, ?, ?, ?)', [$marEntry, $tenantId, $facilityId, $admission, $line, '2026-08-15 14:00:00+00', 'scheduled']);
    $ids['mar_entries'] = $marEntry;

    $vital = (string) Str::uuid();
    $c->insert('insert into vital_observations (id, tenant_id, facility_id, admission_id, encounter_id, patient_id, type, value, measured_at, measured_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$vital, $tenantId, $facilityId, $admission, $encounter, $patient, 'bp', '{"systolic": 120, "diastolic": 80}', '2026-08-15 13:45:00+00', $staff]);
    $ids['vital_observations'] = $vital;

    // Phase 3 slice 14 — Emergency: registration, triage scale, triage
    // assignment, and the ER event log chained to the ER encounter. A fresh
    // ER encounter is created (the chain's main encounter is opd).
    $erEncounter = (string) Str::uuid();
    $c->insert('insert into encounters (id, tenant_id, facility_id, patient_id, provider_staff_id, type, status, started_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$erEncounter, $tenantId, $facilityId, $patient, $staff, 'er', 'open', '2026-08-15 13:00:00+00', 0]);

    $erRegistration = (string) Str::uuid();
    $c->insert('insert into er_registrations (id, tenant_id, facility_id, patient_id, encounter_id, registered_by, registered_at, is_unidentified) values (?, ?, ?, ?, ?, ?, ?, ?)', [$erRegistration, $tenantId, $facilityId, $patient, $erEncounter, $staff, '2026-08-15 13:05:00+00', true]);
    $ids['er_registrations'] = $erRegistration;

    $triageScale = (string) Str::uuid();
    $c->insert('insert into triage_scales (id, tenant_id, facility_id, code, name, level, color, reassessment_minutes, is_default, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$triageScale, $tenantId, $facilityId, 'L1', 'Resuscitation', 1, 'red', 5, true, 'active', 0]);
    $ids['triage_scales'] = $triageScale;

    $triageAssignment = (string) Str::uuid();
    $c->insert('insert into triage_assignments (id, tenant_id, facility_id, encounter_id, patient_id, triage_scale_id, level, color, assessed_by_staff_id, assessed_at, is_override, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$triageAssignment, $tenantId, $facilityId, $erEncounter, $patient, $triageScale, 1, 'red', $staff, '2026-08-15 13:10:00+00', false, 'active', 0]);
    $ids['triage_assignments'] = $triageAssignment;

    $erEvent = (string) Str::uuid();
    $c->insert('insert into er_events (id, tenant_id, facility_id, encounter_id, patient_id, event_type, occurred_at, actor_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?)', [$erEvent, $tenantId, $facilityId, $erEncounter, $patient, 'registered', '2026-08-15 13:05:00+00', $staff]);
    $ids['er_events'] = $erEvent;

    $audit = (string) Str::uuid();
    $c->insert('insert into audit_events (id, tenant_id, facility_id, occurred_at, actor_type, action, resource_type, payload, correlation_id, prev_hash, event_hash) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$audit, $tenantId, $facilityId, '2026-08-15 00:00:00+00', 'user', 'chain.seeded', 'test', '{}', (string) Str::uuid(), null, hash('sha256', (string) Str::uuid())]);
    $ids['audit_events'] = $audit;

    // Phase 3 slice 19 — HR + Assets chain rows (DATABASE.md §3.45–3.47).
    $position = (string) Str::uuid();
    $c->insert('insert into positions (id, tenant_id, facility_id, department_id, code, name, status) values (?, ?, ?, ?, ?, ?, ?)', [$position, $tenantId, $facilityId, $department, $u('pos'), 'Chain Position', 'active']);
    $ids['positions'] = $position;

    $shiftTemplate = (string) Str::uuid();
    $c->insert('insert into shift_templates (id, tenant_id, facility_id, department_id, code, name, shift_type, starts_at, ends_at, working_minutes, status) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$shiftTemplate, $tenantId, $facilityId, $department, $u('shift'), 'Day Shift', 'day', '08:00', '16:00', 480, 'active']);
    $ids['shift_templates'] = $shiftTemplate;

    $roster = (string) Str::uuid();
    $c->insert('insert into rosters (id, tenant_id, facility_id, staff_id, shift_template_id, roster_date, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$roster, $tenantId, $facilityId, $staff, $shiftTemplate, '2026-08-16', 'scheduled', 0]);
    $ids['rosters'] = $roster;

    $attendance = (string) Str::uuid();
    $c->insert('insert into attendance_records (id, tenant_id, facility_id, staff_id, attendance_date, status, source, correction_status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$attendance, $tenantId, $facilityId, $staff, '2026-08-16', 'present', 'clock', 'none', 0]);
    $ids['attendance_records'] = $attendance;

    $leaveType = (string) Str::uuid();
    $c->insert('insert into leave_types (id, tenant_id, facility_id, code, name, paid_days_per_year, carryover_days, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$leaveType, $tenantId, $facilityId, $u('lv'), 'Annual Leave', 30, 5, 'active']);
    $ids['leave_types'] = $leaveType;

    $leaveRequest = (string) Str::uuid();
    $c->insert('insert into leave_requests (id, tenant_id, facility_id, staff_id, leave_type_id, starts_on, ends_on, days_requested, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$leaveRequest, $tenantId, $facilityId, $staff, $leaveType, '2026-08-20', '2026-08-22', 3, 'pending', 0]);
    $ids['leave_requests'] = $leaveRequest;

    $payrollExport = (string) Str::uuid();
    $c->insert('insert into payroll_exports (id, tenant_id, facility_id, period_start, period_end, row_count, format, payload_hash, exported_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$payrollExport, $tenantId, $facilityId, '2026-08-01', '2026-08-31', 0, 'payroll_ready', 'hash', '2026-08-16 00:00:00+00']);
    $ids['payroll_exports'] = $payrollExport;

    $assetCategory = (string) Str::uuid();
    $c->insert('insert into asset_categories (id, tenant_id, facility_id, code, name, status) values (?, ?, ?, ?, ?, ?)', [$assetCategory, $tenantId, $facilityId, $u('acat'), 'Imaging', 'active']);
    $ids['asset_categories'] = $assetCategory;

    $asset = (string) Str::uuid();
    $c->insert('insert into assets (id, tenant_id, facility_id, category_id, name, lifecycle_status, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$asset, $tenantId, $facilityId, $assetCategory, 'MRI Scanner', 'procured', 'active', 0]);
    $ids['assets'] = $asset;

    $assetTransfer = (string) Str::uuid();
    $c->insert('insert into asset_transfers (id, tenant_id, facility_id, asset_id, to_location_id, transferred_at) values (?, ?, ?, ?, ?, ?)', [$assetTransfer, $tenantId, $facilityId, $asset, $location, '2026-08-16 00:00:00+00']);
    $ids['asset_transfers'] = $assetTransfer;

    $maintenance = (string) Str::uuid();
    $c->insert('insert into maintenance_schedules (id, tenant_id, facility_id, asset_id, schedule_type, frequency_days, next_due_date, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$maintenance, $tenantId, $facilityId, $asset, 'preventive', 90, '2026-11-01', 'active', 0]);
    $ids['maintenance_schedules'] = $maintenance;

    $workOrder = (string) Str::uuid();
    $c->insert('insert into work_orders (id, tenant_id, facility_id, asset_id, work_order_number, status, opened_at, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$workOrder, $tenantId, $facilityId, $asset, $u('wo'), 'open', '2026-08-16 00:00:00+00', 0]);
    $ids['work_orders'] = $workOrder;

    $iotReading = (string) Str::uuid();
    $c->insert('insert into iot_readings (id, tenant_id, facility_id, asset_id, reading_type, reading_value, read_at, source) values (?, ?, ?, ?, ?, ?, ?, ?)', [$iotReading, $tenantId, $facilityId, $asset, 'location', '{}', '2026-08-16 00:00:00+00', 'manual']);
    $ids['iot_readings'] = $iotReading;

    // Phase 3 slice 20 — OT (theatres, procedure_requests, procedures,
    // surgical_team_members, anesthesia_records, surgical_events,
    // checklist_templates, checklist_items, recovery_records).
    $theatre = (string) Str::uuid();
    $c->insert('insert into theatres (id, tenant_id, facility_id, code, name, status) values (?, ?, ?, ?, ?, ?)', [$theatre, $tenantId, $facilityId, $u('ot'), 'Main Theatre', 'active']);
    $ids['theatres'] = $theatre;

    $procedureRequest = (string) Str::uuid();
    $c->insert('insert into procedure_requests (id, tenant_id, facility_id, patient_id, requested_by_staff_id, procedure_name, priority, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$procedureRequest, $tenantId, $facilityId, $patient, $staff, 'Cholecystectomy', 'routine', 'scheduled', 0]);
    $ids['procedure_requests'] = $procedureRequest;

    $procedure = (string) Str::uuid();
    $c->insert('insert into procedures (id, tenant_id, facility_id, procedure_request_id, patient_id, theatre_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$procedure, $tenantId, $facilityId, $procedureRequest, $patient, $theatre, 'in_progress', 0]);
    $ids['procedures'] = $procedure;

    $teamMember = (string) Str::uuid();
    $c->insert('insert into surgical_team_members (id, tenant_id, facility_id, procedure_id, staff_id, role, time_in) values (?, ?, ?, ?, ?, ?, ?)', [$teamMember, $tenantId, $facilityId, $procedure, $staff, 'surgeon', '2026-08-16 09:00:00+00']);
    $ids['surgical_team_members'] = $teamMember;

    $anesthesia = (string) Str::uuid();
    $c->insert('insert into anesthesia_records (id, tenant_id, facility_id, procedure_id, anesthetist_staff_id, anesthesia_type, started_at, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$anesthesia, $tenantId, $facilityId, $procedure, $staff, 'general', '2026-08-16 09:00:00+00', 'active', 0]);
    $ids['anesthesia_records'] = $anesthesia;

    $surgicalEvent = (string) Str::uuid();
    $c->insert('insert into surgical_events (id, tenant_id, facility_id, procedure_id, event_type, occurred_at) values (?, ?, ?, ?, ?, ?)', [$surgicalEvent, $tenantId, $facilityId, $procedure, 'incision', '2026-08-16 09:05:00+00']);
    $ids['surgical_events'] = $surgicalEvent;

    $checklistTemplate = (string) Str::uuid();
    $c->insert('insert into checklist_templates (id, tenant_id, facility_id, code, name, category, steps, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$checklistTemplate, $tenantId, $facilityId, $u('cl'), 'Time-out', 'time_out', '[{"key":"id_verified"}]', 'active']);
    $ids['checklist_templates'] = $checklistTemplate;

    $checklistItem = (string) Str::uuid();
    $c->insert('insert into checklist_items (id, tenant_id, facility_id, procedure_id, checklist_template_id, step_key, step_label, sequence, category) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$checklistItem, $tenantId, $facilityId, $procedure, $checklistTemplate, 'id_verified', 'Identity confirmed', 1, 'time_out']);
    $ids['checklist_items'] = $checklistItem;

    $recovery = (string) Str::uuid();
    $c->insert('insert into recovery_records (id, tenant_id, facility_id, procedure_id, admitted_at, admitted_by_staff_id, observations, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$recovery, $tenantId, $facilityId, $procedure, '2026-08-16 11:00:00+00', $staff, '{}', 'in_recovery', 0]);
    $ids['recovery_records'] = $recovery;

    // Phase 3 slice 20 — ICU (icu_beds, icu_admissions,
    // icu_observation_sets, warning_scores, icu_alerts, critical_care_notes).
    $icuBed = (string) Str::uuid();
    $c->insert('insert into icu_beds (id, tenant_id, facility_id, bed_code, status, acuity_supported, lock_version) values (?, ?, ?, ?, ?, ?, ?)', [$icuBed, $tenantId, $facilityId, $u('icu'), 'occupied', 'level_3', 0]);
    $ids['icu_beds'] = $icuBed;

    $icuAdmission = (string) Str::uuid();
    $c->insert('insert into icu_admissions (id, tenant_id, facility_id, patient_id, icu_bed_id, source, acuity, observation_interval_minutes, next_observation_due_at, status, admitted_at, admitted_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$icuAdmission, $tenantId, $facilityId, $patient, $icuBed, 'ot', 'level_3', 60, '2026-08-16 10:00:00+00', 'admitted', '2026-08-16 09:00:00+00', $staff, 0]);
    $ids['icu_admissions'] = $icuAdmission;

    $observation = (string) Str::uuid();
    $c->insert('insert into icu_observation_sets (id, tenant_id, facility_id, icu_admission_id, observed_at, observed_by_staff_id, values) values (?, ?, ?, ?, ?, ?, ?)', [$observation, $tenantId, $facilityId, $icuAdmission, '2026-08-16 09:30:00+00', $staff, '{"hr": 72}']);
    $ids['icu_observation_sets'] = $observation;

    $warningScore = (string) Str::uuid();
    $c->insert('insert into warning_scores (id, tenant_id, facility_id, icu_admission_id, observation_set_id, score_total, severity, breakdown, scale_version, computed_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$warningScore, $tenantId, $facilityId, $icuAdmission, $observation, 0, 'low', '{}', 'news-1', '2026-08-16 09:30:00+00']);
    $ids['warning_scores'] = $warningScore;

    $icuAlert = (string) Str::uuid();
    $c->insert('insert into icu_alerts (id, tenant_id, facility_id, icu_admission_id, alert_type, severity, message, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$icuAlert, $tenantId, $facilityId, $icuAdmission, 'missed_observation', 'medium', 'Observation was late.', 'open']);
    $ids['icu_alerts'] = $icuAlert;

    $ccNote = (string) Str::uuid();
    $c->insert('insert into critical_care_notes (id, tenant_id, facility_id, icu_admission_id, note_type, content, authored_at, authored_by_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?)', [$ccNote, $tenantId, $facilityId, $icuAdmission, 'daily_goal', 'Goals.', '2026-08-16 09:00:00+00', $staff]);
    $ids['critical_care_notes'] = $ccNote;

    // Phase 3 slice 20 — Blood Bank (donors, donations, blood_units,
    // compatibility_results, crossmatches, transfusions, reaction_reports).
    $donor = (string) Str::uuid();
    $c->insert('insert into donors (id, tenant_id, facility_id, donor_number, full_name, date_of_birth, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$donor, $tenantId, $facilityId, $u('dn'), 'Donor Name', '1980-01-01', 'active', 0]);
    $ids['donors'] = $donor;

    $donation = (string) Str::uuid();
    $c->insert('insert into donations (id, tenant_id, facility_id, donor_id, donated_at, phlebotomist_staff_id, volume_ml, screening_result, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$donation, $tenantId, $facilityId, $donor, '2026-08-16 09:00:00+00', $staff, 450, 'eligible', 'processed', 0]);
    $ids['donations'] = $donation;

    $unit = (string) Str::uuid();
    $c->insert('insert into blood_units (id, tenant_id, facility_id, donation_id, unit_number, component_type, blood_group, rh_factor, collected_at, expiry_at, tested, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$unit, $tenantId, $facilityId, $donation, $u('bu'), 'packed_cells', 'O', 'positive', '2026-08-16 09:00:00+00', '2026-09-20 00:00:00+00', false, 'available', 0]);
    $ids['blood_units'] = $unit;

    $compatibility = (string) Str::uuid();
    $c->insert('insert into compatibility_results (id, tenant_id, facility_id, patient_id, patient_blood_group, abo_rh_compatible, antibody_screen, result, checked_at, checked_by_staff_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$compatibility, $tenantId, $facilityId, $patient, 'O', true, 'negative', 'compatible', '2026-08-16 09:30:00+00', $staff]);
    $ids['compatibility_results'] = $compatibility;

    $crossmatch = (string) Str::uuid();
    $c->insert('insert into crossmatches (id, tenant_id, facility_id, blood_unit_id, patient_id, status, requested_at, requested_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$crossmatch, $tenantId, $facilityId, $unit, $patient, 'compatible', '2026-08-16 09:30:00+00', $staff, 0]);
    $ids['crossmatches'] = $crossmatch;

    $transfusion = (string) Str::uuid();
    $c->insert('insert into transfusions (id, tenant_id, facility_id, blood_unit_id, patient_id, crossmatch_id, started_at, started_by_staff_id, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$transfusion, $tenantId, $facilityId, $unit, $patient, $crossmatch, '2026-08-16 10:00:00+00', $staff, 'started', 0]);
    $ids['transfusions'] = $transfusion;

    $reaction = (string) Str::uuid();
    $c->insert('insert into reaction_reports (id, tenant_id, facility_id, transfusion_id, occurred_at, severity, symptoms, status, reported_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reaction, $tenantId, $facilityId, $transfusion, '2026-08-16 10:15:00+00', 'mild', '[]', 'reported', $staff, 0]);
    $ids['reaction_reports'] = $reaction;

    // Phase 3 slice 21 — Analytics and Reporting (kpi_definitions,
    // metric_snapshots, dashboards, dashboard_kpis, report_templates,
    // report_schedules, report_runs — DATABASE.md §3.51). Aggregate rows
    // contain counts/values only, never PHI, but are tenant-scoped data.
    $kpi = (string) Str::uuid();
    $c->insert('insert into kpi_definitions (id, tenant_id, facility_id, code, name, domain, source_table, date_column, filter, aggregation, version, status, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$kpi, $tenantId, $facilityId, $u('kpi'), 'Registrations', 'operational', 'patients', 'created_at', '{}', 'count', 1, 'active', 0]);
    $ids['kpi_definitions'] = $kpi;

    $snapshot = (string) Str::uuid();
    $c->insert('insert into metric_snapshots (id, tenant_id, facility_id, kpi_definition_id, period_start, period_end, value, dimension, row_count, generated_at, generated_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$snapshot, $tenantId, $facilityId, $kpi, '2026-08-16 00:00:00+00', '2026-08-16 23:59:59+00', 1, '{}', 1, '2026-08-16 12:00:00+00', $staff, 0]);
    $ids['metric_snapshots'] = $snapshot;

    $dashboard = (string) Str::uuid();
    $c->insert('insert into dashboards (id, tenant_id, facility_id, code, name, role_gate, is_active, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?)', [$dashboard, $tenantId, $facilityId, $u('dash'), 'Operations', '["hospital_admin"]', true, 0]);
    $ids['dashboards'] = $dashboard;

    $dashboardKpi = (string) Str::uuid();
    $c->insert('insert into dashboard_kpis (id, tenant_id, facility_id, dashboard_id, kpi_definition_id, position, is_active) values (?, ?, ?, ?, ?, ?, ?)', [$dashboardKpi, $tenantId, $facilityId, $dashboard, $kpi, 1, true]);
    $ids['dashboard_kpis'] = $dashboardKpi;

    $reportTemplate = (string) Str::uuid();
    $c->insert('insert into report_templates (id, tenant_id, facility_id, code, name, category, scope, parameter_schema, query, is_active, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reportTemplate, $tenantId, $facilityId, $u('rpt'), 'Registrations report', 'operational', 'facility', '{}', '{"source_table":"patients","filter":{},"date_column":"created_at","period":"last_7_days"}', true, 0]);
    $ids['report_templates'] = $reportTemplate;

    $reportSchedule = (string) Str::uuid();
    $c->insert('insert into report_schedules (id, tenant_id, facility_id, template_id, cron_expression, enabled, last_run_at, next_run_at, created_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reportSchedule, $tenantId, $facilityId, $reportTemplate, '0 6 * * *', true, null, null, $staff, 0]);
    $ids['report_schedules'] = $reportSchedule;

    $reportRun = (string) Str::uuid();
    $c->insert('insert into report_runs (id, tenant_id, facility_id, template_id, schedule_id, requested_by_staff_id, status, run_at, completed_at, row_count, is_export, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$reportRun, $tenantId, $facilityId, $reportTemplate, $reportSchedule, $staff, 'completed', '2026-08-16 12:00:00+00', '2026-08-16 12:00:01+00', 3, false, 0]);
    $ids['report_runs'] = $reportRun;

    // Phase 3 slice 22 — Patient Portal (portal_accounts, portal_sessions,
    // portal_access_grants — DATABASE.md §3.53). The chain patient + staff
    // already exist above; portal identities are patient-identifying data
    // and are tenant+facility scoped like clinical rows.
    $portalAccount = (string) Str::uuid();
    $c->insert('insert into portal_accounts (id, tenant_id, facility_id, patient_id, login_identifier, password_hash, status, failed_attempts, locked_until, mfa_enabled, last_login_at, lock_version, created_by_staff_id, updated_by_staff_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$portalAccount, $tenantId, $facilityId, $patient, $u('portal').'@chain.test', 'hash', 'active', 0, null, false, null, 0, $staff, $staff, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['portal_accounts'] = $portalAccount;

    $portalSession = (string) Str::uuid();
    $c->insert('insert into portal_sessions (id, tenant_id, facility_id, portal_account_id, patient_id, token_id, ip_address, user_agent, expires_at, revoked_at, revoked_by, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$portalSession, $tenantId, $facilityId, $portalAccount, $patient, 9001, '127.0.0.1', 'chain', '2026-08-17 12:00:00+00', null, null, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['portal_sessions'] = $portalSession;

    $portalGrant = (string) Str::uuid();
    $c->insert('insert into portal_access_grants (id, tenant_id, facility_id, portal_account_id, patient_id, data_scope, purpose, status, granted_at, granted_by_staff_id, revoked_at, revoked_by_staff_id, revoked_by_patient, lock_version, created_by, updated_by, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$portalGrant, $tenantId, $facilityId, $portalAccount, $patient, 'appointments', 'Chain appointment visibility', 'granted', '2026-08-16 12:00:00+00', $staff, null, null, false, 0, $staff, $staff, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['portal_access_grants'] = $portalGrant;

    // Phase 3 slice 23 — Interoperability readiness (integrations,
    // integration_events, egress_allowlist, oauth_partners,
    // oauth_partner_tokens — DATABASE.md §3.54). TENANT-tier infrastructure
    // rows: no facility_id, scoped purely by tenant.
    $integration = (string) Str::uuid();
    $c->insert('insert into integrations (id, tenant_id, type, provider, config_encrypted, status, owner_staff_id, purpose, contract_version, standards_version, mapping_version, kill_switched, last_checked_at, health, lock_version, created_by_staff_id, updated_by_staff_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$integration, $tenantId, 'fhir', 'chain-fhir', null, 'configured', $staff, 'Chain FHIR projection', '1.0', 'R4.0.1', '1.0', false, null, null, 0, $staff, $staff, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['integrations'] = $integration;

    $integrationEvent = (string) Str::uuid();
    $c->insert('insert into integration_events (id, tenant_id, integration_id, direction, message_type, correlation_id, consent_basis, payload, status, attempts, error, mapping_version, occurred_at, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$integrationEvent, $tenantId, $integration, 'outbound', 'ADT^A01', (string) Str::uuid(), null, '{"ref":"chain-1"}', 'queued', 0, null, '1.0', '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['integration_events'] = $integrationEvent;

    $egress = (string) Str::uuid();
    $c->insert('insert into egress_allowlist (id, tenant_id, integration_id, host, port, purpose, is_active, created_by_staff_id, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$egress, $tenantId, $integration, 'chain.example.test', 443, 'Chain outbound', true, $staff, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['egress_allowlist'] = $egress;

    $partner = (string) Str::uuid();
    $c->insert('insert into oauth_partners (id, tenant_id, name, client_id, client_secret_hash, scopes, status, token_ttl_seconds, webhook_url, webhook_secret_hash, created_by_staff_id, lock_version, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$partner, $tenantId, 'Chain Partner', $u('cli'), 'hash', '["fhir:Patient"]', 'active', 3600, null, null, $staff, 0, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['oauth_partners'] = $partner;

    $partnerToken = (string) Str::uuid();
    $c->insert('insert into oauth_partner_tokens (id, tenant_id, oauth_partner_id, token_hash, scopes, expires_at, revoked_at, last_used_at, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$partnerToken, $tenantId, $partner, hash('sha256', $partnerToken), '["fhir:Patient"]', '2026-08-17 12:00:00+00', null, null, '2026-08-16 12:00:00+00', '2026-08-16 12:00:00+00']);
    $ids['oauth_partner_tokens'] = $partnerToken;

    // Phase 3 slice 24 — Telehealth (teleconsults, video_sessions —
    // DATABASE.md §3.55). A teleconsult is booked through the SAME schedule
    // model as OPD: a teleconsult appointment → teleconsult → video session
    // (metadata only, consent-gated, recording-policy-bound).
    $teleconsultAppointment = (string) Str::uuid();
    $c->insert('insert into appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, service_id, appointment_type, starts_at, ends_at, status, source, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$teleconsultAppointment, $tenantId, $facilityId, $patient, $staff, $service, 'teleconsult', '2026-08-16 15:00:00+00', '2026-08-16 15:30:00+00', 'booked', 'counter', 0]);

    $teleconsult = (string) Str::uuid();
    $c->insert('insert into teleconsults (id, tenant_id, facility_id, appointment_id, patient_id, provider_staff_id, status, scheduled_at, starts_at, ends_at, fallback_mode, fallback_reason, created_by_staff_id, updated_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$teleconsult, $tenantId, $facilityId, $teleconsultAppointment, $patient, $staff, 'scheduled', '2026-08-16 15:00:00+00', '2026-08-16 15:00:00+00', '2026-08-16 15:30:00+00', null, null, $staff, $staff, 0]);
    $ids['teleconsults'] = $teleconsult;

    $videoSession = (string) Str::uuid();
    $c->insert('insert into video_sessions (id, tenant_id, facility_id, teleconsult_id, status, started_at, ended_at, provider_session_ref, participant_type, recording_requested, recording_consent_verified, recording_started_at, recording_ended_at, recording_storage_ref, failure_reason, created_by_staff_id, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$videoSession, $tenantId, $facilityId, $teleconsult, 'ended', '2026-08-16 15:05:00+00', '2026-08-16 15:25:00+00', 'chain-room', 'provider', false, false, null, null, null, null, $staff, 0]);
    $ids['video_sessions'] = $videoSession;

    // Phase 3 slice 25 — RPM (rpm_devices, rpm_readings, rpm_alerts —
    // DATABASE.md §3.56): an enrolled device → one labeled reading → one
    // open threshold alert. Values are synthetic.
    $rpmDevice = (string) Str::uuid();
    $c->insert('insert into rpm_devices (id, tenant_id, facility_id, patient_id, device_identifier, model, manufacturer, reading_type, status, settings, adapter, last_seen_at, created_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$rpmDevice, $tenantId, $facilityId, $patient, 'CHAIN-RPM-1', 'PulseOx-2', 'Chain Devices', 'pulse', 'active', '{}', 'chain', '2026-08-17 14:00:00+00', $staff, 0]);
    $ids['rpm_devices'] = $rpmDevice;

    $rpmReading = (string) Str::uuid();
    $c->insert('insert into rpm_readings (id, tenant_id, facility_id, patient_id, device_id, reading_type, value, units, measured_at, received_at, source, validation_status, validation_reason, provenance, ingestion_id, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$rpmReading, $tenantId, $facilityId, $patient, $rpmDevice, 'pulse', '{"value": 140}', null, '2026-08-17 14:01:00+00', '2026-08-17 14:01:00+00', 'device', 'flagged', 'value above threshold', '{"adapter": "chain"}', 'chain-ing-1', null]);
    $ids['rpm_readings'] = $rpmReading;

    $rpmAlert = (string) Str::uuid();
    $c->insert('insert into rpm_alerts (id, tenant_id, facility_id, patient_id, device_id, reading_id, alert_type, parameter, threshold_value, observed_value, severity, status, acknowledged_by, acknowledged_at, acknowledged_note, resolved_by, resolved_at, created_by, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$rpmAlert, $tenantId, $facilityId, $patient, $rpmDevice, $rpmReading, 'threshold_high', 'value', '{"high": 120}', '{"value": 140}', 'medium', 'open', null, null, null, null, null, null, 0]);
    $ids['rpm_alerts'] = $rpmAlert;

    // Phase 21 — CDSS/AI (DATABASE.md §3.57, AI_RULES.md §19): one versioned
    // KB rule, one documented allergy, one open check result, one registered
    // AI feature (kill switch off), and one draft awaiting sign-off. No
    // clinical PHI in these rows — all synthetic.
    $cdssRule = (string) Str::uuid();
    $c->insert('insert into cdss_rules (id, tenant_id, facility_id, rule_type, code, name, severity, spec, version, status, lock_version, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$cdssRule, $tenantId, $facilityId, 'interaction', 'CHAIN-INT-1', 'Chain interaction', 'major', '{"medication_a_id": null, "medication_b_id": null}', 1, 'active', 0, $staff, null]);
    $ids['cdss_rules'] = $cdssRule;

    $allergy = (string) Str::uuid();
    $c->insert('insert into patient_allergies (id, tenant_id, facility_id, patient_id, allergen, allergen_class, severity, reaction, status, lock_version, recorded_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$allergy, $tenantId, $facilityId, $patient, 'Penicillin', 'penicillin', 'moderate', 'Rash', 'active', 0, $staff]);
    $ids['patient_allergies'] = $allergy;

    $checkResult = (string) Str::uuid();
    $c->insert('insert into cdss_check_results (id, tenant_id, facility_id, patient_id, alert_type, rule_code, rule_version, severity, message, triggering_facts, status, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$checkResult, $tenantId, $facilityId, $patient, 'allergy', 'CHAIN-ALL-1', 1, 'major', 'Chain allergy alert.', '{"allergen_class": "penicillin"}', 'open', 0, $staff]);
    $ids['cdss_check_results'] = $checkResult;

    $aiFeature = (string) Str::uuid();
    $c->insert('insert into ai_features (id, tenant_id, facility_id, function, name, tier, owner_staff_id, model_id, model_version, purpose, non_goals, min_inputs, output_schema, confidence_threshold, fallback_mode, enabled, model_approved, evaluation_ref, review_cadence, audit_class, status, lock_version, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$aiFeature, $tenantId, $facilityId, 'documentation_draft', 'Chain draft', 2, $staff, 'note-draft-v3', '2026-07-15', 'Draft notes.', null, '[]', '{}', null, 'manual', false, false, null, 'quarterly', 'ai.draft', 'registered', 0, $staff, null]);
    $ids['ai_features'] = $aiFeature;

    $aiDraft = (string) Str::uuid();
    $c->insert('insert into ai_drafts (id, tenant_id, facility_id, patient_id, encounter_id, function, tier, model_id, model_version, source_refs, output, confidence, status, signer_staff_id, signed_at, correlation_id, lock_version, created_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$aiDraft, $tenantId, $facilityId, $patient, null, 'documentation_draft', 2, 'note-draft-v3', '2026-07-15', '[]', 'Chain draft output.', null, 'draft', null, null, null, 0, $staff]);
    $ids['ai_drafts'] = $aiDraft;

    // Support sessions are owner-or-platform visible: insert with the chain
    // user as the owning context (user_id GUC).
    $session = (string) Str::uuid();
    rlsSet($c, 'user_id', $user);
    $c->insert('insert into support_sessions (id, user_id, organization_id, facility_id, reason, status, opened_at, expires_at, correlation_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$session, $user, $tenantId, $facilityId, 'Chain session', 'active', '2026-08-15 00:00:00+00', '2026-08-15 01:00:00+00', (string) Str::uuid()]);
    $ids['support_sessions'] = $session;

    $ids['facilities'] = $facilityId;

    return $ids;
}

/**
 * The column each table's cross-tenant UPDATE probe writes (a benign,
 * CHECK-safe value) and the value.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function chainUpdateColumns(): array
{
    return [
        'appointments' => ['cancel_reason', 'upd'],
        'admissions' => ['admitting_diagnosis', 'upd'],
        'audit_events' => ['ip_address', '127.0.0.1'],
        'critical_value_events' => ['status', 'acknowledged'],
        'beds' => ['bed_code', 'upd'],
        'branches' => ['code', 'upd'],
        'charges' => ['description', 'upd'],
        'clinical_notes' => ['content', '{}'],
        'consents' => ['revocation_reason', 'upd'],
        'departments' => ['code', 'upd'],
        'diagnoses' => ['description', 'upd'],
        'encounters' => ['ended_at', '2026-08-15 12:00:00+00'],
        'facilities' => ['code', 'upd'],
        'facility_settings' => ['version', '2'],
        'insurance_policies' => ['policy_number', 'upd'],
        'invoice_lines' => ['description', 'upd'],
        'follow_ups' => ['cancel_reason', 'upd'],
        'invoices' => ['void_reason', 'upd'],
        'deposits' => ['remaining_minor', '1'],
        'deposit_allocations' => ['amount_minor', '1'],
        'settlements' => ['status', 'disputed'],
        'claims' => ['status', 'pending'],
        'claim_lines' => ['status', 'denied'],
        'inventory_items' => ['reorder_level', '12'],
        'inventory_movements' => ['reason', 'upd'],
        'inventory_transfers' => ['reason', 'upd'],
        'inventory_adjustment_requests' => ['reason', 'upd'],
        'vendors' => ['name', 'upd'],
        'purchase_requests' => ['status', 'submitted'],
        'purchase_request_lines' => ['quantity', '2'],
        'purchase_request_approvals' => ['decision', 'rejected'],
        'purchase_orders' => ['status', 'confirmed'],
        'purchase_order_lines' => ['received_quantity', '1'],
        'goods_receipts' => ['status', 'received'],
        'goods_receipt_lines' => ['quantity_received', '2'],
        'vendor_contracts' => ['status', 'expired'],
        'stock_batches' => ['batch_number', 'upd'],
        'lab_order_items' => ['result_unit', 'upd'],
        'lab_orders' => ['clinical_indication', 'upd'],
        'specimens' => ['container', 'upd'],
        'lab_result_versions' => ['result_unit', 'upd'],
        'modalities' => ['name', 'upd'],
        'studies' => ['status', 'scheduled'],
        'radiology_reports' => ['status', 'amended'],
        'image_references' => ['description', 'upd'],
        'lab_tests' => ['method', 'upd'],
        'locations' => ['code', 'upd'],
        'medications' => ['brand_name', 'upd'],
        'mrn_counters' => ['last_value', '1'],
        'patient_contacts' => ['value', 'upd'],
        'patient_documents' => ['mime_type', 'upd'],
        'patient_identifiers' => ['value_hash', 'upd'],
        'patient_timeline_entries' => ['event_type', 'upd'],
        'patients' => ['full_name', 'upd'],
        'payers' => ['code', 'upd'],
        'portal_accounts' => ['status', 'locked'],
        'portal_sessions' => ['revoked_by', 'staff'],
        'portal_access_grants' => ['status', 'revoked'],
        'integrations' => ['status', 'disabled'],
        'integration_events' => ['status', 'failed'],
        'egress_allowlist' => ['is_active', 'false'],
        'oauth_partners' => ['status', 'revoked'],
        'oauth_partner_tokens' => ['revoked_at', '2026-08-16 13:00:00+00'],
        'teleconsults' => ['fallback_mode', 'phone'],
        'video_sessions' => ['participant_type', 'patient'],
        'rpm_devices' => ['status', 'disabled'],
        'rpm_readings' => ['validation_status', 'validated'],
        'rpm_alerts' => ['status', 'resolved'],
        'cdss_rules' => ['status', 'superseded'],
        'patient_allergies' => ['reaction', 'upd'],
        'cdss_check_results' => ['status', 'overridden'],
        'ai_features' => ['status', 'retired'],
        'ai_drafts' => ['status', 'withdrawn'],
        'payment_allocations' => ['amount_minor', '1'],
        'payments' => ['provider_ref', 'upd'],
        'payroll_exports' => ['format', 'csv'],
        'pharmacy_returns' => ['reason_note', 'upd'],
        'dispensings' => ['batch_number', 'upd'],
        'positions' => ['name', 'upd'],
        'notifications' => ['status', 'delivered'],
        'transfer_events' => ['reason', 'upd'],
        'nursing_notes' => ['status', 'signed'],
        'mar_entries' => ['status', 'given'],
        'vital_observations' => ['type', 'temp'],
        'er_registrations' => ['is_unidentified', 'true'],
        'triage_scales' => ['name', 'upd'],
        'triage_assignments' => ['level', '5'],
        'er_events' => ['event_type', 'other'],
        'prescription_lines' => ['instructions', 'upd'],
        'prescriptions' => ['notes', 'upd'],
        'role_assignments' => ['granted_by', '00000000-0000-0000-0000-000000000001'],
        'rooms' => ['code', 'upd'],
        'refund_requests' => ['reason_note', 'upd'],
        'schedule_exceptions' => ['status', 'cancelled'],
        'schedule_templates' => ['capacity', '2'],
        'services' => ['code', 'upd'],
        'shift_templates' => ['name', 'upd'],
        'rosters' => ['notes', 'upd'],
        'attendance_records' => ['status', 'late'],
        'leave_types' => ['name', 'upd'],
        'leave_requests' => ['decision_notes', 'upd'],
        'asset_categories' => ['name', 'upd'],
        'assets' => ['name', 'upd'],
        'asset_transfers' => ['reason', 'upd'],
        'maintenance_schedules' => ['contract_ref', 'upd'],
        'work_orders' => ['description', 'upd'],
        'iot_readings' => ['source', 'device'],
        'theatres' => ['name', 'upd'],
        'procedure_requests' => ['priority', 'urgent'],
        'procedures' => ['status', 'completed'],
        'surgical_team_members' => ['time_out', '2026-08-16 12:00:00+00'],
        'anesthesia_records' => ['status', 'completed'],
        'surgical_events' => ['event_type', 'other'],
        'checklist_templates' => ['name', 'upd'],
        'checklist_items' => ['completed_at', '2026-08-16 12:00:00+00'],
        'recovery_records' => ['status', 'discharged'],
        'icu_beds' => ['status', 'out_of_service'],
        'icu_admissions' => ['status', 'transferred'],
        'icu_observation_sets' => ['notes', 'upd'],
        'warning_scores' => ['severity', 'high'],
        'icu_alerts' => ['status', 'acknowledged'],
        'critical_care_notes' => ['note_type', 'other'],
        'donors' => ['status', 'deferred'],
        'donations' => ['status', 'discarded'],
        'blood_units' => ['status', 'discarded'],
        'compatibility_results' => ['notes', 'upd'],
        'crossmatches' => ['status', 'released'],
        'transfusions' => ['status', 'stopped'],
        'reaction_reports' => ['status', 'reviewed'],
        'kpi_definitions' => ['name', 'upd'],
        'metric_snapshots' => ['value', '1'],
        'dashboards' => ['name', 'upd'],
        'dashboard_kpis' => ['position', '2'],
        'report_templates' => ['name', 'upd'],
        'report_schedules' => ['cron_expression', '0 12 * * *'],
        'report_runs' => ['status', 'failed'],
        'staff' => ['designation', 'upd'],
        'support_sessions' => ['reason', 'upd'],
        'token_counters' => ['last_token', '1'],
        'wards' => ['code', 'upd'],
    ];
}

/**
 * @return array{tenantA: string, tenantB: string, facilityA: string, facilityB: string}
 */
function inventoryTenants(ConnectionInterface $c): array
{
    $t = [
        'tenantA' => (string) Str::uuid(),
        'tenantB' => (string) Str::uuid(),
        'facilityA' => (string) Str::uuid(),
        'facilityB' => (string) Str::uuid(),
    ];
    $suffix = substr((string) Str::uuid(), 0, 8);

    // organizations INSERT requires is_platform (Phase 11 RLS reconciliation)
    claimsSet($c, ['app_is_platform' => 'true']);
    foreach (['tenantA', 'tenantB'] as $tenant) {
        $c->insert('insert into organizations (id, name, code, status) values (?, ?, ?, ?)', [$t[$tenant], 'Tenant '.$tenant, 'code-'.$suffix.'-'.strtolower($tenant), 'active']);
    }
    claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);

    foreach (['facilityA', 'facilityB'] as $key) {
        $tenant = $key === 'facilityA' ? 'tenantA' : 'tenantB';
        $c->insert('insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)', [$t[$key], $t[$tenant], 'Facility '.$key, 'code-'.strtolower($key), 'active', 'UTC', '{}', '{}']);
    }

    return $t;
}

it('records the current RLS inventory: 137 scoped tables enabled + FORCED, 11 unscoped off', function () {
    $rows = DB::connection('pgsql')->select(
        'select c.relname as table_name, c.relrowsecurity::text as enabled, c.relforcerowsecurity::text as forced
         from pg_class c
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = ? and c.relkind = ?',
        ['public', 'r']
    );

    $byName = collect($rows)->keyBy('table_name');

    $allScoped = array_merge(RLS_SCOPED_TABLES, RLS_RBAC_SCOPED_TABLES);

    foreach ($allScoped as $table) {
        expect(isset($byName[$table]))->toBeTrue("$table exists");
        expect($byName[$table]->enabled)->toBe('true', "$table has RLS enabled");
    }

    foreach (RLS_UNSCOPED_TABLES as $table) {
        expect(isset($byName[$table]))->toBeTrue("$table exists");
        expect($byName[$table]->enabled)->toBe('false', "$table has RLS disabled");
    }

    // PROGRAM PHASE 1 hardening (migration 2026_08_15_100000): every scoped
    // table is now FORCED — the owner is bound too, not just the runtime
    // role. Regression coverage: this assertion fails if a migration ever
    // re-enables RLS without FORCE.
    foreach ($allScoped as $table) {
        expect($byName[$table]->forced)->toBe('true', "$table is FORCE-enabled");
    }

    // Policy counts per table must match the matrix (4 standard, 3
    // assignments, 3 support sessions, 2 audit), and every scoped table must
    // carry SELECT/UPDATE/DELETE policies.
    $policyByTable = collect(
        DB::connection('pgsql')->select(
            "select tablename, count(*) as total from pg_policies where schemaname = 'public' group by tablename"
        )
    )->keyBy('tablename');

    foreach (RLS_SCOPED_TABLES as $table) {
        $expected = match ($table) {
            'audit_events' => 2,
            'role_assignments', 'support_sessions' => 3,
            default => 4,
        };
        expect((int) ($policyByTable[$table]->total ?? 0))->toBe($expected, "$table policy count");
    }

    $cmds = collect(
        DB::connection('pgsql')->select("select tablename, cmd from pg_policies where schemaname = 'public'")
    )->groupBy('tablename');

    foreach (RLS_SCOPED_TABLES as $table) {
        $requiredOps = match ($table) {
            // audit_events is append-only by design — no UPDATE/DELETE policy.
            'audit_events' => ['SELECT'],
            // assignments/sessions have no DELETE policy (revocation is an
            // UPDATE; sessions end, never delete).
            'role_assignments', 'support_sessions' => ['SELECT', 'UPDATE'],
            default => ['SELECT', 'UPDATE', 'DELETE'],
        };
        foreach ($requiredOps as $required) {
            expect($cmds[$table]->contains(fn ($p) => $p->cmd === $required))->toBeTrue("$table has a $required policy");
        }
    }
});

it('the runtime role stays non-bypass', function () {
    $role = DB::connection('pgsql')->selectOne(
        'select rolbypassrls::text as bypass, rolsuper::text as superuser from pg_roles where rolname = ?',
        ['swasthya_app']
    );

    expect($role)->not->toBeNull()
        ->and($role->bypass)->toBe('false')
        ->and($role->superuser)->toBe('false');
});

it('FORCE RLS binds a non-superuser table owner (defense-in-depth proof)', function () {
    $role = 'swasthya_test_force_'.substr((string) Str::uuid(), 0, 8);
    $c = DB::connection('pgsql');

    // CREATE ROLE is non-transactional — must run outside any transaction.
    $c->statement("create role {$role} noinherit nologin nosuperuser nocreatedb nocreaterole nobypassrls");
    $c->statement("grant usage on schema public to {$role}");

    try {
        DB::transaction(function () use ($c, $role): void {
            $tenant = (string) Str::uuid();
            $facility = (string) Str::uuid();
            $patient = (string) Str::uuid();

            // The local/test owner (swasthya) is a superuser, so FORCE cannot
            // bind it — this proves the FORCE semantics with a non-superuser
            // table owner instead (the real defense-in-depth target).
            // Real FK chain first (organizations → facilities → patients).
            $c->insert(
                'insert into organizations (id, name, code, status) values (?, ?, ?, ?)',
                [$tenant, 'Force Tenant', 'code-force-t', 'active']
            );
            $c->insert(
                'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
                [$facility, $tenant, 'Force Facility', 'code-force', 'active', 'UTC', '{}', '{}']
            );

            $c->statement("alter table patients owner to {$role}");
            $c->statement("set role {$role}");

            // The owner itself inserts a row (INSERT WITH CHECK true)…
            $c->insert(
                'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, consent_summary, lock_version) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$patient, $tenant, $facility, 'FORCE-MRN-'.substr((string) Str::uuid(), 0, 8), 'Force Owner', '1990-01-01', 'female', 'active', '{}', 0]
            );

            // …and with EMPTY claims it cannot read its own row: FORCE binds
            // the owner, exactly the hardening the flag promises.
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull('owner with empty claims is bound by FORCE');

            // With claims, the owner sees its tenant's row and nothing else.
            claimsSet($c, ['app_tenant_id' => $tenant, 'app_facility_id' => $facility]);
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull('owner with its tenant claims sees the row');

            claimsSet($c, ['app_tenant_id' => (string) Str::uuid(), 'app_facility_id' => (string) Str::uuid()]);
            expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull('owner with another tenant claim sees nothing');

            $c->statement('reset role');
            $c->statement('alter table patients owner to current_user');
        });
    } finally {
        $c->statement('reset role');
        $c->statement('alter table patients owner to current_user');
        $exists = (bool) $c->selectOne('select 1 from pg_roles where rolname = ?', [$role]);
        if ($exists) {
            $c->statement("revoke all on schema public from {$role}");
            $c->statement("drop role {$role}");
        }
    }
});

it('denies cross-tenant SELECT, UPDATE, and DELETE on all 128 tenant-owned tables — two-sided', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = inventoryTenants($c);

        // Full two-tenant fixture: every tenant-owned table has a row in BOTH
        // tenants, so a zero-row result is only possible if the policy
        // actually blocks the cross-tenant probe.
        $chain = [
            'A' => seedTenantChain($c, $t['tenantA'], $t['facilityA']),
            'B' => seedTenantChain($c, $t['tenantB'], $t['facilityB']),
        ];

        $updates = chainUpdateColumns();

        foreach (RLS_SCOPED_TABLES as $table) {
            // mrn_counters is keyed by tenant_id (no id column).
            $keyColumn = $table === 'mrn_counters' ? 'tenant_id' : 'id';

            // Each tenant sees its own row (with the owner-user GUC for the
            // owner-or-platform support_sessions policy).
            foreach (['A', 'B'] as $label) {
                rlsSet($c, 'tenant_id', $t['tenant'.$label]);
                rlsSet($c, 'facility_id', $t['facility'.$label]);
                rlsSet($c, 'user_id', $table === 'support_sessions' ? $chain[$label]['users'] : '');
                rlsSet($c, 'is_platform', 'false');
                expect($c->selectOne('select '.$keyColumn.' from '.$table.' where '.$keyColumn.' = ?', [$chain[$label][$table]]))->not->toBeNull("$table visible to its own tenant $label");
            }

            // Cross-tenant attacks: SELECT → invisible, UPDATE/DELETE → 0 rows,
            // then the owner confirms the row is untouched.
            foreach (['A', 'B'] as $owner) {
                $attacker = $owner === 'A' ? 'B' : 'A';
                $targetKey = $chain[$owner][$table];

                rlsSet($c, 'tenant_id', $t['tenant'.$attacker]);
                rlsSet($c, 'facility_id', $t['facility'.$attacker]);
                rlsSet($c, 'user_id', '');
                rlsSet($c, 'is_platform', 'false');

                expect($c->selectOne('select '.$keyColumn.' from '.$table.' where '.$keyColumn.' = ?', [$targetKey]))->toBeNull("$table hidden from tenant $attacker");

                [$column, $value] = $updates[$table];
                expect($c->update("update {$table} set {$column} = ? where {$keyColumn} = ?", [$value, $targetKey]))->toBe(0, "$table update blocked from tenant $attacker");

                expect($c->delete("delete from {$table} where {$keyColumn} = ?", [$targetKey]))->toBe(0, "$table delete blocked from tenant $attacker");

                // Owner still sees the intact row.
                rlsSet($c, 'tenant_id', $t['tenant'.$owner]);
                rlsSet($c, 'facility_id', $t['facility'.$owner]);
                rlsSet($c, 'user_id', $table === 'support_sessions' ? $chain[$owner]['users'] : '');
                expect($c->selectOne('select '.$keyColumn.' from '.$table.' where '.$keyColumn.' = ?', [$targetKey]))->not->toBeNull("$table row $owner survived the attack");
            }
        }
    });
});

it('documents the INSERT boundary: permissive WITH CHECK(true), backstopped by composite FKs', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = inventoryTenants($c);
        $chainB = seedTenantChain($c, $t['tenantB'], $t['facilityB']);

        // 1. The INSERT policy is deliberately permissive: WITH CHECK(true)
        //    (pg_policies stores it as a NULL qual).
        $insertPolicy = $c->selectOne(
            "select qual from pg_policies where schemaname = 'public' and tablename = 'patients' and cmd = 'INSERT'"
        );
        expect($insertPolicy?->qual)->toBeNull('patients INSERT policy is WITH CHECK(true) — documented boundary');

        // 2. A top-level cross-tenant INSERT (patients with tenant_id = B
        //    while in A context) SUCCEEDS at the RLS layer. INSERT isolation
        //    is the APPLICATION layer (tenant_id derived server-side from
        //    context; forged payloads rejected) — PostgreSQL enforces
        //    READ/UPDATE/DELETE, not INSERT. This documents the boundary.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        rlsSet($c, 'user_id', '');
        rlsSet($c, 'is_platform', 'false');

        $crossInsert = (string) Str::uuid();
        expect($c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$crossInsert, $t['tenantB'], $t['facilityB'], 'MRN-X', 'Cross Insert', '1990-01-01', 'female', 'active']
        ))->toBeTrue('cross-tenant top-level INSERT succeeds under WITH CHECK(true) — app-layer boundary');

        // The inserted B-owned row is invisible to A (read policies hold).
        expect($c->selectOne('select id from patients where id = ?', [$crossInsert]))->toBeNull('A cannot read the cross-inserted B row');

        // 3. The composite-FK backstop: a child row in tenant A referencing
        //    tenant B's parent FAILS structurally — cross-tenant children are
        //    impossible even under the permissive INSERT policy.
        expect(fn () => $c->insert(
            'insert into patient_identifiers (id, tenant_id, patient_id, type, value_encrypted, value_hash, status) values (?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $t['tenantA'], $chainB['patients'], 'national_id', 'enc', 'hash', 'active']
        ))->toThrow(QueryException::class);
    });
});
