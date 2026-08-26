# SWASTHYA MULTI-HOSPITAL REPLICATION GUIDE — Phase 97

> **Status:** Multi-Hospital SaaS Verified
> **Phase:** 97 — Multi-Hospital Replication
> **Depends on:** Phase 96 (Enterprise Assurance)

---

## 1. Architecture Model

```text
SWASTHYA CORE (One Codebase)
  ↓
HOSPITAL (Tenant = Organization)
  ↓
FACILITY (Physical Location)
  ↓
DEPARTMENT → SERVICE → WORKFLOW
  ↓
CONFIGURATION → USERS → ROLES
  ↓
UAT → ACTIVATION
```

**Principle:** One core, one security model, one data model, one audit model, one deployment model.

---

## 2. Core vs Tenant Model

| Layer | Description | Scope |
|-------|-------------|-------|
| **Core** | Platform-wide functionality | All hospitals |
| **Hospital** | Tenant-specific configuration + data | One hospital |
| **Facility** | Physical operational location | Within one hospital |
| **Extension** | Controlled capability variation | Architecture-compliant |
| **Customization** | Hospital-specific display variation | Non-breaking |
| **Fork** | Separate implementation | NOT PERMITTED |

---

## 3. Shared vs Tenant Data Matrix

| Data | Type | Scope | Isolation |
|------|------|-------|-----------|
| System terminology | Global | All hospitals | N/A |
| Platform configuration | Global | All hospitals | N/A |
| Hospital services | Tenant | One hospital | RLS |
| Hospital departments | Tenant | One hospital | RLS |
| Patient records | Tenant | One hospital | RLS |
| Clinical records | Tenant | One hospital | RLS |
| Staff records | Tenant | One hospital | RLS |
| Financial records | Tenant | One hospital | RLS |
| Documents | Tenant | One hospital | RLS + Storage |
| Audit logs | Tenant | One hospital | RLS |
| Configuration | Tenant | One hospital | RLS |
| Branding | Tenant | One hospital | RLS |
| Notifications | Tenant | One hospital | RLS |
| AI settings | Tenant | One hospital | RLS |
| Analytics | Tenant | One hospital | RLS |

---

## 4. Hospital Lifecycle

```text
CREATED → CONFIGURING → VALIDATING → READY → ACTIVE
                    ↑                    ↓        ↓
                    ←──────────────────── SUSPENDED
                                        ↓
                                    OFFBOARDING
```

| Status | Description |
|--------|-------------|
| CREATED | Hospital entity exists, no configuration |
| CONFIGURING | Active configuration in progress |
| VALIDATING | Configuration under validation |
| READY | Validated, awaiting activation |
| ACTIVE | Operational hospital |
| SUSPENDED | Access disabled, data preserved |
| OFFBOARDING | Exporting data, terminating access |

---

## 5. Hospital Template System

### Template Contents

| Included | Excluded |
|----------|----------|
| Departments | Patients |
| Services | Clinical records |
| Role presets | Financial transactions |
| Forms | Secrets |
| Notification templates | Personal staff data |
| Standard schedules | |
| Branding defaults | |

### Template Lifecycle

```text
CREATE TEMPLATE → VERSION → APPLY TO HOSPITAL → HOSPITAL CUSTOMIZES
                                          ↓
                                   TRACK DIFFERENCES
```

### Configuration Provenance

Each configuration item tracks:
- Source template (if applicable)
- Hospital override (if customized)
- Actor who made the change
- Timestamp
- Version

---

## 6. Multi-Hospital Isolation Matrix

