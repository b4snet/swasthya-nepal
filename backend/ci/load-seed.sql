-- ============================================================================
-- Swasthya RLS load-test seed (STAGING_READINESS_REPORT.md §9).
--
-- Generates a SYNTHETIC multi-tenant workload. No production data is used.
--
--   \set size 100000
--   psql -v size=100000 -f load-seed.sql
--
-- Layout: 20 organizations × 2 facilities = 40 facilities; 1 department,
-- 1 doctor, 1 service, 1 medication per facility. Then, per row n in
-- [0, size):
--   patients             size rows          (tenant n%20, facility n%40)
--   appointments         size/2 rows        (status completed)
--   encounters           size/4 rows        (signed)
--   diagnoses            size/8 rows
--   prescriptions        size/10 rows       (1 each)
--   prescription lines   size/5 rows        (2 per prescription)
--   charges              size/4 rows
--   invoices             size/8 rows        (issued, paid)
--   invoice lines        size/8 rows
--   payments             size/8 rows        (captured)
--   payment allocations  size/8 rows
-- ============================================================================
\set ON_ERROR_STOP on

-- Idempotent re-run: the load DB is disposable; wipe all workload rows first.
TRUNCATE payment_allocations, payments, invoice_lines, invoices, charges,
         prescription_lines, prescriptions, diagnoses, encounters, appointments,
         patients, medications, services, staff, departments, facilities,
         organizations CASCADE;

-- --- reference rows ----------------------------------------------------------
CREATE TEMP TABLE orgs AS SELECT i, gen_random_uuid() AS id FROM generate_series(0, 19) i;
CREATE TEMP TABLE facs AS SELECT i, gen_random_uuid() AS id FROM generate_series(0, 39) i;

INSERT INTO organizations (id, name, code, status, currency, timezone, locale, tax_config, settings)
SELECT id, 'Load Org ' || i, 'load-org-' || i, 'active', 'NPR', 'Asia/Kathmandu', 'en', '{}', '{}'
FROM orgs;

INSERT INTO facilities (id, tenant_id, name, code, status, timezone, address, settings)
SELECT f.id, o.id, 'Load Facility ' || f.i, 'load-fac-' || f.i, 'active', 'Asia/Kathmandu', '{}', '{}'
FROM facs f JOIN orgs o ON o.i = f.i % 20;

INSERT INTO departments (id, tenant_id, facility_id, name, code, status)
SELECT gen_random_uuid(), o.id, f.id, 'General OPD', 'dept-' || f.i, 'active'
FROM facs f JOIN orgs o ON o.i = f.i % 20;

INSERT INTO staff (id, tenant_id, facility_id, department_id, employee_code, full_name, designation, status, settings)
SELECT gen_random_uuid(), o.id, f.id, d.id, 'LDOC-' || f.i, 'Load Doctor ' || f.i, 'Consultant', 'active', '{}'
FROM facs f
JOIN orgs o ON o.i = f.i % 20
JOIN departments d ON d.facility_id = f.id;

INSERT INTO services (id, tenant_id, facility_id, name, code, service_type, status, default_duration_minutes, default_charge_minor, currency)
SELECT gen_random_uuid(), o.id, f.id, 'OPD Consultation', 'svc-' || f.i, 'opd_consultation', 'active', 30, 5000, 'NPR'
FROM facs f JOIN orgs o ON o.i = f.i % 20;

INSERT INTO medications (id, tenant_id, facility_id, code, generic_name, strength, form, unit, price_minor, currency, is_controlled, status, lock_version)
SELECT gen_random_uuid(), o.id, f.id, 'med-' || f.i, 'Paracetamol', '500mg', 'tablet', 'tab', 3000, 'NPR', false, 'active', 0
FROM facs f JOIN orgs o ON o.i = f.i % 20;

-- --- workload rows ------------------------------------------------------------
CREATE TEMP TABLE s AS
SELECT n,
       gen_random_uuid() AS patient_id,
       gen_random_uuid() AS appointment_id,
       gen_random_uuid() AS encounter_id,
       gen_random_uuid() AS diagnosis_id,
       gen_random_uuid() AS prescription_id,
       gen_random_uuid() AS line_id,
       gen_random_uuid() AS charge_id,
       gen_random_uuid() AS invoice_id,
       gen_random_uuid() AS invoice_line_id,
       gen_random_uuid() AS payment_id,
       gen_random_uuid() AS alloc_id,
       n % 20 AS org_idx,
       n % 40 AS fac_idx
FROM generate_series(0, :size - 1) AS n;

INSERT INTO patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, consent_summary, lock_version)
SELECT s.patient_id, o.id, f.id,
       'MRN-' || lpad(s.n::text, 10, '0'),
       'Load Patient ' || s.n,
       date '1970-01-01' + (s.n % 18000),
       CASE WHEN s.n % 2 = 0 THEN 'female' ELSE 'male' END,
       'active', '{}', 0
FROM s JOIN orgs o ON o.i = s.org_idx JOIN facs f ON f.i = s.fac_idx;

INSERT INTO appointments (id, tenant_id, facility_id, patient_id, provider_staff_id, service_id,
                          appointment_type, starts_at, ends_at, status, source)
