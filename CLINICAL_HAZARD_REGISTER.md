# SWASTHYA — Clinical Hazard Register

**Version:** 1.0
**Date:** 2026-09-04
**Status:** DRAFT (requires clinical governance review)

---

## Hazard Analysis Methodology

This register uses a structured approach based on:
- Software-controlled hazards identified from code inspection
- Clinical workflow hazards identified from architecture analysis
- Concurrency hazards identified from testing
- Security hazards identified from penetration analysis

**Severity scale**: Critical (patient harm potential), High (data integrity risk), Medium (operational risk), Low (usability risk)

---

## Hazard Register

### H-01: Wrong-Patient Clinical Documentation

| Aspect | Detail |
|---|---|
| Hazard | Clinician documents in wrong patient's encounter |
| Affected workflow | Encounter → Note, Diagnosis, Prescription |
| Potential consequence | Incorrect clinical record; wrong treatment decisions |
| Existing control | AccessCheck::scoped() + ownership checks + RLS (3 layers) |
| Detection | Automated tests (ClinicalIsolationTest, IDOR tests) |
| Mitigation | Triple-layer defense; no global scopes needed due to UUID + RLS |
| Residual risk | LOW — 3 independent defense layers |
| Owner | Security Owner |
| Status | MITIGATED |

### H-02: Wrong-Encounter Clinical Action

| Aspect | Detail |
|---|---|
| Hazard | Clinical action attached to wrong encounter |
| Affected workflow | Note, Diagnosis, Prescription, Order creation |
| Potential consequence | Clinical record misattribution |
| Existing control | encounter_id ownership checks in controllers |
| Detection | Automated tests verify cross-encounter access denied |
| Mitigation | AccessCheck + encounter_id verification |
| Residual risk | LOW |
| Owner | Security Owner |
| Status | MITIGATED |

### H-03: Silent Modification of Signed Records

| Aspect | Detail |
|---|---|
| Hazard | Signed clinical note or encounter modified without authorization |
| Affected workflow | Note signing, Encounter signing |
| Potential consequence | Loss of clinical truth; patient safety risk |
| Existing control | No update/delete routes; guardNotSigned(); CAS; no SoftDeletes |
| Detection | Immutability tests; regression gate |
| Mitigation | Structural enforcement at route + controller + model level |
| Residual risk | VERY LOW — structurally impossible via application |
| Owner | Clinical Safety Owner |
| Status | MITIGATED |

### H-04: Lost Clinical Note

| Aspect | Detail |
|---|---|
| Hazard | Clinical note deleted or lost |
| Affected workflow | Note lifecycle |
| Potential consequence | Incomplete clinical record |
| Existing control | No delete routes; no SoftDeletes; append-only creation |
| Detection | Automated tests verify notes persist |
| Mitigation | Structural prevention of deletion |
| Residual risk | VERY LOW |
| Owner | Clinical Safety Owner |
| Status | MITIGATED |

### H-05: Unauthorized Clinical Access

| Aspect | Detail |
|---|---|
| Hazard | User accesses clinical records outside their authorization scope |
| Affected workflow | All clinical read operations |
| Potential consequence | Privacy breach; HIPAA/NPHL violation |
| Existing control | RBAC + AccessCheck + RLS + tenant/facility scoping |
| Detection | Authorization tests; IDOR tests; RLS tests |
| Mitigation | 4-layer defense (middleware + controller + model + database) |
| Residual risk | LOW |
| Owner | Security Owner |
| Status | MITIGATED |

### H-06: Duplicate Clinical Order

| Aspect | Detail |
|---|---|
| Hazard | Same order submitted multiple times |
| Affected workflow | CPOE order creation |
| Potential consequence | Duplicate clinical actions; patient harm |
| Existing control | Idempotent creation; unique constraints |
| Detection | Concurrency tests; idempotency tests |
| Mitigation | Database constraints + application logic |
| Residual risk | LOW |
| Owner | Clinical Safety Owner |
| Status | MITIGATED |

### H-07: Incorrect Result Association