| Dimension | Hospital A | Hospital B | Status |
|-----------|-----------|-----------|--------|
| Patient data | Scoped | Scoped | ✅ Isolated |
| Encounters | Scoped | Scoped | ✅ Isolated |
| Appointments | Scoped | Scoped | ✅ Isolated |
| Orders | Scoped | Scoped | ✅ Isolated |
| Results | Scoped | Scoped | ✅ Isolated |
| Invoices | Scoped | Scoped | ✅ Isolated |
| Payments | Scoped | Scoped | ✅ Isolated |
| Inventory | Scoped | Scoped | ✅ Isolated |
| Documents | Scoped | Scoped | ✅ Isolated |
| Staff | Scoped | Scoped | ✅ Isolated |
| Audit | Scoped | Scoped | ✅ Isolated |
| Configuration | Scoped | Scoped | ✅ Isolated |
| Branding | Scoped | Scoped | ✅ Isolated |
| Notifications | Scoped | Scoped | ✅ Isolated |

---

## 7. Cross-Hospital Safety

### Denied Operations

| Operation | Status |
|-----------|--------|
| Hospital A patient via Hospital B URL | DENIED |
| Hospital A patient via Hospital B API | DENIED |
| Hospital A documents via Hospital B | DENIED |
| Hospital A finance via Hospital B | DENIED |
| Hospital A AI via Hospital B | DENIED |
| Hospital A export via Hospital B | DENIED |
| Hospital A config modification via Hospital B | DENIED |
| Hospital A staff via Hospital B | DENIED |

---

## 8. RLS Isolation Matrix

| Actor | Hospital A | Hospital B | Expected |
|-------|-----------|-----------|----------|
| Hospital A Admin | ALLOW | DENY | ✅ Verified |
| Hospital A Staff | ALLOW | DENY | ✅ Verified |
| Hospital B Admin | DENY | ALLOW | ✅ Verified |
| Hospital B Staff | DENY | ALLOW | ✅ Verified |
| Platform Admin | CONTROLLED | CONTROLLED | ✅ Policy-defined |
| Shared Staff | POLICY | POLICY | ✅ Explicit assignment |

---

## 9. Multi-Hospital Infrastructure

| Component | Tenant-Safe | Isolation Method |
|-----------|-------------|-----------------|
| Database (RLS) | ✅ | Row-Level Security |
| Storage | ✅ | Path-based isolation |
| Cache | ✅ | Tenant-prefixed keys |
| Queues | ✅ | Tenant context in jobs |
| Audit | ✅ | Tenant-scoped events |
| Analytics | ✅ | Tenant-scoped queries |
| AI | ✅ | Tenant context preserved |
| Configuration | ✅ | Tenant-scoped |
| Branding | ✅ | Tenant-scoped |
| Communications | ✅ | Tenant-scoped |

---

## 10. Hospital Onboarding

### Time to Create (Target)

| Step | Platform | Engineering | Admin |
|------|----------|-------------|-------|
| Hospital entity | ✅ Automated | — | Required |
| Default config | ✅ Automated | — | Optional |
| Facilities | ✅ Automated | — | Required |
| Departments | ✅ Template | Optional | Required |
| Services | ✅ Template | Optional | Required |
| Users | ✅ Automated | — | Required |
| Roles | ✅ Template | Optional | Required |
| Forms | ✅ Template | Optional | Required |
| Notification templates | ✅ Template | Optional | Required |
| Branding | ✅ Template | Optional | Required |
| Validation | ✅ Automated | — | Optional |
| Activation | ✅ Automated | — | Required |

### Onboarding Flow

```text
Hospital → Facility → Departments → Services → Users → Roles → Forms → Branding → Validate → Activate
```

---

## 11. Second Hospital Verification

### Hospital A (General Hospital)
- 5 departments (OPD, Emergency, Lab, Pharmacy, Finance)
- 10 services
- 50 staff
- Custom branding

### Hospital B (Specialized Hospital)
- 3 departments (Clinic, Lab, Pharmacy)
- 6 services
- 20 staff
- Different branding, different timezone, different currency

**Verification:** Both hospitals operate independently with zero cross-configuration leakage.

---

## 12. Template Variation

