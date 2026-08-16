<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * PROGRAM PHASE 1 — systematic database-layer tenancy verification.
 *
 * Unlike the representative isolation suites (DatabaseRowLevelSecurityTest,
 * ClinicalIsolationTest, ...), this suite iterates the FULL set of 62
 * tenant-owned tables: it seeds a complete two-tenant fixture chain and then
 * probes every table for cross-tenant SELECT/UPDATE/DELETE isolation as the
 * least-privilege `swasthya_app` role (NOBYPASSRLS) under transaction-local
 * claims. It also records the current RLS inventory (enabled/forced/policies)
 * and the deliberately permissive INSERT boundary, so any change to the
 * policy matrix becomes a visible, reviewed regression.
 */

/**
 * The 62 tables with RLS enabled (the documented scoped set).
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
    // Phase 3 slice 8 — pharmacy returns & reversals.
    'pharmacy_returns',
    // Phase 3 slice 4 — discharge & follow-up.
    'follow_ups',
    // Phase 3 slice 5 — billing refunds & adjustments.
    'refund_requests',
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
    // TENANT_ONLY
    'payers', 'mrn_counters', 'patient_identifiers', 'patient_contacts',
    'insurance_policies', 'patient_documents', 'consents',
    'patient_timeline_entries', 'diagnoses', 'clinical_notes',
    'prescriptions', 'prescription_lines', 'invoice_lines',
    'payment_allocations',
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
    'migrations', 'organizations', 'permissions', 'personal_access_tokens',
    'refresh_tokens', 'role_permissions', 'roles', 'users',
    // Pre-tenant public-route flows (Phase 2): hash-only payloads, same
    // pattern as refresh_tokens.
    'mfa_challenges', 'password_reset_tokens',
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
        'inventory_items' => ['reorder_level', '12'],
        'inventory_movements' => ['reason', 'upd'],
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
        'payment_allocations' => ['amount_minor', '1'],
        'payments' => ['provider_ref', 'upd'],
        'pharmacy_returns' => ['reason_note', 'upd'],
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

    foreach (['tenantA', 'tenantB'] as $tenant) {
        $c->insert('insert into organizations (id, name, code, status) values (?, ?, ?, ?)', [$t[$tenant], 'Tenant '.$tenant, 'code-'.$suffix.'-'.strtolower($tenant), 'active']);
    }

    foreach (['facilityA', 'facilityB'] as $key) {
        $tenant = $key === 'facilityA' ? 'tenantA' : 'tenantB';
        $c->insert('insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)', [$t[$key], $t[$tenant], 'Facility '.$key, 'code-'.strtolower($key), 'active', 'UTC', '{}', '{}']);
    }

    return $t;
}

it('records the current RLS inventory: 62 scoped tables enabled + FORCED, 15 unscoped off', function () {
    $rows = DB::connection('pgsql')->select(
        'select c.relname as table_name, c.relrowsecurity::text as enabled, c.relforcerowsecurity::text as forced
         from pg_class c
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = ? and c.relkind = ?',
        ['public', 'r']
    );

    $byName = collect($rows)->keyBy('table_name');

    foreach (RLS_SCOPED_TABLES as $table) {
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
    foreach (RLS_SCOPED_TABLES as $table) {
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

it('denies cross-tenant SELECT, UPDATE, and DELETE on all 62 tenant-owned tables — two-sided', function () {
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
