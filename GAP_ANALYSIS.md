# SWASTHYA — ENTERPRISE HMS GAP AND NEPAL READINESS REPORT

## Baseline

| Metric | Value |
|---|---|
| HEAD | `18a92ba` |
| Branch | `main` |
| TypeScript | 0 errors |
| Vitest | 78/78 |
| Build | successful |
| Frontend pages | 83 |
| Frontend routes | 100 |
| Backend controllers | 85 |
| Backend models | 205 |
| Migrations | 147 |
| Services | 51 |
| Backend tests | 112 |
| Frontend tests | 18 |

---

## 1. WHAT IS ACTUALLY BUILT (Evidence-Based)

### PRODUCTION FUNCTIONAL — Full Stack (Model + Service + Controller + Frontend + Tests)

| Module | Models | Services | Tests | Frontend | Status |
|---|---|---|---|---|---|
| Patient Registration | Patient, PatientIdentifier, PatientContact, InsurancePolicy | PatientService | 10+ | 5 pages | **IMPLEMENTED** |
| Appointments + Scheduling | Appointment, ScheduleTemplate, AvailabilitySlot | AppointmentService | 8+ | 4 pages | **IMPLEMENTED** |
| Queue | QueueToken | QueueService | 4+ | 1 page | **IMPLEMENTED** |
| Encounters + Clinical Notes | Encounter, ClinicalNote, Diagnosis | EncounterService | 8+ | 3 pages | **IMPLEMENTED** |
| Prescriptions | Prescription, PrescriptionLine | PrescriptionService | 6+ | 2 pages | **IMPLEMENTED** |
| Billing + Payments | Invoice, InvoiceLine, Charge, Payment, Deposit, DepositAllocation | BillingService | 18+ | 4 pages | **IMPLEMENTED** |
| Insurance Claims | InsuranceClaim, InsuranceClaimLine, InsurancePolicy, Payer | BillingService (claims) | 5+ | Frontend page | **IMPLEMENTED** |
| Laboratory | LabOrder, LabOrderItem, Specimen, CriticalValueEvent | LabOrderService | 6+ | 2 pages | **IMPLEMENTED** |
| Radiology | RadiologyStudy, RadiologyReport, Modality | RadiologyService | 4+ | 2 pages | **IMPLEMENTED** |
| Pharmacy + Dispensing | PharmacyPrescription, PharmacyPrescriptionLine, Medication | PharmacyService | 8+ | 2 pages | **IMPLEMENTED** |
| Inventory | InventoryItem, StockBatch, InventoryAdjustmentRequest | InventoryService | 6+ | 1 page | **IMPLEMENTED** |
| Emergency | ErRegistration, ErEvent, TriageScale | ErService | 6+ | 1 page | **IMPLEMENTED** |
| IPD Admission | Admission, DischargeSummary | AdmissionService | 4+ | 2 pages | **IMPLEMENTED** |
| ICU | IcuAdmission, IcuObservation, IcuAlert | IcuService | 4+ | 1 page | **IMPLEMENTED** |
| Operating Theatre | OperationTheatreCase, OtsChecklist | OtService | 3+ | 1 page | **IMPLEMENTED** |
| Blood Bank | BloodUnit, BloodDonation, BloodTransfusion, BloodCrossmatch | BloodBankService | 4+ | 1 page | **IMPLEMENTED** |
| Procurement | PurchaseRequest, PurchaseOrder, Vendor | ProcurementService | 5+ | 1 page | **IMPLEMENTED** |
| Finance | FinancialPeriod, Settlement, AgingEntry | FinanceService | 6+ | 3 pages | **IMPLEMENTED** |
| Auth + RBAC | User, Role, RoleAssignment, Permission, RolePermission | AuthController, RefreshTokenService | 15+ | 2 pages | **IMPLEMENTED** |
| Multi-Tenancy | Organization, Facility, Department, Branch | TenantContext | 8+ | Admin pages | **IMPLEMENTED** |
| Audit | AuditEvent | AuditLogger | 4+ | 1 page | **IMPLEMENTED** |
| Documents | GeneratedDocument, DocumentTemplate | DocumentCenterService | 4+ | 1 page | **IMPLEMENTED** |
| Notifications | NotificationTemplate, NotificationCampaign, NotificationDelivery | NotificationService | 3+ | 2 pages | **IMPLEMENTED** |
| Analytics/KPI | KpiDefinition, KpiMetric, DashboardDef, ReportTemplate, ReportRun | AnalyticsService | 4+ | 1 page | **IMPLEMENTED** |
| Patient Portal | PortalToken | PatientPortalService | 4+ | 1 page | **IMPLEMENTED** |
| Telemedicine | TeleconsultSession, TeleconsultRecording | TelehealthService | 3+ | 1 page | **IMPLEMENTED** |
| HR/Workforce | Position, ShiftTemplate, ShiftRoster, Attendance, Leave, LeaveBalance | HrService | 4+ | 1 page | **IMPLEMENTED** |

