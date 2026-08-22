# CUSTOMER_DEPLOYMENT.md — SWASTHYA Hospital Expansion & Customer Success

> **Status:** Deployment framework ready — requires operational stability at first hospital
> **Release:** `a5b54fc` on `main`
> **Date:** August 22, 2026
> **Scope:** Repeatable deployment to additional hospitals

---

## 0. CRITICAL RULES

1. **Never expose production PHI in demos** — synthetic data only.
2. **Never clone production data** — configuration templates, not data copies.
3. **Every release has release notes** — features, fixes, breaking changes, migrations.
4. **Customer feedback is evidence-based** — prioritize with data, not opinions.
5. **Deployment is repeatable** — documented procedures, not heroics.

---

## 1. Hospital Onboarding Package

### 1.1 Onboarding Checklist

| # | Item | Owner | Status |
|---|---|---|---|
| 1 | Organization setup | Implementation | ⬜ |
| 2 | Facility setup | Implementation | ⬜ |
| 3 | Module entitlement | Sales + Implementation | ⬜ |
| 4 | Department configuration | Implementation | ⬜ |
| 5 | Role configuration | Implementation | ⬜ |
| 6 | Staff setup | Hospital IT + Implementation | ⬜ |
| 7 | Branding/configuration | Implementation | ⬜ |
| 8 | Integration setup | Technical | ⬜ |
| 9 | Data migration (if applicable) | Implementation | ⬜ |
| 10 | Training | Training team | ⬜ |
| 11 | Go-live support | Support team | ⬜ |
| 12 | Hypercare | Support + Implementation | ⬜ |

### 1.2 Onboarding Timeline

| Phase | Duration | Activities |
|---|---|---|
| Week 1 | Kickoff | Contract review, environment setup, org/facility config |
| Week 2-3 | Configuration | Departments, roles, staff, branding, modules |
| Week 4-5 | Integration | External systems, data migration, testing |
| Week 6-7 | Training | Role-specific training for all user groups |
| Week 8 | Go-live | Production deployment, monitoring, hypercare start |

---

## 2. Configuration Templates

### 2.1 Template Types

| Template | Description | Use Case |
|---|---|---|
| General Hospital | Full module set, all departments | Large multi-department hospitals |
| Clinic | Outpatient-focused, minimal IPD | Small clinics, polyclinics |
| Multi-Facility | Organization-level, multiple sites | Hospital chains, networks |
| Specialty Hospital | Focused modules (e.g., cardiac, cancer) | Specialty care centers |

### 2.2 Template Contents

Each template includes:

| Component | Included |
|---|---|
| Organization structure | ✅ |
| Department hierarchy | ✅ |
| Role definitions | ✅ |
| Module entitlement | ✅ |
| Pricing structure | ✅ (hospital-specific) |
| Notification templates | ✅ |
| Report templates | ✅ |
| Integration config | ✅ (placeholder) |
| Staff roles | ✅ (template) |
| Bed/ward structure | ✅ |

---

## 3. Module Entitlement

### 3.1 Module Packages

| Package | Modules | Target |
|---|---|---|
| Essential | Patient, Appointments, Billing, Pharmacy | Small clinics |
| Standard | Essential + Lab, Radiology, IPD | Mid-size hospitals |
| Professional | Standard + ICU, OT, Blood Bank, Procurement | Large hospitals |
| Enterprise | Professional + Analytics, Research, AI, Multi-facility | Hospital networks |

### 3.2 Entitlement Controls

| Control | Mechanism |
|---|---|
| Module visibility | Navigation filtering based on entitlement |
| Feature access | Backend API gating by module |
| Configuration boundary | Per-facility configuration isolation |
| Contractual entitlement | Sales + Implementation alignment |

---

## 4. Implementation Checklist

### 4.1 Technical

| # | Item | Status |
|---|---|---|
| 1 | Environment provisioned | ⬜ |
| 2 | Domain/HTTPS configured | ⬜ |
| 3 | Database migrated | ⬜ |
| 4 | RLS enabled | ⬜ |
| 5 | Monitoring active | ⬜ |
| 6 | Backup configured | ⬜ |
| 7 | Integrations connected | ⬜ |
| 8 | Smoke tests passed | ⬜ |

### 4.2 Operational

| # | Item | Status |
|---|---|---|
| 1 | Hospital admin created | ⬜ |
| 2 | Staff onboarded | ⬜ |
| 3 | Roles configured | ⬜ |
| 4 | Departments set up | ⬜ |
| 5 | Workflows tested | ⬜ |
| 6 | Support channel active | ⬜ |
| 7 | On-call established | ⬜ |

### 4.3 Security

| # | Item | Status |
|---|---|---|
| 1 | Secrets in production vault | ⬜ |
| 2 | CORS configured | ⬜ |
| 3 | Rate limits active | ⬜ |
| 4 | MFA enabled for admins | ⬜ |
| 5 | Audit trail active | ⬜ |
| 6 | RLS verified | ⬜ |

