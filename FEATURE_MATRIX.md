# SWASTHYA — Hospital Feature Matrix

**Version**: 1.0 | **Date**: August 2026 | **Commit**: 10fac20

## Legend
- ✅ **AVAILABLE NOW** — Implemented and tested
- 🧪 **AVAILABLE AS PILOT** — Implemented, ready for pilot validation
- 🔌 **REQUIRES EXTERNAL INTEGRATION** — Architecture ready, needs partner connection
- 🗺️ **ROADMAP** — Planned for future development
- ❌ **NOT AVAILABLE** — Not yet implemented

---

## 1. CORE CLINICAL

| Feature | Status | Notes |
|---------|--------|-------|
| Patient Registration | ✅ | MRN auto-generation, deduplication, search |
| Patient Profile/EMR | ✅ | Medical history, allergies, timeline, documents |
| Appointment Scheduling | ✅ | Provider schedules, rescheduling, cancellation |
| Queue Management | ✅ | Real-time queue with priority ordering |
| Clinical Encounters | ✅ | OPD, ER, teleconsult types |
| Clinical Notes | ✅ | SOAP-style documentation |
| Diagnosis (ICD) | ✅ | Diagnosis recording with encounter linkage |
| Prescriptions | ✅ | Multi-line prescriptions with dosage instructions |
| Follow-up Management | ✅ | Auto-creation of follow-up appointments |
| Referrals | ✅ | Inter-facility and inter-department referrals |
| Consent Management | ✅ | Versioned consent records |

## 2. PHARMACY

| Feature | Status | Notes |
|---------|--------|-------|
| Medication Formulary | ✅ | Full catalog with categories, dosages |
| Prescription Dispensing | ✅ | Batch-selected, quantity-tracked |
| Batch/Lot Tracking | ✅ | Expiry dates, batch numbers, stock by batch |
| Expiry Management | ✅ | Expiring stock visibility, expired stock blocked |
| Returns/Reversals | ✅ | Full and partial-quantity returns |
| Stock Movement Ledger | ✅ | Transactional truth for all movements |
| Dispensing Verification | ✅ | Dual-verification workflow |
| Standalone Dispensing | ✅ | Without prescription linkage |
| Pharmacy Billing | ✅ | Automatic charge creation |

## 3. LABORATORY

| Feature | Status | Notes |
|---------|--------|-------|
| Test Catalog | ✅ | Configurable test definitions |
| Lab Orders | ✅ | Encounter-linked ordering |
| Specimen Management | ✅ | Chain of custody, identity tracking |
| Result Entry | ✅ | Separate from verification |
| Result Verification | ✅ | Segregation of duties enforced |
| Critical Value Escalation | ✅ | Automatic escalation with acknowledgment |
| Result Versioning | ✅ | Corrections create new versions |
| Lab Reports | ✅ | Formatted result reports |

## 4. RADIOLOGY

| Feature | Status | Notes |
|---------|--------|-------|
| Radiology Orders | ✅ | Modality-specific ordering |
| Modality Worklist | ✅ | Scheduling by modality |
| Study Lifecycle | ✅ | Order → scheduling → study → report |
| Report Workflow | ✅ | Preliminary → final → verification |
| Report Corrections | ✅ | Versioned amendments |
| Critical Findings | ✅ | Escalation workflow |
| DICOM Reference | 🔌 | Metadata storage, viewer boundary ready |
| PACS Integration | 🔌 | Architecture ready, needs PACS connection |
| RIS Integration | 🔌 | Readiness layer, needs external RIS |

## 5. INPATIENT (IPD)

| Feature | Status | Notes |
|---------|--------|-------|
| Admission | ✅ | Emergency, planned, transfer admission |
| Bed Management | ✅ | Ward/room/bed hierarchy, availability |
| Bed Assignment | ✅ | Race-safe, atomic assignment |
| Transfers | ✅ | Source → destination with reason |
| Nursing Notes | ✅ | Admission-linked documentation |
| MAR Entries | ✅ | Medication administration records |
| Vital Observations | ✅ | Bedside vital sign recording |
| Discharge | ✅ | With required documentation completion |
| Discharge Summary | ✅ | Linked to encounter and admission |

## 6. EMERGENCY DEPARTMENT

| Feature | Status | Notes |
|---------|--------|-------|
| ER Registration | ✅ | Rapid registration with unidentified patient support |
| Triage | ✅ | Configurable scales, acuity levels |
| Triage Assignments | ✅ | Patient-to-scale mapping |
| ER Events | ✅ | Timestamped event log |
| Disposition | ✅ | Admission, discharge, transfer pathways |

## 7. OPERATING THEATRE (OT)

| Feature | Status | Notes |
|---------|--------|-------|
| Theatre Scheduling | ✅ | Resource-based scheduling |
| Surgical Team | ✅ | Surgeon, anesthetist, nurse assignment |
| Anesthesia Records | ✅ | Type, timing, status |
| Surgical Checklists | ✅ | Template-based safety checklists |
| Recovery Records | ✅ | Post-operative recovery tracking |

## 8. ICU