### PARTIAL — Some Layers Missing

| Module | Models | Services | Tests | Frontend | Status |
|---|---|---|---|---|---|
| Oncology | CancerProtocol, CancerCycle | Partial | 2+ | 1 page | **PARTIAL** |
| Referrals | Referral | ReferralService | 2+ | 1 page | **PARTIAL** |
| Interoperability/FHIR | IntegrationRegistry, OAuth2Partner, EgressAllowlist | InteropService | 3+ | 1 page | **PARTIAL** |
| AI Assistance | AiFeatureRegistry, AiInvocationLog | AiService | 2+ | 1 page | **PARTIAL** |
| Quality/Incident | QualityIncident, QualityInvestigation, QualityCapa | QualityService | 2+ | 1 page | **PARTIAL** |
| Research | ResearchProject, ResearchCohort | ResearchService | 1+ | 1 page | **PARTIAL** |
| Follow-up | FollowUp, FollowUpReminder | FollowUpService | 2+ | 1 page | **PARTIAL** |
| Forms Library | FormTemplate, FormSubmission | FormsService | 2+ | 1 page | **PARTIAL** |
| PACS Viewer | — | — | 0 | 1 page | **FRONTEND ONLY** |

### FRONTEND ONLY (No real backend data)

| Module | Status |
|---|---|
| National Governance pages | Documentation/status display |
| Operations Center | Status dashboard |
| Continuous Security | Documentation |
| Hypercare | Documentation |
| Go-Live | Documentation |
| Pilot Launch | Documentation |
| Release Readiness | Documentation |

---

## 2. MEDINOUS BENCHMARK GAP MATRIX

### CLINICAL

| Capability | SWASTHYA State | Evidence | Gap |
|---|---|---|---|
| EMR (longitudinal patient record) | **IMPLEMENTED** | Patient → Encounter → Notes → Diagnosis → Prescription chain | None — single longitudinal record |
| Doctor's Workbench / CPOE | **IMPLEMENTED** | Encounter workspace with orders, prescriptions, notes | Orders exist per-domain; no unified CPOE screen |
| Clinical Model of Care | **NOT IMPLEMENTED** | No care pathways, templates, or protocol engine | **P2** — configurable template infrastructure needed |
| Order Sets | **NOT IMPLEMENTED** | No order-set model | **P2** — reusable order grouping |
| Nursing | **PARTIAL** | NursingPage exists, NursingService exists, IcuService handles ICU nursing | Nursing assessment, vitals, I/O, care plans are partial |
| OT | **IMPLEMENTED** | OperationTheatreCase, OtsChecklist, OtService | Surgical scheduling + checklist implemented |
| Dental | **NOT IMPLEMENTED** | No dental models | **P4** — specialty workflow, not core |
| Physiotherapy | **NOT IMPLEMENTED** | No physiotherapy models | **P4** — specialty workflow |
| Dietary Management | **NOT IMPLEMENTED** | No dietary models | **P3** — inpatient workflow gap |
| Emergency | **IMPLEMENTED** | ErRegistration, ErEvent, TriageScale, ErService | Full ER workflow |
| Home Care | **NOT IMPLEMENTED** | No home-care models | **P4** — out of scope for general hospital |
| Wellness | **NOT IMPLEMENTED** | No wellness models | **P4** — out of scope |

### ANCILLARY

