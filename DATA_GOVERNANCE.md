# Swasthya — Data Governance and Records Lifecycle

> **Version:** 1.0 | **Phase:** 91 Data Governance
> **Status:** Classification matrix defined, retention framework established

---

## Data Governance Model

```text
DATA
↓
OWNER
↓
SCOPE
↓
CLASSIFICATION
↓
LIFECYCLE
↓
ACCESS CONTROL
↓
AUDIT
↓
RETENTION
↓
DISPOSITION
```

---

## Data Classification Matrix

### Confidential PHI (Protected Health Information)

| Data Class | Classification | Owner | Retention | Correction | Export | Audit |
|-----------|---------------|-------|-----------|------------|--------|-------|
| Patient Identity | CONFIDENTIAL_PHI | Patient / Hospital | Hospital policy | Amendment with audit | ✅ Authorized | ✅ |
| Clinical Record | CONFIDENTIAL_PHI | Hospital / Clinician | Hospital policy | Amendment with audit | ✅ Authorized | ✅ |
| Medication | CONFIDENTIAL_PHI | Hospital / Prescriber | Hospital policy | Amendment with audit | ✅ Authorized | ✅ |
| Diagnostics | CONFIDENTIAL_PHI | Hospital / Ordering Clinician | Hospital policy | Supersession | ✅ Authorized | ✅ |
| Documents | CONFIDENTIAL_PHI | Hospital / Author | Hospital policy | Version supersession | ✅ Authorized | ✅ |

### Financial

| Data Class | Classification | Owner | Retention | Correction | Export | Audit |
|-----------|---------------|-------|-----------|------------|--------|-------|
| Finance | FINANCIAL | Hospital / Finance | Hospital/legal policy | Reversal adjustment | ✅ Authorized | ✅ |

### Confidential (Non-PHI)

| Data Class | Classification | Owner | Retention | Correction | Export | Audit |
|-----------|---------------|-------|-----------|------------|--------|-------|
| Staff | CONFIDENTIAL | Hospital / HR | Hospital policy | Amendment with audit | ✅ HR only | ✅ |
| AI | CONFIDENTIAL | Hospital / Platform | Hospital policy | Deletion on request | ✅ Authorized | ✅ |

### Security Sensitive

| Data Class | Classification | Owner | Retention | Correction | Export | Audit |
|-----------|---------------|-------|-----------|------------|--------|-------|
| Security | SECURITY_SENSITIVE | Platform / Hospital Admin | Hospital policy | Append-only | ❌ No export | ✅ |
| Audit | SECURITY_SENSITIVE | Platform | Hospital policy | Append-only | ❌ No export | N/A |

### Internal

| Data Class | Classification | Owner | Retention | Correction | Export | Audit |
|-----------|---------------|-------|-----------|------------|--------|-------|
| Configuration | INTERNAL | Hospital Admin | Hospital policy | Versioned update | ✅ | ✅ |

---

## Record Lifecycle States

| State | Description | Applies To |
|-------|-------------|-----------|
| ACTIVE | Current, operational record | All records |
| SUPERSEDED | Replaced by newer version | Documents, results |
| CORRECTED | Modified via correction workflow | Clinical, financial |
| ARCHIVED | No longer active, retained for policy | All records |
| VOIDED | Cancelled, never completed | Invoices, orders |

---

## Immutable vs Correctable Data

### Append-Only (Immutable)
- Audit events
- Domain events
- Security logs
- Clinical notes (amendments are additions, not edits)

### Correction with Audit Trail
- Patient demographics
- Clinical records
- Financial records
- Staff information

### Version Supersession
- Documents
- Lab results (corrections create new versions)

### Direct Edit (with audit)
- Configuration
- Service pricing (does not affect historical records)
- Department names (preserves historical relationships)

---

## Clinical Record Correction

Original records are NEVER overwritten. Corrections are appended:

```text
ORIGINAL RECORD (unchanged)
↓
CORRECTION REQUESTED
  - Original value
  - Corrected value
  - Reason
  - Authorized by
  - Timestamp
↓
CORRECTION APPROVED
↓
CORRECTION APPLIED (as new version/entry)
↓
AUDIT LOG UPDATED
```

---

## Financial Record Correction

Historical financial records are NEVER silently rewritten:

```text
WRONG INVOICE
↓
CORRECTION APPROVED
↓
REVERSAL/ADJUSTMENT CREATED
  - Original invoice preserved
  - Reversal entry created
  - Adjustment entry created
  - All linked to original
↓
LEDGER UPDATED (net effect only)
↓
AUDIT LOG UPDATED
```

---

## Retention Policy

### Default Policy

All retention periods require **hospital/legal policy** before automated enforcement.