| Aspect | Detail |
|---|---|
| Hazard | Lab/radiology result attached to wrong patient or order |
| Affected workflow | Result → Order → Encounter → Patient |
| Potential consequence | Wrong treatment based on wrong results |
| Existing control | FK relationships; RLS; AccessCheck |
| Detection | Integration tests verify result provenance |
| Mitigation | Database-level relationship enforcement |
| Residual risk | LOW (increases with external lab integration) |
| Owner | Clinical Safety Owner |
| Status | MITIGATED |

### H-08: Concurrent Clinical Edit Data Loss

| Aspect | Detail |
|---|---|
| Hazard | Two clinicians edit simultaneously; one's changes lost |
| Affected workflow | Note editing, Prescription editing |
| Potential consequence | Lost clinical documentation |
| Existing control | CAS on lock_version; 409 Conflict on stale read |
| Detection | Concurrency tests (QueueEnterpriseTest, ClinicalEncountersEnterpriseTest) |
| Mitigation | Optimistic locking with CAS |
| Residual risk | LOW — user receives clear conflict notification |
| Owner | Engineering Owner |
| Status | MITIGATED |

### H-09: PHI Exposure in Logs

| Aspect | Detail |
|---|---|
| Hazard | Patient-identifiable information in application logs |
| Affected workflow | Notification adapters (SMS, Push) |
| Potential consequence | Privacy breach; regulatory violation |
| Existing control | AuditLogger excludes PHI; API controllers have no Log:: calls |
| Detection | PHI logging sweep (this assessment) |
| Mitigation | **GAP**: SmsAdapter and PushAdapter log message bodies |
| Residual risk | MEDIUM — pre-existing; requires remediation |
| Owner | Security Owner |
| Status | OPEN — requires fix |

### H-10: Stale Clinical Context

| Aspect | Detail |
|---|---|
| Hazard | Clinician submits action against outdated patient/encounter context |
| Affected workflow | All clinical write operations |
| Potential consequence | Wrong-patient or wrong-encounter action |
| Existing control | CAS on lock_version detects stale reads; AccessCheck re-verifies on each request |
| Detection | Concurrency tests |
| Mitigation | Server-side verification on every request; no client-side context trust |
| Residual risk | LOW |
| Owner | Engineering Owner |
| Status | MITIGATED |

### H-11: Unsigned Record Appearing Signed

| Aspect | Detail |
|---|---|
| Hazard | Draft note displayed as if it were the authoritative signed record |
| Affected workflow | Clinical documentation display |
| Potential consequence | Clinician acts on unverified information |
| Existing control | Status field (draft/signed/amended); UI should distinguish states |
| Detection | UI review (requires hospital UAT) |
| Mitigation | Status field is authoritative; UI rendering requires validation |
| Residual risk | MEDIUM — requires UI validation |
| Owner | UX Architect |
| Status | REQUIRES HOSPITAL UAT |

### H-12: Unsafe Copy-Forward / Auto-Fill

| Aspect | Detail |
|---|---|
| Hazard | Previous encounter data auto-populated as current observation |
| Affected workflow | Clinical documentation |
| Potential consequence | Stale information treated as current fact |
| Existing control | No auto-fill implemented yet |
| Detection | N/A — not yet implemented |
| Mitigation | Future implementation must distinguish historical from current |
| Residual risk | LOW (not yet implemented) |
| Owner | Clinical Safety Owner |
| Status | DEFERRED |

---

## Summary

| Status | Count |
|---|---|
| MITIGATED | 10 |
| OPEN | 1 (H-09: PHI in notification logs) |
| REQUIRES HOSPITAL UAT | 1 (H-11: UI state display) |
| DEFERRED | 1 (H-12: copy-forward safety) |
| **Total hazards** | **12** |

---

## Open Actions

| ID | Hazard | Action Required | Owner | Priority |
|---|---|---|---|---|
| ACT-01 | H-09 | Remove PHI from SmsAdapter/PushAdapter log payloads | Security Owner | HIGH |
| ACT-02 | H-11 | Validate UI distinguishes draft/signed/amended states | UX Architect | MEDIUM |
| ACT-03 | All | Clinical governance review of this register | Clinical Safety Owner | HIGH |