| Feature | Status | Notes |
|---------|--------|-------|
| ICU Bed Management | ✅ | Acuity-level beds |
| ICU Admissions | ✅ | Source, acuity, observation intervals |
| Observation Sets | ✅ | Structured ICU observations |
| Warning Scores | ✅ | Automated severity scoring |
| ICU Alerts | ✅ | Missed observation, threshold alerts |
| Critical Care Notes | ✅ | Daily goal notes |

## 9. BLOOD BANK

| Feature | Status | Notes |
|---------|--------|-------|
| Donor Management | ✅ | Registration, screening |
| Donations | ✅ | Collection with phlebotomist tracking |
| Blood Units | ✅ | Component separation, expiry, status |
| Compatibility Testing | ✅ | ABO/Rh, antibody screening |
| Crossmatching | ✅ | Unit-patient compatibility verification |
| Transfusion | ✅ | Dual-verification, reaction reporting |
| Unit Traceability | ✅ | Full donor-to-patient chain |

## 10. BILLING & FINANCE

| Feature | Status | Notes |
|---------|--------|-------|
| Charge Capture | ✅ | Automatic and manual charges |
| Invoices | ✅ | Multi-line invoices |
| Payments | ✅ | Idempotent payment processing |
| Refunds | ✅ | Full lifecycle: request → approve → disburse |
| Deposits | ✅ | Advance deposits with allocation |
| Settlements | ✅ | Insurance claim settlement |
| Claims | ✅ | Claim creation and line management |
| Void/Approval | ✅ | Segregation of duties for voids |
| Aging Reports | ✅ | Outstanding balance tracking |
| Revenue Reports | ✅ | Domain-specific analytics |
| Financial Periods | ✅ | Open/close/lock lifecycle |
| Budget Management | ✅ | Department-level budgets with enforcement |
| Expense Tracking | ✅ | Category-based with approval workflow |
| Integer Money | ✅ | All amounts in minor units (no floating point) |

## 11. PROCUREMENT & INVENTORY

| Feature | Status | Notes |
|---------|--------|-------|
| Inventory Items | ✅ | Full catalog with reorder levels |
| Stock Movements | ✅ | Transactional movement ledger |
| Transfers | ✅ | Inter-facility stock transfers |
| Adjustments | ✅ | Approval-gated adjustments |
| Reorder Alerts | ✅ | Automatic low-stock detection |
| Vendors | ✅ | Encrypted credential storage |
| Purchase Requests | ✅ | Multi-step approval workflow |
| Purchase Orders | ✅ | Contract-price enforcement |
| Goods Receipt | ✅ | Partial receipt support |
| Three-Way Match | ✅ | Order → receipt → invoice matching |
| Vendor Contracts | ✅ | Price and validity management |

## 12. HR & ASSETS

| Feature | Status | Notes |
|---------|--------|-------|
| Employee Records | ✅ | Staff with department/facility linkage |
| Department Management | ✅ | Hierarchical departments |
| Shift Templates | ✅ | Configurable shift patterns |
| Rosters | ✅ | Staff-to-shift assignment |
| Attendance | ✅ | Clock-based attendance tracking |
| Leave Management | ✅ | Leave types, requests, approval |
| Payroll Export | ✅ | Payroll-ready data export |
| Asset Register | ✅ | Lifecycle tracking per asset |
| Asset Transfers | ✅ | Location-based transfers |
| Maintenance Schedules | ✅ | Preventive maintenance scheduling |
| Work Orders | ✅ | Maintenance request tracking |
| IoT Readings | ✅ | Asset sensor data ingestion |

## 13. ONCOLOGY

| Feature | Status | Notes |
|---------|--------|-------|
| Oncology Patient Profile | ✅ | Staging, diagnosis |
| Treatment Plans | ✅ | Protocol-based planning |
| Treatment Cycles | ✅ | Cycle tracking with medications |
| Chemotherapy Protocols | ✅ | Medication protocols |
| Toxicity Tracking | ✅ | Adverse event recording |
| Multidisciplinary Review | ✅ | Team-based case review |
| Radiotherapy | 🧪 | Treatment courses, fractions, plans |

## 14. TELEMEDICINE & RPM

| Feature | Status | Notes |
|---------|--------|--
|---------|--------|-------|
| Multi-Tenancy | ✅ | Complete tenant isolation |
| Facility Scoping | ✅ | Facility-level data boundaries |
| RBAC | ✅ | Role-based access control |
| MFA | ✅ | TOTP-based MFA |
| Session Management | ✅ | Token rotation, refresh revocation |
| RLS (PostgreSQL) | ✅ | 712 policies, 179 FORCE-RLS tables |
| Claims-Based RLS | ✅ | Zero legacy GUC references |
| Audit Logging | ✅ | PHI-safe, hash-chained events |
| Secrets Management | ✅ | Encrypted storage, no exposure |
| Rate Limiting | ✅ | API-level rate limiting |
| Platform Admin Isolation | ✅ | Support sessions with boundary |
| API Security | ✅ | CORS, CSP, auth enforcement |

---

*This matrix reflects actual implementation state as of commit 10fac20. Items marked 🔌 require real external systems that are not yet connected.*