| Record Type | Default | Automated | Requires Policy |
|------------|---------|-----------|----------------|
| Patient records | Retained | No | Yes |
| Clinical records | Retained | No | Yes |
| Financial records | Retained | No | Yes |
| Audit records | Retained | No | Yes |
| Documents | Retained | No | Yes |

### Retention Framework

```text
RECORD CREATED
  ↓
ACTIVE PERIOD (hospital-defined)
  ↓
RETENTION ELIGIBLE (after X years)
  ↓
RETENTION POLICY APPLIED
  ├── ARCHIVE (retain, reduce access)
  ├── DELETE (if permitted by policy)
  └── HOLD (if legal hold active)
  ↓
DISPOSITION RECORDED
```

---

## Legal Hold

When a record is under legal hold:

- Automated retention/deletion is **suspended**
- Archive operations are **suspended**
- The hold is recorded with reason, date, and authority
- Hold removal requires explicit authorization

---

## Data Export

### Export Categories

| Category | Classification | Authorization Required |
|----------|---------------|----------------------|
| Patient data | CONFIDENTIAL_PHI | patient:export or patient:view |
| Financial data | FINANCIAL | billing:export |
| Clinical data | CONFIDENTIAL_PHI | clinical:export |
| Bulk export | Any | admin:export |
| Configuration | INTERNAL | admin:manage |
| Audit data | SECURITY_SENSITIVE | Not exportable |

### Export Safety

Every export must:
- Be scoped to authorized records only
- Preserve data integrity
- Be audit-logged
- Not expose internal identifiers accidentally
- Handle large datasets without timeout

### Export Provenance

Each sensitive export records:
- Actor (who exported)
- Purpose (if required)
- Scope (what was exported)
- Timestamp
- Record count
- Outcome

---

## Data Deletion

### What Can Be Deleted

| Record Type | User Request | Legal Requirement | Security Erasure | Tenant Offboard |
|------------|-------------|-------------------|-----------------|----------------|
| Patient identity | Conditional | If no retention | Yes | Export then archive |
| Clinical records | Conditional | If no retention | Yes | Archive |
| Financial records | No | If no retention | Yes | Archive |
| Audit records | No | No | Yes | Archive |
| Documents | Conditional | If no retention | Yes | Archive |
| Configuration | Yes | Yes | Yes | Archive |

### Patient Data Deletion Boundary

Patient records have relationships to:
- Encounters
- Orders
- Results
- Invoices
- Payments
- Documents
- Audit

Cascade deletion of critical history is **prohibited**. The deletion workflow:

```text
DELETION REQUESTED
  ↓
RELATIONSHIP CHECK
  ├── Active encounters → BLOCK
  ├── Pending invoices → BLOCK
  ├── Legal hold → BLOCK
  └── No dependencies → PROCEED
  ↓
AUTHORIZATION REQUIRED
  ↓
SOFT DELETE (record marked deleted, retained for policy)
  ↓
AUDIT LOG UPDATED
```

---

## Tenant Offboarding

When a hospital leaves SWASTHYA:

```text
OFFBOARDING INITIATED
  ↓
READINESS CHECK
  ├── Active patients → BLOCK
  ├── Open encounters → BLOCK
  ├── Pending invoices → BLOCK
  ├── Active integrations → BLOCK
  └── All clear → PROCEED
  ↓
DATA EXPORT (hospital receives copy)
  ↓
ACCESS TERMINATION
  ├── Users deactivated
  ├── Roles revoked
  ├── Sessions invalidated
  └── API keys revoked
  ↓
ARCHIVE (data retained per policy)
  ↓
STATUS → 'offboarded'
```

---

## Privacy Access Review

| Role | Patient Data | Clinical | Finance | Staff | Security | Config |
|------|-------------|----------|---------|-------|----------|--------|
| Super Admin | Platform scope | Platform | Platform | Platform | Full | Full |
| Hospital Admin | Facility scope | Facility | Facility | Facility | Facility | Facility |
| Doctor | Assigned patients | Own encounters | View only | View only | No | No |
| Nurse | Assigned patients | Own encounters | No | View only | No | No |
| Pharmacy | Prescriptions | Related orders | No | No | No | No |
| Lab | Related orders | Related results | No | No | No | No |
| Radiology | Related orders | Related studies | No | No | No | No |
| Finance | Billing view | No | Full | Payroll | No | No |
| Patient | Own data | Own records | Own invoices | No | No | No |

---

## Source of Truth

| Concept | Canonical Source | Derived From |
|---------|-----------------|-------------|
| Patient | Patient master | — |
| Encounter | Encounter system | Patient |
| Order | Order system | Encounter, Patient |
| Result | Result system | Order |
| Prescription | Prescription system | Encounter, Patient |
| Charge | Charge system | Encounter, Service |
| Invoice | Invoice system | Charges |
| Payment | Payment ledger | Invoice |
| Inventory | Stock ledger | Medication, Vendor |
| Audit | Audit event log | All mutations |
| Configuration | Configuration tables | Hospital settings |