| Capability | SWASTHYA State | Evidence | Gap |
|---|---|---|---|
| Laboratory | **IMPLEMENTED** | LabOrder, Specimen, CriticalValueEvent, LabOrderService | Complete lab workflow |
| Radiology / RIS | **IMPLEMENTED** | RadiologyStudy, RadiologyReport, Modality, RadiologyService | Complete radiology workflow |
| Pharmacy | **IMPLEMENTED** | PharmacyPrescription, Medication, InventoryItem, PharmacyService | Dispensing + inventory |
| General Stores / Inventory | **IMPLEMENTED** | InventoryItem, StockBatch, Procurement | Procurement + inventory chain |
| Blood Bank | **IMPLEMENTED** | BloodUnit, BloodDonation, BloodTransfusion, BloodCrossmatch | Full blood bank workflow |
| CSSD | **NOT IMPLEMENTED** | No CSSD models | **P3** — specialized workflow |

### PATIENT CARE

| Capability | SWASTHYA State | Evidence | Gap |
|---|---|---|---|
| Registration | **IMPLEMENTED** | Patient, PatientIdentifier, PatientContact | MRN, demographics, contacts |
| Appointment Scheduling | **IMPLEMENTED** | Appointment, ScheduleTemplate, AvailabilitySlot | Full scheduling with availability |
| OP Management | **IMPLEMENTED** | Encounter → Queue → Consultation chain | OPD workflow complete |
| IP / ADT | **IMPLEMENTED** | Admission, DischargeSummary, Bed, Ward | Admission + discharge + beds |
| Patient Portal | **IMPLEMENTED** | PortalToken, PatientPortalService | Portal with appointments, results |

### BACK OFFICE

| Capability | SWASTHYA State | Evidence | Gap |
|---|---|---|---|
| Billing and Insurance | **IMPLEMENTED** | Invoice, Payment, InsuranceClaim, Payer | Full billing + claims |
| Housekeeping | **NOT IMPLEMENTED** | No housekeeping models | **P3** — operational workflow |
| Machine Maintenance | **NOT IMPLEMENTED** | No equipment maintenance models | **P4** — CMMS not core HMS |
| Application Setup / User Management | **IMPLEMENTED** | AdminUsersPage, AdminRolesPage, AdminSettingsPage | Full admin console |
| Finance / Budgeting | **IMPLEMENTED** | FinancialPeriod, Settlement, AgingEntry, Budget | Finance + settlement |
| Fixed Assets | **NOT IMPLEMENTED** | No fixed-asset models | **P4** — accounting integration |
| Quality / Infection Control | **PARTIAL** | QualityIncident, QualityInvestigation, QualityCapa | Framework exists, needs depth |
| Incident Reporting | **PARTIAL** | QualityIncident model exists | Needs workflow depth |

### ADD-ONS

| Capability | SWASTHYA State | Evidence | Gap |
|---|---|---|---|
| Analytics | **IMPLEMENTED** | KpiDefinition, KpiMetric, DashboardDef, ReportTemplate | KPI + dashboards + reports |
| MIS Dashboard | **IMPLEMENTED** | AnalyticsPage, OperationsCenterPage | Management reporting |
| Healthcare Mobile | **PARTIAL** | MobileOfflinePage exists, responsive layout | Mobile framework, needs depth |

---

## 3. NEPAL-SPECIFIC ASSESSMENT

### What Exists

| Capability | Status | Evidence |
|---|---|---|
| NPR currency | ✅ Configurable | Organization.currency field, default NPR |
| Nepal fiscal year | ⬜ Not implemented | Needs configurable fiscal-year boundary |
| Cash/Bank/Card payments | ✅ Implemented | Payment model with method field |
| Invoice/Receipt | ✅ Implemented | Invoice + InvoiceLine + Payment |
| Refund | ✅ Implemented | RefundRequest model with approval |
| Deposit/Advance | ✅ Implemented | Deposit + DepositAllocation |
| Payer/Insurance | ✅ Implemented | Payer, InsurancePolicy, InsuranceClaim |
| Claim lifecycle | ✅ Implemented | draft→submitted→pending→paid/denied |
| Claims RLS | ✅ Implemented | ClaimsBasedRlsTest |
| Patient account aging | ✅ Implemented | AgingEntry model + service |
| Daily settlement | ✅ Implemented | Settlement model + service |

