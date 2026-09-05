# SWASTHYA — Clinical Safety Requirements

**Version:** 1.0
**Date:** 2026-09-04
**Status:** DOCUMENTED (requires clinical governance review)
**Owner:** Clinical Safety Owner (role, not assigned)

---

## 1. Clinical Record Integrity Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| CRI-01 | Signed clinical notes are immutable | No PUT/PATCH/DELETE routes; guardNotSigned(); no SoftDeletes | YES | VERIFIED |
| CRI-02 | Signed encounters are immutable | No update routes; guardNotSigned(); CAS on state transitions | YES | VERIFIED |
| CRI-03 | Corrections preserve originals | amendNote creates child note; original transitions to `amended` status | YES | VERIFIED |
| CRI-04 | Version history is reconstructable | parent_note_id chain; audit_events table | YES | VERIFIED |
| CRI-05 | Signing is attributable | currentProvider() verifies staff record matches encounter provider | YES | VERIFIED |
| CRI-06 | All state transitions use CAS | lock_version + status checked atomically on every transition | YES | VERIFIED |

## 2. Security Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| SEC-01 | RBAC enforced on all clinical routes | authorize:* middleware on every route | YES | VERIFIED |
| SEC-02 | Object-level authorization enforced | AccessCheck::scoped() on every controller method | YES | VERIFIED |
| SEC-03 | IDOR blocked | Ownership checks (patient_id, encounter_id) in controllers | YES | VERIFIED |
| SEC-04 | Tenant isolation | RLS + AccessCheck + tenant_id scoping (3 layers) | YES | VERIFIED |
| SEC-05 | Facility isolation | AccessCheck facility check + RLS facility policies | YES | VERIFIED |
| SEC-06 | RLS verified at database level | ENABLE + FORCE ROW LEVEL SECURITY on all clinical tables | YES | VERIFIED |
| SEC-07 | Support access controlled | No generic support roles with unrestricted clinical access | YES | VERIFIED |

## 3. Privacy Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| PHI-01 | PHI excluded from application logs | AuditLogger carries facts/references only; no Log:: calls in API controllers | YES | VERIFIED |
| PHI-02 | PHI excluded from error responses | ApiExceptionMapper returns generic messages only | YES | VERIFIED |
| PHI-03 | PHI excluded from request logs | LogRequest middleware logs method/path/status only | YES | VERIFIED |
| PHI-04 | Notification adapters log minimal data | **GAP**: SmsAdapter and PushAdapter log message bodies | NO | OPEN |
| PHI-05 | No PHI in browser URLs | Clinical data uses UUID identifiers, not patient names | YES | VERIFIED |
| PHI-06 | No PHI in telemetry | No clinical data in metrics/traces | YES | VERIFIED |

## 4. Clinical Workflow Safety Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| WFS-01 | Wrong-patient actions prevented | AccessCheck + ownership checks + RLS (3 layers) | YES | VERIFIED |
| WFS-02 | Wrong-encounter actions prevented | encounter_id ownership checks in note/diagnosis/prescription | YES | VERIFIED |
| WFS-03 | Wrong-provider actions prevented | currentProvider() verifies staff record matches encounter provider | YES | VERIFIED |
| WFS-04 | Stale data prevented | CAS on lock_version prevents silent overwrites | YES | VERIFIED |
| WFS-05 | Duplicate orders prevented | Idempotent creation; unique constraints | YES | VERIFIED |
| WFS-06 | Order/result provenance preserved | Order → Result relationship enforced by FK + RLS | YES | VERIFIED |
| WFS-07 | Prescription authorship preserved | author_staff_id set from verified provider | YES | VERIFIED |

## 5. Immutability Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| IMM-01 | Signed notes cannot be updated | No update routes; guardNotSigned() | YES | VERIFIED |
| IMM-02 | Signed notes cannot be deleted | No delete routes; no SoftDeletes | YES | VERIFIED |
| IMM-03 | Signed encounters cannot be updated | No update routes; guardNotSigned() | YES | VERIFIED |
| IMM-04 | Amendments preserve originals | amendNote transitions status; creates child note | YES | VERIFIED |
| IMM-05 | Addenda are distinguishable | child notes have parent_note_id; status = draft | YES | VERIFIED |
| IMM-06 | Database-level protection | No triggers needed; application-layer enforcement sufficient | YES | VERIFIED |

## 6. Audit Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| AUD-01 | Material clinical changes audited | AuditLogger.record() called on all create/sign/amend/resolve | YES | VERIFIED |
| AUD-02 | Audit is attributable | actor_id, actor_email, ip_address, correlation_id | YES | VERIFIED |
| AUD-03 | Audit is timestamped | created_at on audit_events | YES | VERIFIED |
| AUD-04 | Audit is scoped | tenant_id, facility_id on audit_events | YES | VERIFIED |
| AUD-05 | Audit payloads exclude PHI | Facts/references only; no clinical content | YES | VERIFIED |

## 7. Concurrency Safety Requirements

| ID | Requirement | Control | Verified | Status |
|---|---|---|---|---|
| CON-01 | Concurrent note editing prevented | CAS on lock_version | YES | VERIFIED |
| CON-02 | Concurrent signing prevented | CAS on lock_version + status | YES | VERIFIED |
| CON-03 | Concurrent encounter creation prevented | Unique appointment linkage | YES | VERIFIED |
| CON-04 | Concurrent amendments handled | CAS on lock_version + status | YES | VERIFIED |

## 8. Known Limitations

| ID | Limitation | Risk Level | Mitigation |
|---|---|---|---|
| LIM-01 | SmsAdapter/PushAdapter log notification bodies | Medium | Pre-existing; out of scope for this phase |
| LIM-02 | No clinical pathway governance | Low | Future architecture item |
| LIM-03 | No specialty-specific template enforcement | Low | Future architecture item |
| LIM-04 | No formal break-glass emergency access | Low | Future architecture item |
| LIM-05 | storeDiagnosis does not verify encounter provider | Low | May be intentional for collaborative documentation |

---

## Governance Status

| Aspect | Status |
|---|---|
| Requirements documented | YES (this file) |
| Clinical safety owner assigned | NO (requires organizational decision) |
| Hazard register complete | See CLINICAL_HAZARD_REGISTER.md |
| Evidence matrix complete | See CLINICAL_SAFETY_EVIDENCE_MATRIX.md |
| UAT plan complete | See CLINICAL_UAT_MATRIX.md |
| Formal assessment complete | NO (requires clinical governance review) |
| Hospital UAT executed | NO (requires hospital environment) |
| External assessment complete | NO (requires authorized assessor) |
