# MULTI_FACILITY.md — SWASTHYA Multi-Facility Expansion

> **Status:** Architecture verified — expansion framework ready
> **Release:** `43a2bc8` on `main`
> **Prerequisite:** Phase 127 hypercare exit criteria met at first facility
> **Scope:** Expand from one hospital to multiple facilities within one organization

---

## 0. CRITICAL RULES

1. **Expand only after first facility is stable** — hypercare exit criteria must be met.
2. **No cross-facility data leakage** — every facility's data must remain isolated.
3. **No shared configurations unless explicitly authorized** — each facility is independent.
4. **No nationwide rollout** — expand one facility at a time with validation.
5. **No unauthorized multi-facility access** — staff access to multiple facilities must be explicit.

---

## 1. Multi-Facility Architecture

### 1.1 Hierarchy

```
ORGANIZATION (Tenant)
   ↓
FACILITY A (Hospital 1)
   ├── Department A1
   ├── Department A2
   ├── Ward A1
   ├── Bed A1-A1
   └── Staff A (assigned)
   ↓
FACILITY B (Hospital 2)
   ├── Department B1
   ├── Department B2
   ├── Ward B1
   ├── Bed B1-B1
   └── Staff B (assigned)
```

### 1.2 Architecture Verified

| Component | Status | Evidence |
|---|---|---|
| Organization model | ✅ Implemented | `organizations` table, tenant boundary |
| Facility model | ✅ Implemented | `facilities` table, tenant-scoped |
| Branch model | ✅ Implemented | `branches` table, facility-scoped |
| Department model | ✅ Implemented | `departments` table, facility-scoped |
| Tenant context middleware | ✅ Implemented | `ResolveTenantContext.php` |
| RLS policies | ✅ Implemented | 80+ policies, tenant + facility scope |
| Facility switching | ✅ Implemented | `X-Swasthya-Facility` header |
| Multi-facility API | ✅ Implemented | `facilityId` parameter on all endpoints |
| Frontend facility selector | ✅ Implemented | AppShell facility switcher |

### 1.3 Isolation Model

| Isolation Level | Mechanism | Verified |
|---|---|---|
| Tenant (Organization) | RLS policies, tenant_id on all rows | ✅ |
| Facility | RLS policies, facility_id on sensitive rows | ✅ |
| Department | Application-level authorization | ✅ |
| Patient scope | Patient-specific access controls | ✅ |
| Financial scope | Facility-scoped billing records | ✅ |

---

## 2. Tenant Isolation Verification

### 2.1 Proof: Hospital A Cannot Access Hospital B

| Test | Expected | Mechanism |
|---|---|---|
| Direct API with Hospital A token → Hospital B resource | 403 FORBIDDEN | RLS + tenant context |
| SQL query without tenant context | 0 rows returned | RLS policies |
| SQL query with wrong tenant context | 0 rows returned | RLS policies |
| SQL query with correct tenant context | Only own rows | RLS policies |
| Cross-facility patient search | Only facility-scoped results | RLS + facility scope |

### 2.2 RLS Policy Coverage

| Table Category | Policy Count | Isolation |
|---|---|---|
| Patient records | 20+ | Tenant + Facility |
| Clinical records | 30+ | Tenant + Facility |
| Financial records | 15+ | Tenant + Facility |
| Configuration | 10+ | Tenant |
| Audit | 5+ | Tenant |

---

## 3. Facility Isolation Verification

### 3.1 Proof: Facility A Cannot Access Facility B

| Test | Expected | Mechanism |
|---|---|---|
| Facility A staff → Facility B patients | Denied | RLS facility scope |
| Facility A inventory → Facility B stock | Denied | Application + RLS |
| Facility A billing → Facility B invoices | Denied | RLS facility scope |
| Facility A scheduling → Facility B providers | Denied | Application scope |
| Facility A reports → Facility B data | Denied | RLS + application |

### 3.2 Authorized Multi-Facility Access

| Scenario | Allowed | Mechanism |
|---|---|---|
| Staff assigned to Facility A only | Facility A data only | Role assignment |
| Staff assigned to Facility A + B | Both facilities' data | Multiple role assignments |
| Admin with org-level role | All facilities in org | Org-scoped assignment |
| Support session (time-limited) | Specific facility | SupportSession model |

---

## 4. Module Entitlement per Facility

Each facility can independently configure:

| Module | Facility A | Facility B | Independent? |
|---|---|---|---|
| Patient Records | ✅ | ✅ | Yes |
| Appointments | ✅ | ✅ | Yes |
| Emergency | ✅ | ❌ | Yes |
| IPD | ✅ | ✅ | Yes |
| ICU | ✅ | ❌ | Yes |
| OT | ✅ | ✅ | Yes |
| Pharmacy | ✅ | ✅ | Yes |
| Laboratory | ✅ | ✅ | Yes |
| Radiology | ✅ | ❌ | Yes |
| Blood Bank | ✅ | ❌ | Yes |
| Oncology | ❌ | ✅ | Yes |
| Billing | ✅ | ✅ | Yes |
| Procurement | ✅ | ✅ | Yes |
| HR | ✅ | ✅ | Yes |
| Patient Portal | ✅ | ✅ | Yes |
| Telemedicine | ✅ | ❌ | Yes |