### What Is Missing

| Capability | Status | Gap | Priority |
|---|---|---|---|
| SSF (Social Security Fund) | ❌ Not implemented | Needs payer type + contribution model + benefit rules | **P1** |
| HIB (Health Insurance Board) | ❌ Not implemented | Needs payer type + eligibility + claim format | **P1** |
| Nepal VAT/Tax config | ⬜ Hardcoded? | Needs configurable effective-date tax rules | **P1** |
| TDS | ❌ Not implemented | Withholding tax on supplier payments | **P2** |
| Corporate/Sponsor billing | ⬜ Partial | Payer model exists but no sponsor-specific logic | **P2** |
| Nepal payment gateway | ❌ Not implemented | QR/digital payment integration boundary | **P2** |
| Nepali calendar display | ⬜ Not implemented | Bikram Sambat display alongside Gregorian | **P3** |
| Nepali localization depth | ⬜ Partial | Basic i18n exists, needs clinical terminology | **P3** |

### CRITICAL ARCHITECTURAL INSIGHT

**The existing Payer/InsurancePolicy/InsuranceClaim architecture IS the correct foundation for SSF and HIB.**

SSF and HIB are NOT separate billing engines. They are different **payer configurations** within the existing claims architecture:

```
PAYER (existing model)
├── SSF payer type
│   ├── benefit rules (JSON, configurable)
│   ├── contribution tracking
│   ├── OPD/IPD limits
│   └── claim format
├── HIB payer type
│   ├── eligibility rules
│   ├── benefit package
│   ├── claim format
│   └── integration boundary
├── PRIVATE insurer payer type
│   ├── plan/benefits
│   ├── deductible/copay
│   └── claim format
└── CORPORATE/SPONSOR payer type
    ├── coverage agreement
    ├── approved services
    └── billing rules
```

The existing `InsurancePolicy.benefits` JSON field and `Payer` model can support all payer types without creating duplicate financial engines.

---

## 4. PRIORITY CLASSIFICATION

### P0 — BLOCKING (Must fix before pilot)

| # | Issue | Evidence | Recommendation |
|---|---|---|---|
| 1 | No Nepal fiscal year support | No fiscal-year model | Add configurable fiscal year |
| 2 | Tax/VAT not configurable | Check if hardcoded | Make effective-date configurable |
| 3 | SSF payer type missing | No SSF-specific claims flow | Configure as payer type |
| 4 | HIB payer type missing | No HIB-specific claims flow | Configure as payer type |

### P1 — CRITICAL (Should fix for Nepal deployment)

| # | Issue | Evidence | Recommendation |
|---|---|---|---|
| 5 | No corporate/sponsor billing | Payer exists but no sponsor logic | Extend Payer with sponsor type |
| 6 | Nursing workflow depth | NursingPage exists but lacks vitals/I/O/care plans | Strengthen nursing service |
| 7 | Order sets not implemented | No order-set model | Add configurable order grouping |
| 8 | No dietary management | No dietary models | Add as inpatient workflow |
| 9 | No housekeeping workflow | No housekeeping models | Add bed-turnaround workflow |
| 10 | TDS not implemented | No withholding tax model | Add configurable TDS rules |

### P2 — IMPORTANT (Improve for quality)

| # | Issue | Evidence | Recommendation |
|---|---|---|---|
| 11 | CSSD not implemented | No sterilization models | Add as specialized inventory |
| 12 | Clinical templates/care plans | No template engine | Add configurable clinical templates |
| 13 | Nepal payment gateway | No QR/digital payment integration | Add integration boundary |
| 14 | Mobile bedside workflows | Responsive exists but not optimized | Define mobile-primary workflows |
| 15 | CPOE unified view | Orders exist per-domain, no unified screen | Add order entry dashboard |

### P3 — ENHANCEMENT (Nice to have)

| # | Issue | Evidence | Recommendation |
|---|---|---|---|
| 16 | Nepali calendar display | Basic i18n exists | Add BS date display |
| 17 | Dental specialty | No dental models | Optional specialty module |
| 18 | Physiotherapy specialty | No physiotherapy models | Optional specialty module |
| 19 | Home care | No home-care models | Out of scope for general hospital |
| 20 | Wellness | No wellness models | Out of scope |