SELECT s.appointment_id, o.id, f.id, s.patient_id, st.id, sv.id, 'opd',
       timestamp '2026-01-05 09:00:00+05:45' + (s.n * interval '1 minute'),
       timestamp '2026-01-05 09:30:00+05:45' + (s.n * interval '1 minute'),
       'completed', 'counter'
FROM s
JOIN orgs o ON o.i = s.org_idx
JOIN facs f ON f.i = s.fac_idx
JOIN staff st ON st.facility_id = f.id
JOIN services sv ON sv.facility_id = f.id
WHERE s.n < :size / 2;

INSERT INTO encounters (id, tenant_id, facility_id, patient_id, appointment_id, provider_staff_id,
                        type, status, started_at, lock_version)
SELECT s.encounter_id, o.id, f.id, s.patient_id, s.appointment_id, st.id,
       'opd', 'signed',
       timestamp '2026-01-05 09:05:00+05:45' + (s.n * interval '1 minute'),
       0
FROM s
JOIN orgs o ON o.i = s.org_idx
JOIN facs f ON f.i = s.fac_idx
JOIN staff st ON st.facility_id = f.id
WHERE s.n < :size / 4;

INSERT INTO diagnoses (id, tenant_id, encounter_id, code, description, diagnosis_type, is_primary, status)
SELECT s.diagnosis_id, o.id, s.encounter_id, 'J11.1', 'Influenza with other respiratory manifestations',
       'final', true, 'active'
FROM s JOIN orgs o ON o.i = s.org_idx
WHERE s.n < :size / 8;

INSERT INTO prescriptions (id, tenant_id, patient_id, encounter_id, prescriber_staff_id, status, lock_version)
SELECT s.prescription_id, o.id, s.patient_id, s.encounter_id, st.id, 'dispensed', 0
FROM s
JOIN orgs o ON o.i = s.org_idx
JOIN facs f ON f.i = s.fac_idx
JOIN staff st ON st.facility_id = f.id
WHERE s.n < :size / 10;

-- 2 lines per prescription: line n references prescription floor(n/2), same tenant.
CREATE TEMP TABLE sl AS
SELECT n, n / 2 AS p_idx, gen_random_uuid() AS line_id FROM generate_series(0, :size / 5 - 1) n;

INSERT INTO prescription_lines (id, tenant_id, prescription_id, medication_id, dose, route, frequency,
                                duration, quantity_minor, instructions, status, line_no)
SELECT sl.line_id, o.id, s.prescription_id, m.id, '500mg', 'oral', 'TDS', '3 days', 9, 'After food', 'dispensed',
       CASE WHEN sl.n % 2 = 0 THEN 1 ELSE 2 END
FROM sl
JOIN s ON s.n = sl.p_idx
JOIN orgs o ON o.i = s.org_idx
JOIN facs f ON f.i = s.fac_idx
JOIN medications m ON m.facility_id = f.id;

INSERT INTO charges (id, tenant_id, facility_id, patient_id, source_type, encounter_id, prescription_id,
                     description, amount_minor, currency, tax_rate_bps, status, charged_at)
SELECT s.charge_id, o.id, f.id, s.patient_id, 'encounter', s.encounter_id, NULL,
       'OPD Consultation', 5000, 'NPR', 0, 'posted',
       timestamp '2026-01-05 09:10:00+05:45' + (s.n * interval '1 minute')
FROM s JOIN orgs o ON o.i = s.org_idx JOIN facs f ON f.i = s.fac_idx
WHERE s.n < :size / 4;

INSERT INTO invoices (id, tenant_id, facility_id, patient_id, invoice_number, status, total_minor,
                      total_tax_minor, paid_minor, lock_version)
SELECT s.invoice_id, o.id, f.id, s.patient_id, 'INV-' || lpad(s.n::text, 10, '0'),
       'paid', 8000, 0, 8000, 0
FROM s JOIN orgs o ON o.i = s.org_idx JOIN facs f ON f.i = s.fac_idx
WHERE s.n < :size / 8;

INSERT INTO invoice_lines (id, tenant_id, invoice_id, charge_id, description, amount_minor, tax_minor, line_no)
SELECT s.invoice_line_id, o.id, s.invoice_id, s.charge_id, 'OPD Consultation', 8000, 0, 1
FROM s JOIN orgs o ON o.i = s.org_idx
WHERE s.n < :size / 8;

INSERT INTO payments (id, tenant_id, facility_id, patient_id, method, provider_ref, amount_minor, currency,
                      status, idempotency_key, received_at)
SELECT s.payment_id, o.id, f.id, s.patient_id, 'cash', NULL, 8000, 'NPR', 'captured',
       'load-pay-' || s.n,
       timestamp '2026-01-05 09:15:00+05:45' + (s.n * interval '1 minute')
FROM s JOIN orgs o ON o.i = s.org_idx JOIN facs f ON f.i = s.fac_idx
WHERE s.n < :size / 8;

INSERT INTO payment_allocations (id, tenant_id, payment_id, invoice_id, amount_minor, allocated_at)
SELECT s.alloc_id, o.id, s.payment_id, s.invoice_id, 8000,
       timestamp '2026-01-05 09:16:00+05:45' + (s.n * interval '1 minute')
FROM s JOIN orgs o ON o.i = s.org_idx
WHERE s.n < :size / 8;

-- Housekeeping
ANALYZE;
SELECT 'seeded' AS status, count(*) AS patients FROM patients;