---

## Tenant Isolation

### Patient Isolation

Every patient record includes `tenant_id`. RLS policies ensure:

```sql
-- Patient SELECT policy
CREATE POLICY p_rls_patients_select ON patients
  USING (
    swasthya_rls_is_platform() = true
    OR tenant_id = swasthya_rls_tenant_id()
  );
```

### Facility Isolation

Service, department, and staff records include `facility_id`:

```sql
-- Service SELECT policy
CREATE POLICY p_rls_services_select ON services
  USING (
    swasthya_rls_is_platform() = true
    OR tenant_id = swasthya_rls_tenant_id()
      AND facility_id = swasthya_rls_facility_id()
  );
```

### Cross-Hospital Access Prevention

RLS ensures Hospital A cannot see Hospital B's data:
- Patient records
- Clinical records
- Financial records
- Configuration
- Documents

---

## Data Quality

### Patient Identity Quality

| Issue | Detection | Resolution |
|-------|-----------|------------|
| Duplicate patient | MRN/name matching | Merge workflow (authorized) |
| Wrong identity | Correction request | Amendment with audit |
| Missing identifier | Validation | Registration prompt |

### Patient Merge Governance

Merge requires:
1. Explicit authorization
2. Review by authorized staff
3. Source and target record identification
4. Reason documented
5. Audit trail created

**Merge is irreversible** in most implementations. Stronger approval is required.

---

## Longitudinal Data Integrity

A patient's records across years must remain intact:

```text
PATIENT (2024)
  ↓
ENCOUNTER 1 (2024-01)
  ├── Orders
  ├── Results
  ├── Prescriptions
  └── Billing
  ↓
ENCOUNTER 2 (2024-06)
  ├── Orders
  ├── Results
  └── Billing
  ↓
ENCOUNTER 3 (2025-01)
  ├── Orders
  ├── Results
  └── Billing
```

Nothing silently disappears when new data is added. Historical records remain interpretable regardless of:
- Service name changes
- Department restructuring
- Provider changes
- Pricing updates

---

## Data Lineage

Every critical data flow is traceable:

```
LAB ORDER (source)
  ↓
SAMPLE COLLECTED
  ↓
ANALYSIS PERFORMED
  ↓
RESULT GENERATED
  ↓
RESULT REVIEWED
  ↓
PATIENT NOTIFIED
  ↓
AUDIT LOGGED
```

The lineage is documented through:
- `ReportLineageEntry` — tracks data flow
- `AuditEvent` — records all mutations
- `DisclosureLog` — tracks external releases

---

## AI Data Governance

| AI Feature | Input | Provider | Retention | Output | Audit |
|-----------|-------|----------|-----------|--------|-------|
| CDSS | Patient data | External | Per policy | Recommendation | ✅ |
| AI Drafts | Clinical data | External | Per policy | Draft text | ✅ |
| Nutrition | Patient data | External | Per policy | Plan | ✅ |

**AI must be OPTIONAL.** Core HMS continues when AI is unavailable.

---

## Backup Data Governance

Backups contain sensitive data:

| Aspect | Policy |
|--------|--------|
| Encryption | Database-level encryption |
| Access | Restricted to recovery operations |
| Retention | Per hospital policy |
| Restore access | Same authorization as production |
| Deletion | Per retention policy |

---

## Audit Requirements

| Action | Audit Required |
|--------|---------------|
| Patient create | ✅ |
| Patient read | ✅ (access log) |
| Patient update | ✅ |
| Patient delete | ✅ |
| Clinical record create | ✅ |
| Clinical record update | ✅ |
| Financial transaction | ✅ |
| Export | ✅ |
| Configuration change | ✅ |
| User role change | ✅ |
| Login/logout | ✅ |

---

## Configuration History

Configuration changes are versioned:

```text
SERVICE PRICE CHANGE
  ↓
OLD PRICE: 1500 NPR
NEW PRICE: 2000 NPR
CHANGED BY: admin@hospital.com
CHANGED AT: 2025-01-15 10:30:00
REASON: Annual price review
  ↓
AUDIT LOG UPDATED
```

**Historical invoices are NOT affected** by price changes.

---

## Related Documentation

- `SECURITY.md` — Security architecture
- `TENANCY.md` — Multi-tenancy
- `DATABASE.md` — Schema
- `MASTER_RULES.md` — Engineering rules
- `STAGING.md` — Staging operations
- `backend/app/Services/DataGovernanceService.php` — Implementation
- `backend/app/Services/ComplianceService.php` — Compliance
- `backend/app/Services/ConsentService.php` — Consent management
- `backend/app/Services/ExportService.php` — Data export
- `backend/app/Services/ArchiveService.php` — Archiving
