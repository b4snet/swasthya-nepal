-- ============================================================================
-- Swasthya RLS benchmark queries (STAGING_READINESS_REPORT.md §9).
-- Executed by load-benchmark.sh under two modes:
--   RLS     — as swasthya_app with tenant/facility/user GUCs (real behavior)
--   BASELINE— as the owner with row-level security disabled (raw cost)
--
-- psql variables are passed quoted (:'var' -> 'uuid-string') by the script.
-- ============================================================================
\set ON_ERROR_STOP off
\timing on

-- Q1  patient by id (index: pk + RLS predicate on tenant_id)
SELECT full_name, mrn FROM patients WHERE id = :'pid' AND tenant_id = :'tid';
SELECT full_name, mrn FROM patients WHERE id = :'pid' AND tenant_id = :'tid';
SELECT full_name, mrn FROM patients WHERE id = :'pid' AND tenant_id = :'tid';

-- Q2  patient name search (trgm index + tenant predicate)
SELECT id, full_name FROM patients
WHERE tenant_id = :'tid' AND full_name ILIKE 'Load Patient 77%'
ORDER BY full_name LIMIT 20;
SELECT id, full_name FROM patients
WHERE tenant_id = :'tid' AND full_name ILIKE 'Load Patient 77%'
ORDER BY full_name LIMIT 20;

-- Q3  appointment by id
SELECT status FROM appointments WHERE id = :'aid' AND tenant_id = :'tid';
SELECT status FROM appointments WHERE id = :'aid' AND tenant_id = :'tid';

-- Q4  encounters for a patient
SELECT id, status FROM encounters WHERE tenant_id = :'tid' AND patient_id = :'pid';
SELECT id, status FROM encounters WHERE tenant_id = :'tid' AND patient_id = :'pid';

-- Q5  invoices for a patient
SELECT id, status, total_minor FROM invoices WHERE tenant_id = :'tid' AND patient_id = :'pid';
SELECT id, status, total_minor FROM invoices WHERE tenant_id = :'tid' AND patient_id = :'pid';

-- Q6  provider day schedule lookup
SELECT id, status FROM appointments
WHERE tenant_id = :'tid' AND provider_staff_id = :'sid'
  AND starts_at >= :'day'::timestamptz AND starts_at < :'day'::timestamptz + interval '1 day';
SELECT id, status FROM appointments
WHERE tenant_id = :'tid' AND provider_staff_id = :'sid'
  AND starts_at >= :'day'::timestamptz AND starts_at < :'day'::timestamptz + interval '1 day';

-- Q7  invoice by id with lines
SELECT i.status, count(il.id) FROM invoices i
JOIN invoice_lines il ON il.invoice_id = i.id
WHERE i.id = :'iid' AND i.tenant_id = :'tid'
GROUP BY i.status;
SELECT i.status, count(il.id) FROM invoices i
JOIN invoice_lines il ON il.invoice_id = i.id
WHERE i.id = :'iid' AND i.tenant_id = :'tid'
GROUP BY i.status;

-- Q8  tenant-scoped insert (a fresh patient row)
INSERT INTO patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, consent_summary, lock_version)
VALUES (gen_random_uuid(), :'tid', :'fid', 'BENCH-INS', 'Bench Insert', '1990-01-01', 'female', 'active', '{}', 0);
INSERT INTO patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, consent_summary, lock_version)
VALUES (gen_random_uuid(), :'tid', :'fid', 'BENCH-INS2', 'Bench Insert 2', '1990-01-01', 'female', 'active', '{}', 0);
INSERT INTO patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status, consent_summary, lock_version)
VALUES (gen_random_uuid(), :'tid', :'fid', 'BENCH-INS3', 'Bench Insert 3', '1990-01-01', 'female', 'active', '{}', 0);

-- Q9  tenant-scoped update (one row, with the RLS WITH CHECK)
UPDATE patients SET full_name = 'Bench Insert Updated'
WHERE id = (SELECT id FROM patients WHERE tenant_id = :'tid' AND mrn = 'BENCH-INS' LIMIT 1) AND tenant_id = :'tid';

-- Q10 tenant-scoped delete of the freshly inserted rows (applies to this run)
DELETE FROM patients WHERE tenant_id = :'tid' AND mrn IN ('BENCH-INS', 'BENCH-INS2', 'BENCH-INS3');