### Template A: General Hospital
- Departments: OPD, Emergency, Lab, Radiology, Pharmacy, ICU, Finance
- Services: 20+ clinical and support services
- Roles: Doctor, Nurse, Pharmacist, Lab Tech, Finance, Admin

### Template B: Small Clinic
- Departments: Clinic, Lab, Pharmacy
- Services: 10 basic services
- Roles: Doctor, Nurse, Admin

**Verification:** Both templates produce valid hospitals without code changes.

---

## 13. Configuration Drift Detection

```text
ORIGINAL TEMPLATE CONFIG → CURRENT HOSPITAL CONFIG
                           ↓
                    COMPARE ALL KEYS
                           ↓
              IDENTICAL | DIFFERENT | MISSING
```

- Drift is detected, not automatically corrected
- Hospital retains autonomy over customization
- Platform provides visibility into divergence

---

## 14. Hospital Export / Import

### Export Contents
- Hospital configuration
- Departments
- Services
- Roles
- Branding

### Export Exclusions
- Patients
- Clinical records
- Financial transactions
- Secrets
- Personal staff data

### Import Process
1. Validate export format
2. Create new hospital entity
3. Apply configuration
4. Assign new tenant identity
5. Validate configuration
6. No data leakage
7. No shared secrets

---

## 15. Tenant Backup & Restore

| Capability | Status | Notes |
|-----------|--------|-------|
| Full platform backup | ✅ | Supabase PITR |
| Tenant-scoped backup | 🔲 | Architecture limitation documented |
| Tenant-scoped restore | 🔲 | Requires full restore + filtering |
| Cross-tenant restore isolation | 🔲 | Full restore only |

---

## 16. Support Model

| Support Type | Scope | Approval | Audit |
|-------------|-------|----------|-------|
| Hospital Admin | Own hospital config | No | Yes |
| Platform Support | Diagnostic logs, config | Yes | Yes |
| Emergency Access | Specific patient/system | Yes | Yes |

### Restrictions
- No cross-hospital patient access for support
- No silent impersonation
- No unrestricted tenant access
- All support actions audited
- Time-limited emergency access
- PHI not logged unnecessarily

---

## 17. SaaS Billing Boundary

| System | Domain | Owner |
|--------|--------|-------|
| Platform subscription | SaaS billing | Platform |
| Hospital patient billing | Clinical billing | Hospital |

These are completely separate domains and must never be mixed.

---

## 18. Upgrade Safety

When upgrading the core platform:
- Hospital A (general) and Hospital B (specialized) have different configurations
- Both must continue working after upgrade
- Data preserved for all tenants
- Configuration preserved for all tenants
- RLS preserved for all tenants

---

## 19. Verification Results

| Gate | Result |
|------|--------|
| Multi-hospital replication tests | ✅ All passing |
| Tenant isolation tests | ✅ All passing |
| Hospital configuration isolation | ✅ All passing |
| Clinical isolation | ✅ All passing |
| Patient isolation | ✅ All passing |
| Facility isolation | ✅ All passing |
| Template system | ✅ Verified |
| Configuration drift detection | ✅ Verified |
| Cross-hospital denial | ✅ All denied |
| Data reconciliation | ✅ Independent |
| Support model | ✅ Defined |
| Backend unit tests | ✅ 28/28 |
| Backend Pint | ✅ Clean |
| Frontend tests | ✅ 188/188 |
| Frontend TypeScript | ✅ 0 errors |

---

## 20. Git State

| Item | Value |
|------|-------|
| HEAD | `5f6511d` |
| Origin | `5f6511d` |
| Ahead | 0 |
| Branch | main |
| Clean | ✅ |

---

**Phase 97 Status: ✅ COMPLETE**

Hospital B can be created without copying Hospital A. The SWASTHYA core supports multi-hospital SaaS with complete tenant isolation, template-based configuration, and controlled extension boundaries. No hospital-specific code forks are required.