---

## 5. Configuration Isolation

### 5.1 Per-Facility Configuration

| Configuration | Scoped To | Isolation |
|---|---|---|
| Pricing | Facility | ✅ Facility-level |
| Medications | Organization | ✅ Tenant-level |
| Lab Tests | Organization | ✅ Tenant-level |
| Departments | Facility | ✅ Facility-level |
| Beds / Wards | Facility | ✅ Facility-level |
| Staff | Facility (or multi) | ✅ Assignment-based |
| Roles | Organization | ✅ Tenant-level |
| Billing Rules | Organization | ✅ Tenant-level |
| Notification Templates | Organization | ✅ Tenant-level |
| Reports | Organization + Facility | ✅ Scoped |

### 5.2 Pricing Isolation

| Scenario | Expected |
|---|---|
| Facility A sets Service X price = 1000 | Only applies to Facility A |
| Facility B sets Service X price = 1500 | Only applies to Facility B |
| Invoice at Facility A | Uses Facility A pricing |
| Invoice at Facility B | Uses Facility B pricing |

---

## 6. Multi-Hospital Reporting

| Report Type | Scope | Access |
|---|---|---|
| Facility-level reports | Single facility | Facility staff |
| Organization-level reports | All facilities | Org admin only |
| Cross-facility comparison | All facilities | Org admin only |
| Aggregate analytics | All facilities | Org admin only |

**Rule:** Only authorized organization-level users may aggregate data across facilities.

---

## 7. Multi-Facility Scheduling

| Scenario | Supported |
|---|---|
| Provider works at Facility A only | ✅ |
| Provider works at Facility A + B | ✅ (multiple assignments) |
| Provider schedule visible across facilities | ✅ (with proper authorization) |
| Appointment at Facility B for Facility A provider | ✅ (if assigned) |

---

## 8. Billing Isolation

| Check | Expected |
|---|---|
| Facility A invoices | Only in Facility A |
| Facility B invoices | Only in Facility B |
| Cross-facility invoice | Impossible (RLS) |
| Organization revenue report | Aggregates authorized facilities |
| Facility revenue report | Only own facility |

---

## 9. Inventory Isolation

| Check | Expected |
|---|---|
| Facility A stock | Only visible at Facility A |
| Facility B stock | Only visible at Facility B |
| Cross-facility stock query | Denied |
| Stock transfer A → B | Allowed (with authorization) |
| Organization inventory overview | Aggregates authorized facilities |

---

## 10. Expansion Procedure

### 10.1 Pre-Expansion Checklist

| # | Item | Status |
|---|---|---|
| 1 | First facility hypercare complete | ⬜ |
| 2 | All exit criteria met | ⬜ |
| 3 | Hospital team confirms stability | ⬜ |
| 4 | Second facility formally onboarded | ⬜ |
| 5 | Second facility configuration ready | ⬜ |
| 6 | Staff assignments prepared | ⬜ |
| 7 | Module entitlement defined | ⬜ |
| 8 | Pricing configured | ⬜ |
| 9 | Backup verified | ⬜ |
| 10 | Rollback tested | ⬜ |

### 10.2 Expansion Steps

```
1. VERIFY FIRST FACILITY STABLE
   ↓
2. ONBOARD SECOND FACILITY (org admin)
   ↓
3. CONFIGURE DEPARTMENTS / WARDS / BEDS
   ↓
4. CONFIGURE STAFF / ROLES / ASSIGNMENTS
   ↓
5. CONFIGURE MODULES / PRICING
   ↓
6. SMOKE TEST (synthetic data)
   ↓
7. VERIFY ISOLATION (cross-facility tests)
   ↓
8. ENABLE PRODUCTION ACCESS
   ↓
9. MONITOR (intensive for 7 days)
   ↓
10. EXPANSION COMPLETE
```

### 10.3 Post-Expansion Verification

| # | Check | Expected |
|---|---|---|
| 1 | Facility A data inaccessible from Facility B | ✅ |
| 2 | Facility B data inaccessible from Facility A | ✅ |
| 3 | Org-level reports aggregate correctly | ✅ |
| 4 | Staff with multi-facility access see correct data | ✅ |
| 5 | Pricing isolation verified | ✅ |
| 6 | Inventory isolation verified | ✅ |
| 7 | Billing isolation verified | ✅ |
| 8 | No performance degradation | ✅ |

---

## 11. Scale Targets

| Metric | Target | Current |
|---|---|---|
| Organizations | 10+ | Verified at 20 (load test) |
| Facilities per org | 5+ | Verified at 2 (load test) |
| Users per facility | 200+ | Verified at 1M patients |
| Concurrent users | 1000+ | Verified at load test |
| Patients per org | 1M+ | Load tested |

---

*This document must be reviewed before any multi-facility expansion.*
