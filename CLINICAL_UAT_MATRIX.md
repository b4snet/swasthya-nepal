# SWASTHYA — Clinical UAT Matrix

**Version:** 1.0
**Date:** 2026-09-04
**Status:** PLAN ONLY (not executed)

---

## UAT Environment Requirements

| Requirement | Status |
|---|---|
| Synthetic patient database | REQUIRED |
| Multiple user roles (reception, nurse, clinician, billing) | REQUIRED |
| Multiple facilities | REQUIRED |
| Real PostgreSQL instance | REQUIRED |
| Clinical reviewer approval | REQUIRED |

---

## UAT Scenarios

### UAT-01: General Consultation Workflow

| Aspect | Detail |
|---|---|
| Scenario | Patient arrives → check-in → encounter opens → vitals → note → diagnosis → prescription → sign → follow-up |
| Actor | Nurse → Clinician → Billing |
| Precondition | Active patient, available clinician |
| Steps | 1. Reception checks in patient<br>2. Nurse records vitals<br>3. Clinician opens encounter<br>4. Clinician documents complaint/assessment<br>5. Clinician enters diagnosis<br>6. Clinician prescribes medication<br>7. Clinician signs encounter<br>8. Follow-up appointment created |
| Expected outcome | Encounter signed; billing has correct source; follow-up scheduled |
| Safety risk | Wrong-patient action; unsigned record appearing signed |
| Status | NOT EXECUTED |

### UAT-02: Signed Record Immutability

| Aspect | Detail |
|---|---|
| Scenario | Sign a note → attempt to edit → verify rejection |
| Actor | Clinician |
| Precondition | Open encounter with draft note |
| Steps | 1. Clinician creates note<br>2. Clinician signs note<br>3. Attempt to modify signed note (should fail)<br>4. Attempt to delete signed note (should fail)<br>5. Verify original content preserved |
| Expected outcome | All modification attempts rejected; original content intact |
| Safety risk | Silent modification of signed record |
| Status | NOT EXECUTED |

### UAT-03: Note Amendment

| Aspect | Detail |
|---|---|
| Scenario | Amend a signed note → verify original preserved |
| Actor | Clinician |
| Precondition | Encounter with signed note |
| Steps | 1. Clinician initiates amendment<br>2. Clinician provides corrected content + reason<br>3. System creates child note<br>4. Original note status → amended<br>5. Verify original content still accessible<br>6. Verify audit trail |
| Expected outcome | Original preserved; child note created; audit recorded |
| Safety risk | Original content lost; amendment not attributable |
| Status | NOT EXECUTED |

### UAT-04: Wrong-Patient Protection

| Aspect | Detail |
|---|---|
| Scenario | Attempt clinical action on wrong patient |
| Actor | Clinician (or attacker) |
| Precondition | Two patients in different facilities |
| Steps | 1. Obtain patient A's encounter UUID<br>2. Switch to patient B's context<br>3. Attempt to add note to patient A's encounter<br>4. Verify denial<br>5. Attempt to view patient A's results<br>6. Verify denial |
| Expected outcome | All cross-patient actions denied |
| Safety risk | Wrong-patient documentation; wrong-patient orders |
| Status | NOT EXECUTED |

### UAT-05: Wrong-Encounter Protection

| Aspect | Detail |
|---|---|
| Scenario | Attempt clinical action on wrong encounter |
| Actor | Clinician |
| Precondition | Two encounters for same patient |
| Steps | 1. Open encounter A<br>2. Obtain encounter B's UUID<br>3. Attempt to add diagnosis to encounter B<br>4. Verify denial |
| Expected outcome | Cross-encounter actions denied |
| Safety risk | Clinical misattribution |
| Status | NOT EXECUTED |

### UAT-06: Unauthorized Signing

