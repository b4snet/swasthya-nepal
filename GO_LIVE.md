# GO_LIVE.md — SWASTHYA Production Go-Live

> **Status:** Documentation ready — requires explicit authorization
> **Release:** `81932b8` on `main`
> **Date:** August 22, 2026
> **Authorization Required:** YES — hospital, technical, and security approval before any deployment

---

## 0. CRITICAL RULES

1. **No deployment without explicit authorization** from hospital IT director, technical lead, and security officer.
2. **No nationwide rollout** — this is the FIRST controlled hospital go-live.
3. **No hidden failures** — if migration fails or data integrity is compromised, STOP and report.
4. **No real PHI without formal authorization** — data migration requires explicit hospital approval.
5. **Rollback must be tested before go-live** — a deployment without rollback is a gamble, not an operation.

---

## 1. Go-Live Authorization Checklist

| # | Authorization | Signatory | Date | Status |
|---|---|---|---|---|
| 1 | Hospital IT Director approval | _____________ | ___/___/___ | ⬜ |
| 2 | Technical Lead approval | _____________ | ___/___/___ | ⬜ |
| 3 | Security Officer approval | _____________ | ___/___/___ | ⬜ |
| 4 | Clinical Director approval | _____________ | ___/___/___ | ⬜ |
| 5 | Finance Director approval | _____________ | ___/___/___ | ⬜ |

**No deployment proceeds until ALL required signatures are obtained.**

---

## 2. Final Production Snapshot

Before any deployment, create:

| # | Snapshot | Purpose | Status |
|---|---|---|---|
| 1 | Release version tag | Git tag for this deployment | ⬜ |
| 2 | Database backup | pg_dump -Fc of production database | ⬜ |
| 3 | Configuration backup | All env vars, config files | ⬜ |
| 4 | Migration snapshot | Current migration state recorded | ⬜ |
| 5 | Rollback reference | Previous known-good version identified | ⬜ |

### Snapshot Commands

```bash
# 1. Tag the release
git tag -a v1.0.0-pilot -m "SWASTHYA v1.0.0 — First pilot go-live"
git push origin v1.0.0-pilot

# 2. Database backup
pg_dump -Fc -h <host> -U swasthya_admin -d swasthya_production > backup_pre_golive_$(date +%Y%m%d_%H%M%S).dump

# 3. Configuration backup
cp .env .env.backup_$(date +%Y%m%d_%H%M%S)

# 4. Migration state
php artisan migrate:status > migration_status_$(date +%Y%m%d_%H%M%S).txt

# 5. Record rollback reference
echo "Rollback to: $(git log --oneline -1)" > rollback_reference.txt
```

---

## 3. Data Migration Plan

### 3.1 Source System Mapping

If migrating from a previous HMS, map:

| Source Field | SWASTHYA Field | Validation |
|---|---|---|
| Patient ID / MRN | patients.mrn | Unique, no duplicates |
| Patient Name | patients.full_name | Character encoding verified |
| Date of Birth | patients.date_of_birth | Format validation |
| Sex / Gender | patients.sex | Enum match |
| Phone | patients.phone | Format validation |
| Address | patients.address | JSON structure |
| Blood Group | patients.blood_group | Enum match |
| Provider ID | staff.employee_code | Cross-reference |
| Provider Name | staff.full_name | Cross-reference |
| Department | departments.code | Cross-reference |
| Appointment Date | appointments.starts_at | ISO format |
| Diagnosis | diagnoses.description | Free text |
| Prescription | prescriptions (lines) | Medication cross-reference |
| Invoice | invoices + lines | Amount validation |
| Payment | payments + allocations | Amount validation |
| Stock | inventory_items + batches | Quantity validation |

### 3.2 Migration Sequence