### 4.4 Training

| # | Item | Status |
|---|---|---|
| 1 | Admin training complete | ⬜ |
| 2 | Doctor training complete | ⬜ |
| 3 | Nurse training complete | ⬜ |
| 4 | Pharmacy training complete | ⬜ |
| 5 | Lab training complete | ⬜ |
| 6 | Billing training complete | ⬜ |
| 7 | Portal training complete | ⬜ |

---

## 5. Training Materials

### 5.1 Role-Specific Training

| Role | Duration | Topics |
|---|---|---|
| Hospital Admin | 4 hours | Organization setup, module config, staff management |
| Doctor | 2 hours | Clinical workspace, encounters, prescriptions, orders |
| Nurse | 2 hours | Ward tasks, vitals, care plans, handovers |
| Pharmacist | 2 hours | Prescriptions, dispensing, inventory |
| Lab Technician | 2 hours | Lab worklist, specimen, results |
| Radiologist | 2 hours | Imaging worklist, reports, critical findings |
| Billing Staff | 2 hours | Charges, invoices, payments, reconciliation |
| HR Admin | 2 hours | Staff directory, credentials, onboarding |
| Patient | 30 min | Portal login, appointments, results, bills |

### 5.2 Training Delivery

| Method | Description |
|---|---|
| In-person workshop | Hands-on training with real scenarios |
| Video tutorials | Recorded sessions for reference |
| Quick reference guides | PDF guides for each role |
| Sandbox environment | Practice environment with synthetic data |
| Go-live support | On-site support during first week |

---

## 6. Demo Environment

### 6.1 Demo Rules

| Rule | Description |
|---|---|
| Synthetic data only | No production PHI |
| Reset capability | Can be reset to clean state |
| Feature-complete | All enabled modules available |
| Safe for external use | No real patient data |
| Documented limitations | Clear about what is/isn't implemented |

### 6.2 Demo Data

| Data Type | Source |
|---|---|
| Patients | Synthetic generator |
| Staff | Template roles |
| Appointments | Synthetic schedule |
| Clinical records | Template encounters |
| Billing | Template invoices |

---

## 7. Customer Support

### 7.1 Support Channels

| Channel | Availability | Response Time |
|---|---|---|
| Email | 24/7 | < 4 hours |
| Phone | Business hours | < 1 hour |
| Slack/Teams | Business hours | < 30 min |
| Emergency | 24/7 | < 15 min |

### 7.2 Support Severity

| Severity | Definition | Response | Resolution |
|---|---|---|---|
| P1 | Platform down | < 15 min | < 4 hours |
| P2 | Major feature broken | < 1 hour | < 8 hours |
| P3 | Non-critical issue | < 4 hours | < 24 hours |
| P4 | Minor/cosmetic | < 24 hours | < 72 hours |

---

## 8. Release Notes Template

```markdown
# Release Notes — SWASTHYA v[X.Y.Z]

## Date: [YYYY-MM-DD]

## Features
- [feature 1]
- [feature 2]

## Fixes
- [fix 1]
- [fix 2]

## Breaking Changes
- [breaking change 1]

## Migrations
- [migration 1]

## Training Impact
- [training needed/not needed]

## Upgrade Instructions
1. [step 1]
2. [step 2]
```

---

## 9. Customer Feedback

### 9.1 Collection

| Channel | Frequency |
|---|---|
| Support tickets | Continuous |
| Quarterly review | Quarterly |
| User survey | Semi-annually |
| On-site visit | Annually |

### 9.2 Prioritization

| Priority | Criteria |
|---|---|
| P1 | Critical bug, data loss, security |
| P2 | Major workflow broken, multiple users affected |
| P3 | UX improvement, single user affected |
| P4 | Enhancement request, nice-to-have |

---

## 10. Multi-Hospital Onboarding Drill

### 10.1 Drill Scope

Simulate onboarding a new hospital:

| Step | Activity | Duration |
|---|---|---|
| 1 | Create organization | 30 min |
| 2 | Configure facility | 1 hour |
| 3 | Set up departments | 2 hours |
| 4 | Configure roles | 1 hour |
| 5 | Create staff accounts | 2 hours |
| 6 | Enable modules | 30 min |
| 7 | Configure branding | 1 hour |
| 8 | Test workflows | 4 hours |
| 9 | Verify isolation | 2 hours |
| 10 | Documentation | 1 hour |

**Total estimated time: ~15 hours**

### 10.2 Isolation Verification

| Check | Expected |
|---|---|
| New hospital data isolated from existing | ✅ |
| Existing hospital unaffected | ✅ |
| Configuration independent | ✅ |
| No cross-facility leakage | ✅ |

---

*This document defines the customer deployment framework for SWASTHYA hospital expansion.*
