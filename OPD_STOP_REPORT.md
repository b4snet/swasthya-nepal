# OPD + Clinical Encounters — STOP Report

**Date:** 2026-09-04
**Phase:** OPD + Clinical Encounters Hardening

## Scope

Patient-level longitudinal problem list with lifecycle management (active → resolved / ruled out), plus verification that the clinical encounters spine (vitals, allergies, order sets, walk-in encounters, note amendment, CAS sign) all function correctly.

## Implemented

### Problem List (`Problem` model + CRUD)
- **`backend/app/Models/Problem.php`** — Patient-level longitudinal condition model with statuses: `active`, `resolved`, `ruled_out`. Records onset date, resolved date, resolved reason, and originating encounter.
- **`backend/database/migrations/2026_09_04_500000_create_problems_table.php`** — `problems` table with RLS policies (SELECT/INSERT/UPDATE), partial index on `[patient_id, status]`, and `[tenant_id, patient_id]`.
- **`backend/app/Http/Controllers/Api/ProblemController.php`** — Four endpoints:
  - `GET /patients/{patient}/problems` — List problems filtered by status (default: active)
  - `POST /patients/{patient}/problems` — Add a new problem to the patient's list
  - `POST /patients/{patient}/problems/{problem}/resolve` — CAS resolve with reason
  - `POST /patients/{patient}/problems/{problem}/rule-out` — CAS rule-out with reason
- **Routes** registered in `backend/routes/api.php` with `authorize:patient:view` (read) and `authorize:encounter:document` (write).

### Previously Implemented (verified passing)

| Feature | File | Status |
|---|---|---|
| Encounter sign CAS | `EncounterController.php` | ✅ |
| Walk-in encounters | `EncounterController.php` (startWalkIn) | ✅ |
| Note amendment | `EncounterController.php` (amendNote) | ✅ |
| OPD vitals | `EncounterController.php` (storeVitals, vitals) | ✅ |
| Patient allergy CRUD | `PatientAllergyController.php` | ✅ |
| Order sets (CRUD, publish, retire, apply) | `OrderSetController.php` | ✅ |

## Tests

**`tests/Feature/ClinicalEncountersEnterpriseTest.php`** — 6 tests, 42 assertions, all passing:

1. `it records and retrieves encounter vitals` — Creates vitals for an encounter, verifies retrieval
2. `it creates, lists, and resolves patient allergies` — Full allergy lifecycle
3. `it creates, lists, resolves, and rules out problems` — Problem list lifecycle with CAS
4. `it creates, publishes, applies, and retires order sets` — Full order set lifecycle + provenance
5. `it creates a walk-in encounter without appointment` — Walk-in encounter creation
6. `it amends a signed note creating a child version` — Note amendment with parent chain

## Regression Gate

All 45 tests across 6 suites pass:

- `AppointmentBookingTest` — ✅
- `SchedulingEnterpriseTest` — ✅
- `QueueEnterpriseTest` — ✅
- `EncounterClinicalTest` — ✅
- `ClinicalWorkflowE2ETest` — ✅
- `ClinicalEncountersEnterpriseTest` — ✅

## Known Issues

- `org_admin` role assignment requires explicit `$facility` parameter for walk-in endpoint to resolve RLS context correctly.
- Pre-existing baseline failures (out of scope): `NepalFinanceTest`, `NepalFinanceE2ETest`, `DoctorScheduleTest`.

## Git Status

No commits made per rules.