```
1. ORGANIZATION / FACILITY
   ↓
2. DEPARTMENTS / WARDS / ROOMS / BEDS
   ↓
3. STAFF / ROLES / PERMISSIONS
   ↓
4. SERVICES / MEDICATIONS / LAB TESTS
   ↓
5. PATIENTS (with MRN mapping)
   ↓
6. ENCOUNTERS / DIAGNOSES
   ↓
7. PRESCRIPTIONS / MEDICATIONS
   ↓
8. LAB ORDERS / RESULTS
   ↓
9. RADIOLOGY ORDERS / STUDIES
   ↓
10. APPOINTMENTS / FOLLOW-UPS
    ↓
11. INVOICES / PAYMENTS
    ↓
12. INVENTORY / STOCK
    ↓
13. DOCUMENTS
    ↓
14. AUDIT TRAIL
```

### 3.3 Migration Rules

| Rule | Description |
|---|---|
| No orphan records | Every migrated record must reference valid parent |
| No duplicate MRNs | Patient identity must be unique |
| No negative balances | Financial records must reconcile |
| No orphaned permissions | Every role assignment must reference valid user/role |
| Preserved timestamps | Created/updated timestamps preserved where possible |
| Audit every migration | Every migration action logged in audit trail |
| Rollback capability | Every migration step must be reversible |

---

## 4. Migration Validation

### 4.1 Pre-Migration Validation

| # | Check | Expected | Status |
|---|---|---|---|
| 1 | Source record counts | Match expected | ⬜ |
| 2 | Duplicate patient check | Zero duplicates | ⬜ |
| 3 | Orphan record check | Zero orphans | ⬜ |
| 4 | MRN uniqueness | All unique | ⬜ |
| 5 | Financial reconciliation | Debits = Credits | ⬜ |
| 6 | Permission consistency | All roles valid | ⬜ |
| 7 | Data encoding | UTF-8 verified | ⬜ |
| 8 | Backup created | Pre-migration backup exists | ⬜ |

### 4.2 Post-Migration Validation

| # | Check | Expected | Status |
|---|---|---|---|
| 1 | Record counts match | Source = Target | ⬜ |
| 2 | Patient search works | All patients findable | ⬜ |
| 3 | MRN lookup works | All MRNs resolvable | ⬜ |
| 4 | Encounter history intact | Patient timelines complete | ⬜ |
| 5 | Financial balances correct | No negative balances | ⬜ |
| 6 | Lab results accessible | All results viewable | ⬜ |
| 7 | Radiology studies accessible | All studies viewable | ⬜ |
| 8 | Prescriptions viewable | All prescriptions intact | ⬜ |
| 9 | Documents accessible | All documents downloadable | ⬜ |
| 10 | Audit trail complete | Migration events logged | ⬜ |

---

## 5. Go-Live Procedure

### 5.1 Pre-Go-Live (T-60 min)

| # | Action | Owner | Status |
|---|---|---|---|
| 1 | Final database backup | DBA | ⬜ |
| 2 | Verify monitoring active | Ops | ⬜ |
| 3 | Verify alerting active | Ops | ⬜ |
| 4 | Confirm support team available | Support | ⬜ |
| 5 | Confirm rollback procedure tested | Tech Lead | ⬜ |
| 6 | Notify hospital IT team | Project Lead | ⬜ |

### 5.2 Deployment (T-0)

| # | Action | Owner | Status |
|---|---|---|---|
| 1 | Deploy application code | Tech Lead | ⬜ |
| 2 | Run database migrations | DBA | ⬜ |
| 3 | Clear caches | Ops | ⬜ |
| 4 | Verify health endpoints | Ops | ⬜ |
| 5 | Run synthetic smoke test | QA | ⬜ |

### 5.3 Post-Go-Live (T+30 min)

| # | Action | Owner | Status |
|---|---|---|---|
| 1 | Verify login works | QA | ⬜ |
| 2 | Verify patient search | QA | ⬜ |
| 3 | Verify appointment booking | QA | ⬜ |
| 4 | Verify clinical workflow | Clinical | ⬜ |
| 5 | Verify pharmacy dispensing | Pharmacy | ⬜ |
| 6 | Verify lab workflow | Lab | ⬜ |
| 7 | Verify radiology workflow | Radiology | ⬜ |
| 8 | Verify billing | Billing | ⬜ |
| 9 | Verify patient portal | Portal | ⬜ |
| 10 | Monitor for errors (30 min) | Ops | ⬜ |