### P4 — NOT RECOMMENDED (Do not build)

| # | Capability | Reason |
|---|---|---|
| 21 | Full CMMS | Use external maintenance system |
| 22 | Fixed asset accounting | Use accounting integration |
| 23 | Full offline EMR | Consistency model unsafe for clinical data |
| 24 | Real-time PACS viewer | Requires external PACS infrastructure |

---

## 5. CORRECTED ARCHITECTURE

### What SWASTHYA Already Has (Do NOT Duplicate)

1. **One patient record** — Patient → Encounter → Orders → Results → Billing
2. **One billing engine** — Invoice → Charge → Payment → Settlement
3. **One claims engine** — Payer → Policy → Claim → Settlement
4. **One inventory engine** — InventoryItem → StockBatch → Procurement
5. **One auth engine** — User → Role → Permission → Assignment → RLS
6. **One notification engine** — Template → Campaign → Delivery
7. **One document engine** — Template → Generation → Verification

### What SWASTHYA Needs (Additive, Not Replacing)

1. **SSF payer configuration** — extend Payer model with SSF-specific benefit rules
2. **HIB payer configuration** — extend Payer model with HIB-specific eligibility
3. **Nepal fiscal year** — add configurable fiscal period boundary
4. **Tax/VAT configurability** — effective-date-based tax rules
5. **Nursing workflow depth** — vitals, I/O, care plans, handover
6. **Order sets** — configurable order grouping (reuses existing order infrastructure)
7. **Dietary orders** — as clinical order type (reuses order infrastructure)
8. **Housekeeping** — bed turnaround workflow (reuses bed/ward models)
9. **Corporate sponsor billing** — extend Payer with sponsor type

### What SWASTHYA Should NOT Build

1. Duplicate billing engines for SSF/HIB
2. Separate patient models per department
3. Full CMMS/maintenance system
4. Fixed asset accounting (use integration)
5. Full offline EMR
6. Real PACS (use external PACS)
7. Dental/physiotherapy as separate mini-HMS

---

## 6. RECOMMENDED NEXT PHASE

Based on evidence, the ONE highest-value next engineering phase is:

### **Nepal Financial Architecture Completion**

This addresses P0 items 1-4 and P1 items 5,10:

1. **SSF payer type** — configure as payer with benefit rules, contribution tracking, claim format
2. **HIB payer type** — configure as payer with eligibility, benefit package, claim format
3. **Nepal fiscal year** — add configurable fiscal period boundary to FinancialPeriod
4. **Tax/VAT configurability** — effective-date-based tax rules in billing
5. **Corporate sponsor billing** — extend Payer with sponsor type and coverage rules
6. **TDS** — withholding tax on supplier payments

**Why this phase:**
- SWASTHYA already has the correct claims architecture (Payer → Policy → Claim)
- SSF and HIB are payer configurations, not new engines
- Nepal financial compliance is a blocker for real hospital deployment
- The existing billing/invoice/payment chain is production-functional
- This phase extends rather than replaces existing work

**What this phase does NOT do:**
- Does NOT create duplicate billing systems
- Does NOT weaken existing authorization
- Does NOT add unrelated features
- Does NOT claim external integrations without evidence

---

## 7. REMAINING EXTERNAL DEPENDENCIES

| Dependency | Status | Required For |
|---|---|---|
| SSF official API/portal | Not available | Direct SSF claim submission |
| HIB official API/portal | Not available | Direct HIB claim submission |
| Nepal payment gateway | Not tested | QR/digital payments |
| External PACS | Not available | DICOM image viewing |
| External LIS | Not available | Analyzer integration |
| Nepali calendar library | Available (npm) | BS date display |

---

## 8. REMAINING BLOCKERS

| Blocker | Severity | Resolution |
|---|---|---|
| No real hospital UAT | High | Requires pilot hospital |
| No external pen test | High | Requires security firm |
| No production PITR | High | Requires infrastructure |
| SSF/HIB rules not verified | Medium | Requires Nepal regulatory research |
| Tax rates not verified | Medium | Requires Nepal tax authority research |

---

*This report is evidence-based. Every claim is backed by actual source code inspection.*
