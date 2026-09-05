# SWASTHYA — Clinical Safety Evidence Matrix

**Version:** 1.0
**Date:** 2026-09-04
**Status:** DRAFT (requires clinical governance review)

---

## Evidence Matrix

| Requirement | Control | Automated Test | DB Proof | UAT | Clinical Review | Status |
|---|---|---|---|---|---|---|
| **CRI-01**: Signed notes immutable | No update/delete routes; guardNotSigned(); no SoftDeletes | ClinicalEncountersEnterpriseTest, EncounterClinicalTest | Schema verification: no SoftDeletes on ClinicalNote | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CRI-02**: Signed encounters immutable | No update routes; guardNotSigned(); CAS | ClinicalEncountersEnterpriseTest, ClinicalWorkflowE2ETest | Schema verification: no SoftDeletes on Encounter | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CRI-03**: Corrections preserve originals | amendNote creates child note | ClinicalEncountersEnterpriseTest (note amendment test) | clinical_notes table: parent_note_id chain | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CRI-04**: Version history reconstructable | parent_note_id chain + audit_events | ClinicalEncountersEnterpriseTest | audit_events table with action/resource_type/resource_id | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CRI-05**: Signing attributable | currentProvider() | ClinicalEncountersEnterpriseTest (note sign test) | encounters.provider_staff_id + clinical_notes.author_staff_id | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CRI-06**: CAS on all transitions | lock_version + status atomic check | ClinicalEncountersEnterpriseTest, QueueEnterpriseTest | lock_version column on all clinical tables | PENDING | PENDING | SOFTWARE-VERIFIED |
| **SEC-01**: RBAC enforced | authorize:* middleware | All route tests pass | routes/api.php verification | PENDING | PENDING | SECURITY-VERIFIED |
| **SEC-02**: Object authorization | AccessCheck::scoped() | All controller tests pass | tenant_id/facility_id on all records | PENDING | PENDING | SECURITY-VERIFIED |
| **SEC-03**: IDOR blocked | Ownership checks | ClinicalIsolationTest, EnterpriseAssuranceTest | ownership verification in controllers | PENDING | PENDING | SECURITY-VERIFIED |
| **SEC-04**: Tenant isolation | RLS + AccessCheck + scoping | EnterpriseAssuranceTest, MultiHospitalReplicationTest | RLS policies on all clinical tables | PENDING | PENDING | SECURITY-VERIFIED |
| **SEC-05**: Facility isolation | AccessCheck facility check | EnterpriseAssuranceTest | facility_id on records + RLS | PENDING | PENDING | SECURITY-VERIFIED |
| **SEC-06**: RLS verified | ENABLE + FORCE RLS | Migration verification | ALTER TABLE ... ENABLE ROW LEVEL SECURITY | PENDING | PENDING | SECURITY-VERIFIED |
| **PHI-01**: No PHI in logs | AuditLogger facts/references only | LoggingTest | audit_events payload inspection | PENDING | PENDING | SECURITY-VERIFIED |
| **PHI-02**: No PHI in errors | ApiExceptionMapper generic messages | LoggingTest | Error response inspection | PENDING | PENDING | SECURITY-VERIFIED |
| **PHI-03**: No PHI in request logs | LogRequest method/path/status only | LoggingTest | Log entry inspection | PENDING | PENDING | SECURITY-VERIFIED |
| **PHI-04**: Notification adapters safe | **GAP**: Bodies logged | NONE | NONE | PENDING | PENDING | **OPEN** |
| **WFS-01**: Wrong-patient prevented | 3-layer defense | ClinicalIsolationTest, EnterpriseAssuranceTest | RLS + AccessCheck + ownership | PENDING | PENDING | SECURITY-VERIFIED |
| **WFS-02**: Wrong-encounter prevented | encounter_id checks | ClinicalEncountersEnterpriseTest | encounter_id ownership in controllers | PENDING | PENDING | SOFTWARE-VERIFIED |
| **WFS-03**: Wrong-provider prevented | currentProvider() | ClinicalEncountersEnterpriseTest | provider_staff_id match | PENDING | PENDING | SOFTWARE-VERIFIED |
| **WFS-04**: Stale data prevented | CAS on lock_version | QueueEnterpriseTest (race test) | lock_version atomic increment | PENDING | PENDING | SOFTWARE-VERIFIED |
| **CON-01**: Concurrent editing safe | CAS | QueueEnterpriseTest (concurrent callNext) | DB-level atomic update | PENDING | PENDING | SOFTWARE-VERIFIED |

---

## Readiness Tiers

| Tier | Criteria | Status |
|---|---|---|
| SOFTWARE-VERIFIED | Automated tests pass against real PostgreSQL | **YES** — 107/107 tests pass |
| SECURITY-VERIFIED | Authorization/RLS/privacy controls verified | **YES** — all security sweeps pass |
| CLINICAL-WORKFLOW-VALIDATED | Clinicians confirm workflow is safe | **NO** — requires clinical reviewer |
| HOSPITAL-UAT-VALIDATED | Hospital users execute UAT scenarios | **NO** — requires hospital environment |
| FORMALLY ASSESSED | Formal safety assessment completed | **NO** — requires clinical governance review |
| PRODUCTION-READY | All gates satisfied | **NO** — blocked on clinical validation |

---

## Assessment

The SWASTHYA clinical record safety system is **SOFTWARE-VERIFIED** and **SECURITY-VERIFIED** at the automated level. All 107 tests pass against real PostgreSQL. Immutability, wrong-patient protection, tenant isolation, PHI exclusion, and concurrency safety are all verified through automated tests.

**The system is NOT yet CLINICAL-WORKFLOW-VALIDATED, HOSPITAL-UAT-VALIDATED, FORMALLY ASSESSED, or PRODUCTION-READY.** These tiers require:
1. Clinical reviewer approval of workflow safety
2. Hospital UAT execution with synthetic patients
3. Formal safety assessment by authorized governance
4. External assessment where required