### 5.4 Go-Live Decision (T+60 min)

| # | Criteria | Status |
|---|---|---|
| 1 | All smoke tests pass | ⬜ |
| 2 | No critical errors in logs | ⬜ |
| 3 | No data integrity issues | ⬜ |
| 4 | Hospital team confirms functionality | ⬜ |
| 5 | Support channel active | ⬜ |

**If ALL criteria met → GO-LIVE SUCCESSFUL**
**If ANY criteria fail → ROLLBACK**

---

## 6. Rollback Procedure

### 6.1 Rollback Decision

Rollback if ANY of:
- Critical application error preventing clinical workflows
- Data integrity issue detected
- Security vulnerability exploited
- Hospital team requests rollback

### 6.2 Rollback Steps

| # | Action | Time Estimate |
|---|---|---|
| 1 | Stop application traffic | 1 min |
| 2 | Restore database from pre-go-live backup | 5-15 min |
| 3 | Redeploy previous application version | 2-5 min |
| 4 | Clear caches | 1 min |
| 5 | Verify health endpoints | 1 min |
| 6 | Verify data integrity | 5 min |
| 7 | Notify hospital IT team | 1 min |
| **Total** | | **15-30 min** |

### 6.3 Post-Rollback

| # | Action | Owner |
|---|---|---|
| 1 | Investigate root cause | Tech Lead |
| 2 | Document findings | Tech Lead |
| 3 | Create fix | Development |
| 4 | Test fix in staging | QA |
| 5 | Schedule re-deployment | Project Lead |

---

## 7. Go-Live Monitoring

### 7.1 High-Frequency Monitoring (First 24 hours)

| Metric | Frequency | Threshold |
|---|---|---|
| Application errors | Every 5 min | 0 critical |
| API latency (p95) | Every 5 min | < 500ms |
| Database connections | Every 5 min | < 80% pool |
| Queue depth | Every 5 min | < 100 jobs |
| Auth failures | Every 5 min | < 10/min |
| Disk usage | Every 30 min | < 80% |
| Memory usage | Every 30 min | < 80% |

### 7.2 Normal Monitoring (After 24 hours)

| Metric | Frequency | Threshold |
|---|---|---|
| Application errors | Hourly | < 5/hour |
| API latency (p95) | Hourly | < 1s |
| Database performance | Hourly | Normal baseline |
| Backup completion | Daily | 100% |
| Security events | Daily | Review all |

---

## 8. Support Activation

| # | Item | Details | Status |
|---|---|---|---|
| 1 | Support channel | [Slack/Teams channel created] | ⬜ |
| 2 | Incident escalation | P1: < 15min, P2: < 1hr, P3: < 4hr | ⬜ |
| 3 | Technical owner | [Name assigned] | ⬜ |
| 4 | Hospital superuser | [Name trained] | ⬜ |
| 5 | Response targets | Documented and agreed | ⬜ |
| 6 | On-call rotation | [Schedule created] | ⬜ |

---

## 9. Go-Live Report Template

After go-live, produce:

```markdown
# SWASTHYA PRODUCTION GO-LIVE REPORT

## Environment
- Release: [version]
- Commit: [hash]
- Deployment time: [timestamp]
- Deployed by: [name]

## Migration
- Source system: [name]
- Records migrated: [count]
- Migration duration: [time]
- Failures: [count]
- Reconciliation: [pass/fail]

## Go-Live Verification
- [ ] Login works
- [ ] Patient search works
- [ ] Appointment booking works
- [ ] Clinical workflow works
- [ ] Pharmacy dispensing works
- [ ] Lab workflow works
- [ ] Radiology workflow works
- [ ] Billing works
- [ ] Patient portal works
- [ ] No critical errors

## Monitoring (First 24 hours)
- Errors: [count]
- Latency (p95): [ms]
- Uptime: [percentage]

## Incidents
- P1: [count]
- P2: [count]
- P3: [count]

## Decision
GO-LIVE SUCCESSFUL / GO-LIVE FAILED

## Evidence
[Attach monitoring screenshots, log excerpts, test results]
```

---

*This document must be reviewed and approved before any production deployment.*