| Aspect | Detail |
|---|---|
| Scenario | Non-provider attempts to sign encounter |
| Actor | Nurse / Admin |
| Precondition | Encounter with signed note |
| Steps | 1. Nurse attempts to sign encounter<br>2. Verify 403 denial<br>3. Admin attempts to sign encounter<br>4. Verify 403 denial |
| Expected outcome | Only encounter provider can sign |
| Safety risk | Unauthorized clinical finalization |
| Status | NOT EXECUTED |

### UAT-07: Concurrent Note Editing

| Aspect | Detail |
|---|---|
| Scenario | Two clinicians edit same note simultaneously |
| Actor | Clinician A + Clinician B |
| Precondition | Open encounter with draft note |
| Steps | 1. Clinician A loads note<br>2. Clinician B loads same note<br>3. Clinician A saves changes<br>4. Clinician B saves changes<br>5. Verify second save receives conflict |
| Expected outcome | One succeeds; other receives 409 conflict |
| Safety risk | Lost clinical documentation |
| Status | NOT EXECUTED |

### UAT-08: Order Set Application

| Aspect | Detail |
|---|---|
| Scenario | Apply order set → verify provenance |
| Actor | Clinician |
| Precondition | Published order set; open encounter |
| Steps | 1. Clinician selects order set<br>2. Reviews included orders<br>3. Modifies one item<br>4. Applies to encounter<br>5. Verify generated orders<br>6. Verify provenance record<br>7. Verify audit trail |
| Expected outcome | Orders created with correct provenance; modification tracked |
| Safety risk | Silent substitution; missing provenance |
| Status | NOT EXECUTED |

### UAT-09: Clinical Handoff (OPD → Lab)

| Aspect | Detail |
|---|---|
| Scenario | Encounter → lab order → result → review |
| Actor | Clinician → Lab |
| Precondition | Open encounter |
| Steps | 1. Clinician places lab order<br>2. Lab receives order<br>3. Lab processes and creates result<br>4. Clinician reviews result<br>5. Verify result linked to correct order/encounter/patient |
| Expected outcome | Result correctly associated; provenance preserved |
| Safety risk | Wrong-patient result; result not linked to order |
| Status | NOT EXECUTED |

### UAT-10: Tenant Isolation

| Aspect | Detail |
|---|---|
| Scenario | User from Tenant A attempts to access Tenant B's data |
| Actor | Clinician (Tenant A) |
| Precondition | Patients in both tenants |
| Steps | 1. Clinician authenticates as Tenant A<br>2. Attempt to access Tenant B's patient<br>3. Verify 404/403<br>4. Attempt to access Tenant B's encounter<br>5. Verify denial |
| Expected outcome | All cross-tenant access denied |
| Safety risk | Cross-tenant clinical data leakage |
| Status | NOT EXECUTED |

---

## UAT Actor Matrix

| Role | Allowed Actions | Forbidden Actions |
|---|---|---|
| Clinician | Create/edit notes, diagnoses, prescriptions; sign encounters; view patients in scope | Access other tenants/facilities; modify signed records; sign other provider's encounters |
| Nurse | Record vitals; view encounters in scope | Sign encounters; create prescriptions; modify signed records |
| Reception | Check-in patients; view appointments | Access clinical notes; modify clinical records |
| Billing | View charges; create invoices | Modify clinical records; access full clinical notes |
| Lab Staff | View lab orders; create results | Modify clinical records; access prescriptions |
| Admin | Manage users/facilities | Access clinical records (unless explicitly authorized) |

---

## Acceptance Criteria

Each scenario requires:
- [ ] Precondition verified
- [ ] Steps executed
- [ ] Expected outcome confirmed
- [ ] Safety risk assessed
- [ ] Evidence captured
- [ ] Clinical reviewer sign-off
- [ ] Date recorded

---

## Status

| Item | Status |
|---|---|
| UAT plan created | YES (this file) |
| UAT environment ready | NO |
| UAT scenarios executed | NO |
| Findings documented | NO |
| Blocking safety issues | N/A (not yet executed) |
| Clinical reviewer assigned | NO |
| Acceptance status | NOT EXECUTED |
