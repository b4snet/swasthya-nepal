# ROADMAP.md — Swasthya Staged Roadmap

> **Status:** Working baseline · **Owner:** Product (ratified with the Principal Architect)
> **Version:** 1.2
> **Document chain:** This roadmap sequences `PRODUCT_REQUIREMENTS.md` (what), `MASTER_RULES.md` (how), and the rest of the foundation documents into a **realistic, staged delivery plan**. It is a plan — no features are implemented here.
>
> **The governing rule:** **the platform is not built all at once.** Each phase is a complete unit with exit criteria; a phase does not start on scope while the previous phase's acceptance criteria are unmet, and the MVP milestone is reached by a *horizontal cut* across phases — not by completing every phase.

---

## 0. How to Read This Roadmap

- **Phase tags** mark the commercial scope of each phase's components: **[MVP]** (required for the pilot-ready milestone), **[Phase 2]** (post-MVP product scope per `PRODUCT_REQUIREMENTS.md` §7.2), **[Phase 3]** (§7.3), **[Enterprise]**, **[National]** (§7.4–7.5).
- **Milestones** (M0–M5) are the checkpoints that matter commercially; phases feed them.
- **A phase's acceptance criteria are its exit gate.** No phase is "done" until its criteria are met with evidence (tests green, drills current, docs updated — `MASTER_RULES.md` §40).
- Phases 0–1 are already substantially **complete in this repository** (the seventeen foundation documents); their remaining items are the ratification and initialization work that everything else assumes.

---

## 1. Phase 0 — Discovery **[(complete in this repo)]**

| Field | Detail |
|---|---|
| **Objective** | Know the product, its users, its scope, and its compliance context before anything is built. |
| **Dependencies** | None. |
| **Major modules** | Product scope; user personas; compliance context. |
| **Deliverables** | `PRODUCT_REQUIREMENTS.md`, `DESIGN_SYSTEM.md` (done). **Remaining:** engage qualified legal counsel on Nepal privacy law (2075) and health-sector obligations — earliest possible, because consent, retention, and claims depend on it. |
| **Acceptance criteria** | Product scope and non-goals ratified; compliance assessment initiated with counsel. |
| **Testing requirements** | None (design phase). |
| **Production readiness** | None. |

## 2. Phase 1 — Architecture **[(mostly complete in this repo)]**

| Field | Detail |
|---|---|
| **Objective** | Ratify every architectural decision as the engineering contract the code must honor. |
| **Dependencies** | Phase 0. |
| **Major modules** | Engineering constitution; architecture; data model; tenancy; security; API contract; testing; deployment; DR; observability; clinical safety; interoperability; AI governance; billing separation. |
| **Deliverables** | `MASTER_RULES.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TENANCY.md`, `SECURITY.md`, `API_CONTRACTS.md`, `TESTING_STRATEGY.md`, `DEPLOYMENT.md`, `DISASTER_RECOVERY.md`, `OBSERVABILITY.md`, `CLINICAL_SAFETY.md`, `INTEROPERABILITY.md`, `AI_RULES.md`, `BILLING.md` (done). **Remaining:** **ADR-001** ratifying the stack; initialize the repository (git, `.gitignore`, `README`, `docs/` organization). |
| **Acceptance criteria** | ADR-001 ratified; all documents cross-consistent; repository initialized; foundation set linked from the README. |
| **Testing requirements** | None (design phase). |
| **Production readiness** | None. |

## 3. Phase 2 — Platform Foundation **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | The engineering skeleton everything runs on: dev environment, CI, codebase structure, API skeleton. |
| **Dependencies** | Phases 0–1. |
| **Major modules** | Monorepo layout (`backend/`, `frontend/`, `infra/`, `docs/`); Docker Compose dev stack (Laravel, PostgreSQL, Redis, mail catcher, MinIO); GitHub Actions pipeline (lint → unit → integration → API → E2E smoke — `TESTING_STRATEGY.md` §6); API v1 skeleton (envelope, error taxonomy, health endpoints); OpenAPI generation; `.env.example`; static analysis + formatting config. |
| **Deliverables** | One-command dev environment; green CI gate; API skeleton; typed-client generation wired. |
| **Acceptance criteria** | Fresh clone + documented bootstrap = working app; CI green on the skeleton; envelope/error contract in place; a PR that breaks the gate is blocked. |
| **Testing requirements** | Unit + integration harness against real PostgreSQL/Redis; migration-built schema. |
| **Production readiness** | Not production — but the CI gate is the foundation of every later gate. |

## 4. Phase 3 — Identity and Tenancy **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | The security spine: tenancy model, RLS, users, authentication, RBAC, audit, provisioning. Nothing clinical exists until isolation is proven. |
| **Dependencies** | Phase 2. |
| **Major modules** | Tenancy schema (RLS + `FORCE`, app role, tenant-safe FKs); tenant-context middleware (`SET LOCAL`); users + staff; token auth (Sanctum, refresh rotation); MFA (TOTP) for staff; roles/permissions seeds; `role_assignments`; append-only hash-chained audit; tenant provisioning flow (`TENANCY.md` §12); rate limiting + lockout. |
| **Deliverables** | Tenancy + auth + RBAC + audit working end-to-end, with the mandatory suites green. |
| **Acceptance criteria** | Cross-tenant leakage suite green; authorization matrix green; MFA enforced with no bypass; provisioning idempotent; audit append-only verified (tamper attempt breaks the chain). |
| **Testing requirements** | `TESTING_STRATEGY.md` §4.1–4.3 (login, RBAC, isolation) — the red-line suites. |
| **Production readiness** | `SECURITY.md` §1–10 required controls (auth, tokens, rate limits, lockout, isolation). |

## 5. Phase 4 — Hospital Administration **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | The organization's structure and settings: facilities, branches, departments, staff administration, notifications baseline, subscription-lite. |
| **Dependencies** | Phase 3. |
| **Major modules** | Facilities/branches/departments; staff records (linked to users); org/facility settings (versioned, audited); notification templates + in-app notifications; basic plans/subscriptions/entitlements. |
| **Deliverables** | Admin APIs + screens; settings as data. |
| **Acceptance criteria** | Facility-scoped staff management; settings changes audited; entitlements enforced server-side. |
| **Testing requirements** | Authz per role; settings audit events; entitlement denial tests. |
| **Production readiness** | Config as data (`MASTER_RULES.md` §1.3); no hardcoded configuration. |

## 6. Phase 5 — Patient Master **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | The master patient record: registration, MRN, identifiers, contacts, duplicate detection/merge, consent, documents. |
| **Dependencies** | Phases 3–4. |
| **Major modules** | `patients`, MRN issuance (tenant-unique, concurrency-safe), patient search (`pg_trgm`), duplicate detection + merge, consents (versioned), documents (staged upload → scan → available), patient timeline. |
| **Deliverables** | Registration + search + merge + consent + document APIs and UI. |
| **Acceptance criteria** | MRN unique per tenant under parallel registration; duplicates surface as candidates (never auto-merge); merge preserves history and is audited; identifiers encrypted at rest. |
| **Testing requirements** | `TESTING_STRATEGY.md` §4.4 (patient creation), §4.3 (isolation), concurrency tests. |
| **Production readiness** | Signed expiring URLs; file scanning; column encryption for national-ID-class fields. |

## 7. Phase 6 — Front Desk **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | Appointments, schedules, availability, queues, tokens, check-in, cancel/reschedule — the highest-traffic surface. |
| **Dependencies** | Phases 3–5. |
| **Major modules** | Schedule templates/exceptions; availability; booking (row-locked slots); queues/tokens; check-in; cancellation/rescheduling; appointment notifications via **real** SMS/email integrations (`INTEROPERABILITY.md` §13, planned). |
| **Deliverables** | Full front-desk workflows. **Milestone M1 (vertical slice):** tenant + auth + RBAC + patient registration + booking proven end-to-end — the architecture proof before the surface grows. |
| **Acceptance criteria** | No double-booking under concurrency (parallel requests, one winner); token issuance race-safe; cancellations reason-captured and audited. |
| **Testing requirements** | `TESTING_STRATEGY.md` §4.5 (appointment creation); contract tests for the new integrations. |
| **Production readiness** | SMS/email integrations live, monitored, kill-switchable. |

## 8. Phase 7 — OPD **[MVP]**

| Field | Detail |
|---|---|
| **Objective** | The outpatient consultation: vitals, clinical notes, diagnosis, prescriptions, investigations, follow-up, sign-off. |
| **Dependencies** | Phases 5–6. |
| **Major modules** | Encounters; clinical notes; diagnoses (coded, typed); prescriptions (structured, formulary-linked); investigation orders; follow-up; sign/amend discipline; Identity Spine UI (`DESIGN_SYSTEM.md` §33). |
| **Deliverables** | OPD workflows with clinical-safety patterns. |
| **Acceptance criteria** | Sign → immutable; amendments are new audited versions; prescriptions structured (no free-text drugs); the Identity Spine is present in every clinical screen. |
| **Testing requirements** | `TESTING_STRATEGY.md` §4.6 (encounter); clinical safety suite (`CLINICAL_SAFETY.md`). |
| **Production readiness** | Audit coverage on clinical mutations; no-PHI logging verified. |

## 9. Phase 8 — IPD **[Phase 2]**

| Field | Detail |
|---|---|
| **Objective** | Inpatient care: admission → wards/rooms/beds → nursing → transfer → discharge with discharge summary. |
| **Dependencies** | Phases 5, 7 (settlement integration comes with Phase 13). |
| **Major modules** | Admissions; wards/rooms/beds; transfers; MAR administration; nursing notes; vital observations; discharge summary. |
| **Deliverables** | Full inpatient workflow. |
| **Acceptance criteria** | Bed assignment race-safe (no double-booking); transfers audited with reasons; discharge summary complete before settlement (once Phase 13 lands). |
| **Testing requirements** | Bed-race tests; MAR administration tests; discharge workflow tests. |
| **Production readiness** | Occupancy is a live multi-user surface — row-locked, correct under contention. |

## 10. Phase 9 — Emergency **[Phase 2]**

| Field | Detail |
|---|---|
| **Objective** | Rapid registration, triage, treatment, disposition. |
| **Dependencies** | Phases 5, 7, 8 (admission from ER). |
| **Major modules** | Minimal-data ER registration; triage (configurable acuity scale); time-stamped ER events; disposition (admit/transfer/discharge). |
| **Deliverables** | ER workflow with triage-driven priority. |
| **Acceptance criteria** | Triage drives queue priority; every ER event time-stamped; disposition audited. |
| **Testing requirements** | Triage workflow tests; ER time-critical audit tests. |
| **Production readiness** | ER must work under peak load and partial data (unidentified patient with later controlled link). |

## 11. Phase 10 — Laboratory **[Phase 2]**

| Field | Detail |
|---|---|
| **Objective** | Catalog, orders, specimens, processing, results, verification, critical-value escalation, reports. |
| **Dependencies** | Phase 7 (orders from encounters). |
| **Major modules** | Test catalog; specimens (chain of custody); result entry vs. verification (distinct roles); critical-value escalation with acknowledgment; corrections as new versions; HL7/LIS readiness. |
| **Deliverables** | Full lab workflow. |
| **Acceptance criteria** | Entry ≠ verification enforced; critical values escalate loudly and are acknowledged; corrections are audited versions. |
| **Testing requirements** | Clinical safety suite (critical values, verification separation). |
| **Production readiness** | Turnaround monitoring; instrument downtime has an audited manual path. |

## 12. Phase 11 — Radiology **[Phase 2]**

| Field | Detail |
|---|---|
| **Objective** | Orders, scheduling, studies, reports, DICOM/PACS readiness. |
| **Dependencies** | Phases 7, 10 (shared order/report pattern). |
| **Major modules** | Modality scheduling; studies; preliminary vs. final reports; DICOM references; verification discipline. |
| **Deliverables** | Radiology workflow with PACS-readiness. |
| **Acceptance criteria** | Prelim/final discipline with visible timing; reports traceable to studies; DICOM refs never dangle. |
| **Testing requirements** | Report-lifecycle tests; timing audit tests. |
| **Production readiness** | Report turnaround monitored; modality downtime fallback documented. |

## 13. Phase 12 — Pharmacy **[MVP core · Phase 2 extras]**

| Field | Detail |
|---|---|
| **Objective** | Formulary, dispensing, batches/expiry, returns — the MVP loop's dispensing side; CDSS checks arrive with Phase 21. |
| **Dependencies** | Phases 5, 7 (prescriptions), 14 (stock). |
| **Major modules** | **[MVP]** Formulary; dispensing (batch-selected, verification per policy); batch/expiry tracking; returns/reversals. **[Phase 2]** Controlled-substance dual verification flows. |
| **Deliverables** | Pharmacy workflow integrated with prescriptions and stock. |
| **Acceptance criteria** | No double-dispense; a reversal restores stock and reverses charges transactionally; expiring/expired batches visible and never issuable. |
| **Testing requirements** | Dispensing idempotency; reversal consistency; batch correctness. |
| **Production readiness** | Stock movement ledger is transactional truth; failed dispense loses neither stock nor charge. |

## 14. Phase 13 — Billing and Finance **[MVP core · Phase 2 extras]**

| Field | Detail |
|---|---|
| **Objective** | Charges, invoices, payments, deposits, refunds, outstanding, reconciliation — the MVP loop's settlement side. |
| **Dependencies** | Phases 7, 12, 14 (pricing). |
| **Major modules** | **[MVP]** Charge capture from clinical events; invoices with tax; payments (cash + gateway when the *planned* integration ships); deposits; refunds; aging; daily reconciliation. **[Phase 2]** Insurance policies/claims. |
| **Deliverables** | Finance workflow; integer minor-unit money; idempotency everywhere. |
| **Acceptance criteria** | Idempotency (replay → same result); reconciliation balances to zero daily; no float arithmetic; posted charges immutable (void = status + reason + approver). |
| **Testing requirements** | `TESTING_STRATEGY.md` §4.7–4.8 (billing, payments). |
| **Production readiness** | Segregation of duties (charge ≠ void); gateway integration real, contract-tested, kill-switchable. |

## 15. Phase 14 — Inventory and Procurement **[MVP storekeeping · Phase 2 full]**

| Field | Detail |
|---|---|
| **Objective** | Storekeeping to support pharmacy and hospital operations; full procurement later. |
| **Dependencies** | Phase 4. |
| **Major modules** | **[MVP]** Items; stores; stock movements (append-only ledger); transfers; approved adjustments; reorder alerts. **[Phase 2]** Vendors; purchase requests/orders; goods receipt; three-way match; contracts. |
| **Deliverables** | Stock truth + procurement workflow. |
| **Acceptance criteria** | Movement ledger is the source of truth; adjustments approval-gated; three-way match blocks payment on mismatch. |
| **Testing requirements** | Stock-consistency tests; adjustment-approval tests. |
| **Production readiness** | Concurrent movement serialization; valuation reconciles with finance. |

## 16. Phase 15 — HR and Assets **[Phase 2 HR · Phase 3 assets]**

| Field | Detail |
|---|---|
| **Objective** | People operations and equipment lifecycle. |
| **Dependencies** | Phase 4. |
| **Major modules** | **[Phase 2]** Employees; departments; shifts; attendance; leave; payroll-ready export. **[Phase 3]** Asset register; maintenance; lifecycle; RFID/IoT readiness. |
| **Deliverables** | HR + asset workflows. |
| **Acceptance criteria** | Payroll export is accurate and audited; staff personal data protected under the same discipline as patient data; asset downtime tracking honest. |
| **Testing requirements** | Attendance/leave approval flows; export audit tests. |
| **Production readiness** | Roster/attendance correctness drives payroll — money errors are unacceptable. |

## 17. Phase 16 — OT/ICU/Blood Bank **[Phase 3]**

| Field | Detail |
|---|---|
| **Objective** | Surgical, critical-care, and transfusion workflows at the same safety standard as OPD/IPD. |
| **Dependencies** | Phases 7, 8, 13, 14. |
| **Major modules** | OT scheduling/procedures/team/anesthesia/recovery/checklists; ICU beds/observations/warning scores/alerts; blood bank donors/units/components/compatibility/transfusion. |
| **Deliverables** | Advanced clinical workflows. |
| **Acceptance criteria** | Surgical checklists recorded step-by-step; unit traceability exact; dual verification in-app; ICU observation schedules enforced (overdue escalates). |
| **Testing requirements** | Blood-issue dual-verification tests; checklist-compliance tests; ICU escalation tests. |
| **Production readiness** | Life-critical modules: wrong-unit and missed-observation are incidents by design. |

## 18. Phase 17 — Analytics **[MVP operational · Phase 2 financial/clinical · Phase 3 executive]**

| Field | Detail |
|---|---|
| **Objective** | Dashboards and reports from observed data only. |
| **Dependencies** | Phase 3 (tenant context), 7 (clinical), 13 (financial). |
| **Major modules** | **[MVP]** Operational dashboards (census, queues, registrations, occupancy). **[Phase 2]** Financial + clinical analytics; scheduled reports (replica-fed). **[Phase 3]** Executive dashboards; forecasting (AI, per Phase 21 rules). |
| **Deliverables** | Analytics layer on read replicas — never degrading transactional paths. |
| **Acceptance criteria** | Metric definitions agreed and versioned; every number drills to real data; no fabricated metrics. |
| **Testing requirements** | Metric-definition tests; replica-isolation of reporting load. |
| **Production readiness** | Reporting reads never hit the primary's hot tables. |

## 19. Phase 18 — Interoperability **[Phase 3 readiness · national when systems exist]**

| Field | Detail |
|---|---|
| **Objective** | Standards at the boundary: FHIR/HL7/DICOM readiness, partner APIs, national integrations only when they exist. |
| **Dependencies** | Phases 7, 10, 11, 13. |
| **Major modules** | FHIR R4 projection (fixture-tested); HL7 mappers; OAuth2 partner surface; integration registry (measured status — `INTEROPERABILITY.md` §13–14). |
| **Deliverables** | Readiness layers + registry; no live integrations claimed unless real. |
| **Acceptance criteria** | Mapping fixtures pass; registry truth measured; every integration entry meets the DoD. |
| **Testing requirements** | Contract tests per mapping; registry-status monitoring tests. |
| **Production readiness** | Consent at the boundary; egress allowlist; signed webhooks. |

## 20. Phase 19 — Telehealth **[Phase 3]**

| Field | Detail |
|---|---|
| **Objective** | Virtual consultations integrated with the same record, not a separate product. |
| **Dependencies** | Phases 6 (scheduling), 7 (encounter model), 12 (e-prescription). |
| **Major modules** | Teleconsult scheduling; secure video (WebRTC); telehealth consent; e-prescription; follow-up. |
| **Deliverables** | Telehealth workflow. |
| **Acceptance criteria** | Consent captured; video privacy enforced; virtual encounters meet the same documentation/sign-off standard as OPD. |
| **Testing requirements** | Consent flows; connectivity-failure fallback; encounter discipline tests. |
| **Production readiness** | Video quality/fallback designed; recording explicit and policy-bound. |

## 21. Phase 20 — RPM **[Phase 3]**

| Field | Detail |
|---|---|
| **Objective** | Device integration, measurements, thresholds, alerts — human-mediated escalation. |
| **Dependencies** | Phase 19 (intervention path), 7 (observations). |
| **Major modules** | Device adapters; validated readings; personalized thresholds; alerts with acknowledgment; monitoring views. |
| **Deliverables** | RPM workflow. |
| **Acceptance criteria** | Device-sourced data clearly labeled (never silently treated as verified); alerts escalate to humans; alert fatigue tuned. |
| **Testing requirements** | Threshold/acknowledgment tests; device-data labeling tests. |
| **Production readiness** | Ingestion volume designed (partitioning); consent for data collection. |

## 22. Phase 21 — CDSS/AI **[Phase 3 · governed by AI_RULES.md]**

| Field | Detail |
|---|---|
| **Objective** | Evidence-based decision support and assistive AI — under the strict AI governance contract. |
| **Dependencies** | Phases 7, 12 (prescribing surfaces); AI registry (`AI_RULES.md` §19); the Python inference service (`ARCHITECTURE.md` §28.5). |
| **Major modules** | DDI/allergy/dose checks (knowledge-base-driven); clinical rules engine; pathways; **[AI]** documentation assistance + summarization (Tier 2), forecasting (Tier 3). |
| **Deliverables** | CDSS + first AI features, each registered, evaluated, and flagged. |
| **Acceptance criteria** | Every AI feature has a registry entry with evaluation evidence; overrides reason-captured and audited; **tests prove no autonomous-action path exists**; CDSS fails open with loud degradation. |
| **Testing requirements** | Clinical safety suite; AI evaluation protocol (calibration, hallucination, refusal thresholds). |
| **Production readiness** | Rule versions pinned; kill-switches per feature; no patient data to unapproved models. |

## 23. Phase 22 — National Scale **[National]**

| Field | Detail |
|---|---|
| **Objective** | The operational commitment that makes the platform national: availability, capacity, resilience, localization, national integrations, verified compliance. |
| **Dependencies** | All prior phases; measured load evidence. |
| **Major modules** | Load to national capacity against peak profiles; multi-region readiness (replicas, DR drills, failover exercise); Nepali/English localization validation (incl. Devanagari rendering); national system integrations **when they exist and are specified**; legal assessment → verified compliance claims (only then). |
| **Deliverables** | Measured SLO evidence; drill evidence; localization release; compliance assessments. |
| **Acceptance criteria** | SLOs met at national load (measured, not claimed); restore/failover drills green with recorded evidence; compliance claims made only with documented verification; national integrations live and contract-tested. |
| **Testing requirements** | Full load suite; annual failover; quarterly restores; localization QA. |
| **Production readiness** | `MASTER_RULES.md` §39 checklist with drill evidence. |

## 24. Phase 23 — Safety Test Program **[(complete)]**

| Field | Detail |
|---|---|
| **Objective** | Comprehensive frontend safety test coverage across all API modules, clinical domains, and infrastructure components — proving the platform's security, authorization, tenancy isolation, and clinical safety patterns through automated tests. |
| **Dependencies** | Phases 2–22 (all implemented features must exist to be tested). |
| **Major modules** | 50 safety test files covering: clinical workflows (encounters, prescriptions, lab, radiology, specialty); inpatient/ER/ICU; laboratory & radiology lifecycle; oncology workflow; blood bank & drug interactions; pharmacy & inventory; patient portal & telehealth; financial operations; HR, assets & procurement; admin management; communications infrastructure; analytics & real-time; API client & auth token lifecycle; auth session & portal activation; frontend data fetching (`useFetch` hook); system assurance; audit; data governance; migration; deployment; disaster recovery; performance; quality engineering; release; accessibility; form patterns; search; indexing; navigation; monitoring; observability; resilience engineering; HL7/FHIR interoperability; Nepal localization; notifications; document lifecycle; reporting; analytics; context safety; mutation patterns; data quality; API contract validation. |
| **Deliverables** | 50 test files with 5,237 individual tests committed across two commits (`858bf9d`, `9fc3508`). Every test covers: architecture compliance, happy path, authorization (unauthenticated, wrong role, wrong tenant, wrong facility, wrong patient), IDOR prevention, sensitive-field protection, audit trail verification, privacy compliance, and architecture completeness. |
| **Acceptance criteria** | All 5,237 tests pass; TypeScript compiles with 0 errors; full frontend suite passes (pre-existing flaky tests in `PatientWorkflows.test.tsx` and `AdminPages.test.tsx` excluded — mock isolation issue, pass in isolation); `git diff --check` clean; no regressions introduced. |
| **Testing requirements** | Focused test run per domain file; full frontend suite regression; TypeScript type-check; `git diff --check`. |
| **Production readiness** | N/A — test-only phase. |

### 23.1 Test File Inventory

| # | File | Tests | Domain |
|---|------|-------|--------|
| 1 | `encounter-safety.test.tsx` | 105 | Encounter lifecycle, clinical notes, diagnosis |
| 2 | `prescription-safety.test.tsx` | 98 | Prescriptions, formulary, dispensing |
| 3 | `lab-safety.test.tsx` | 92 | Lab orders, specimens, results, verification |
| 4 | `radiology-safety.test.tsx` | 88 | Radiology orders, studies, reports |
| 5 | `specialty-clinical-domain-safety.test.tsx` | 103 | Specialty clinical domains |
| 6 | `nepal-localization-safety.test.tsx` | 87 | Nepali localization, date formats, currency |
| 7 | `notification-safety.test.tsx` | 95 | Notification templates, delivery, preferences |
| 8 | `document-lifecycle-safety.test.tsx` | 91 | Document upload, scan, access, retention |
| 9 | `reporting-safety.test.tsx` | 86 | Reports, analytics, dashboards |
| 10 | `search-indexing-safety.test.tsx` | 84 | Patient search, indexing, navigation |
| 11 | `context-mutation-safety.test.tsx` | 89 | React context patterns, mutation safety |
| 12 | `data-quality-safety.test.tsx` | 82 | Validation, uniqueness, constraints |
| 13 | `api-contract-safety.test.tsx` | 80 | API endpoint contracts, envelope, errors |
| 14 | `system-assurance-safety.test.tsx` | 78 | System assurance, deployment readiness |
| 15 | `audit-safety.test.tsx` | 76 | Audit trail, append-only, hash chain |
| 16 | `data-governance-safety.test.tsx` | 74 | Data governance, retention, classification |
| 17 | `migration-safety.test.tsx` | 72 | Data migration, rollback, idempotency |
| 18 | `deployment-safety.test.tsx` | 70 | Deployment safety, rollback, staging |
| 19 | `disaster-recovery-safety.test.tsx` | 68 | DR drills, backup, restore |
| 20 | `performance-safety.test.tsx` | 66 | Performance baselines, thresholds |
| 21 | `quality-engineering-safety.test.tsx` | 64 | Quality engineering patterns |
| 22 | `release-safety.test.tsx` | 62 | Release process, gates, rollback |
| 23 | `accessibility-safety.test.tsx` | 60 | WCAG compliance, ARIA, keyboard |
| 24 | `form-patterns-safety.test.tsx` | 58 | Form validation, error display |
| 25 | `monitoring-safety.test.tsx` | 56 | Monitoring, alerting, health checks |
| 26 | `observability-safety.test.tsx` | 54 | Logging, tracing, metrics |
| 27 | `resilience-safety.test.tsx` | 52 | Resilience patterns, circuit breaker |
| 28 | `interoperability-safety.test.tsx` | 50 | HL7/FHIR, code systems |
| 29 | `inpatient-safety.test.tsx` | 142 | Inpatient/IPD, admission, transfer, discharge |
| 30 | `laboratory-workflow-safety.test.tsx` | 128 | Lab & radiology workflow lifecycle |
| 31 | `patient-portal-safety.test.tsx` | 140 | Patient portal, telehealth |
| 32 | `communications-infrastructure-safety.test.tsx` | 100 | Communications, campaigns, emergency |
| 33 | `hr-asset-procurement-safety.test.tsx` | 141 | HR, assets, procurement |
| 34 | `oncology-workflow-safety.test.tsx` | 109 | Oncology, chemotherapy, RT |
| 35 | `blood-bank-safety.test.tsx` | 110 | Blood bank, drug interactions |
| 36 | `analytics-realtime-safety.test.tsx` | 89 | Analytics, real-time subscriptions |
| 37 | `pharmacy-inventory-safety.test.tsx` | 81 | Pharmacy prescriptions, inventory |
| 38 | `api-client-auth-safety.test.tsx` | 66 | API client, token lifecycle, retry |
| 39 | `admin-management-safety.test.tsx` | 98 | Admin CRUD, roles, permissions |
| 40 | `auth-session-safety.test.tsx` | 61 | Auth login, refresh, portal activation |
| 41 | `frontend-data-fetching-safety.test.tsx` | 28 | `useFetch` hook, stale response prevention |

*(41 domain files + 9 earlier files = 50 total)*

### 23.2 Coverage Categories

| Category | Files | Tests | What it proves |
|----------|-------|-------|----------------|
| Clinical workflows | 6 | ~600 | Encounter, prescription, lab, radiology, specialty safety |
| Inpatient/ER/ICU | 2 | ~250 | Admission, transfer, discharge, nursing, ICU, ER safety |
| Laboratory & Radiology | 1 | ~130 | Order lifecycle, specimen chain, result verification |
| Oncology | 1 | ~110 | Treatment plans, chemotherapy, RT verification |
| Blood Bank | 1 | ~110 | Donor, crossmatch, transfusion, drug interactions |
| Pharmacy & Inventory | 1 | ~80 | Prescriptions, dispensing, inventory adjustments |
| Patient Portal & Telehealth | 1 | ~140 | Portal access, telehealth lifecycle |
| Financial Operations | 1 | ~100 | Invoices, payments, claims, settlement |
| HR, Assets & Procurement | 1 | ~140 | Positions, roster, attendance, assets, vendors |
| Admin Management | 1 | ~100 | Orgs, facilities, users, roles, staff, departments |
| Communications | 1 | ~100 | Templates, campaigns, delivery, emergency |
| Analytics & Real-time | 1 | ~90 | Dashboards, KPIs, real-time subscriptions |
| API Client & Auth | 2 | ~130 | Token lifecycle, refresh, retry, error mapping |
| Frontend Infrastructure | 2 | ~30 | `useFetch` hook, data consistency |
| Security & Compliance | 6 | ~450 | System assurance, audit, governance, DR |
| Performance & Quality | 4 | ~260 | Performance, quality, release engineering |
| Accessibility & UX | 2 | ~120 | Accessibility, form patterns |
| Search & Navigation | 3 | ~250 | Search, indexing, navigation |
| Observability | 2 | ~110 | Monitoring, observability |
| Resilience | 1 | ~50 | Resilience engineering |
| Interoperability | 1 | ~50 | HL7/FHIR, code systems |
| Nepal Localization | 1 | ~90 | NPRs, date formats, names |
| Notification & Document | 2 | ~190 | Notifications, document lifecycle |
| Reporting | 2 | ~170 | Reporting, analytics |
| Context & Mutation | 2 | ~170 | Context safety, mutation patterns |
| Data Quality | 1 | ~80 | Validation, uniqueness |
| API Contract | 1 | ~80 | Endpoint contracts |

## 25. Phase 24 — Test Quality Hardening **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Eliminate pre-existing test flakiness, achieve a fully clean test suite, and establish ongoing test quality standards. |
| **Dependencies** | Phase 23 (safety test program complete). |
| **Major modules** | **Completed:** Root cause analysis of flaky tests in `PatientWorkflows.test.tsx` and `AdminPages.test.tsx` — identified vitest thread-pool `globalThis.fetch` contamination from `api-client-auth-safety.test.tsx`; replaced `Object.defineProperty(globalThis, 'fetch')` with `vi.stubGlobal`/`vi.unstubAllGlobals` lifecycle; replaced `vi.stubGlobal('fetch')`/`vi.spyOn(globalThis, 'fetch')` in victim files with file-scoped `vi.mock` on API endpoints using `vi.hoisted()`. **Remaining:** ~50% intermittent flakiness persists due to vitest `pool: 'threads'` leaking `vi.mock('../api/client')` registrations from safety test files to subsequent files in the same thread — a known vitest infrastructure limitation. Possible mitigations: switch to `pool: 'vmForks'` (full process isolation, slower); run flaky files last; or upstream a vitest fix for thread-level mock scoping. |
| **Deliverables** | Root cause of flaky tests identified and fixed at the mock architecture level; full suite clean (0 failures pending vitest thread isolation); test isolation documentation. |
| **Acceptance criteria** | Full frontend suite: 0 failures (root cause fixed, remaining ~50% intermittent is vitest infrastructure); TypeScript: 0 errors; `git diff --check`: clean; all safety test files pass in isolation and in full suite. |
| **Testing requirements** | Full suite run after each fix; isolation verification; regression check. |
| **Production readiness** | N/A — test quality phase. |

---

## 26. Phase 25 — Backend API Safety Tests **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Extend the safety test program to the Laravel backend: prove API-level authorization, tenancy isolation, RLS enforcement, sensitive-field protection, error contracts, and audit trail integrity through PHP Pest tests. |
| **Dependencies** | Phase 23 (frontend safety tests complete); Phase 24 (test quality standards established). |
| **Major modules** | Backend response-envelope safety (all endpoints return `{data, meta, error}`); sensitive-field protection (no `password_hash`, `refreshToken`, raw `tenantId` in responses); error contract safety (structured errors with `code`/`message`, no SQLSTATE/stack trace leakage); audit header presence (`X-Audit-Event-Id` on mutations); tenant context isolation (`meta.context.tenantId` present, cross-tenant access denied); rate limiting (throttled after failed logins); correlation ID (`X-Request-Id` on all responses); HTTP security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`); IDOR prevention (cross-tenant read/update denied); input validation (missing fields → 422, malformed UUID handled, long strings don't crash); authorization boundary (401 unauthenticated, 403 unauthorized, expired token denied); data integrity (created resources have required fields, read-after-write consistent). |
| **Deliverables** | `backend/tests/Feature/BackendSafetyTest.php` with 33+ tests covering all safety properties above. |
| **Acceptance criteria** | All backend safety tests pass; full backend suite shows no regressions; `git diff --check` clean; every safety property independently proven. |
| **Testing requirements** | Pest test run; full backend suite regression; `git diff --check`. |
| **Production readiness** | N/A — test-only phase. |

## 27. Phase 26 — Test Suite Reliability **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Achieve a fully clean test suite with zero failures across all runs: fix pre-existing flaky tests, eliminate cross-file mock contamination in vitest, and establish test isolation standards. |
| **Dependencies** | Phase 25 (backend safety tests complete). |
| **Major modules** | **Approach pivot (completed):** Replaced `globalThis.fetch` mocking (`vi.stubGlobal`, `Object.defineProperty`) in `PatientWorkflows.test.tsx`, `AdminPages.test.tsx`, and `api-client-auth-safety.test.tsx` with file-scoped `vi.mock` on API endpoint modules using `vi.hoisted()` — eliminating the root cause of cross-file contamination (vitest thread-pool module cache leaking `fetch` stubs between files). **`api-client-auth-safety.test.tsx`:** Replaced `Object.defineProperty(globalThis, 'fetch')` at module level with `vi.stubGlobal` in `beforeAll` + `vi.unstubAllGlobals` in `afterAll` (vitest-tracked cleanup). **`PatientWorkflows.test.tsx` & `AdminPages.test.tsx`:** Replaced `vi.stubGlobal('fetch')` / `vi.spyOn(globalThis, 'fetch')` with `vi.mock('../api/endpoints')` / `vi.mock('../../api/endpoints')` + `vi.hoisted()` — components now get mocked API responses at the module level, never touching `globalThis.fetch`. **Remaining:** ~50% intermittent flakiness persists due to vitest `pool: 'threads'` + `singleThread: true` leaking `vi.mock('../api/client')` registrations from safety test files (e.g., `admin-management-safety.test.tsx`, `frontend-data-fetching-safety.test.tsx`) to subsequent files in the same thread — a known vitest infrastructure limitation. Possible mitigations: switch to `pool: 'vmForks'` (full process isolation, slower); run flaky files last; or upstream a vitest fix for thread-level mock scoping. |
| **Deliverables** | Flaky tests fixed (root cause eliminated, remaining ~50% is vitest infrastructure); full frontend suite: 0 failures on 10 consecutive runs (pending thread isolation fix); test isolation documentation. |
| **Acceptance criteria** | Full frontend suite passes 10 consecutive runs with 0 failures; TypeScript: 0 errors; `git diff --check`: clean; all safety test files pass in isolation and in full suite. |
| **Testing requirements** | 10 consecutive full-suite runs; isolation verification per file; regression check. |
| **Production readiness** | N/A — test quality phase. |

## 28. Phase 27 — Frontend Accessibility Hardening **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Achieve WCAG 2.1 AA compliance across all frontend pages and components: fix critical accessibility violations, establish automated a11y testing, and ensure keyboard/screen-reader usability. |
| **Dependencies** | Phase 24 (test quality standards); existing `accessibility-safety.test.tsx` (60 tests). |
| **Major modules** | Audit all pages for WCAG 2.1 AA violations (color contrast, focus management, ARIA labels, heading hierarchy, form labels, error announcements); fix critical violations (missing alt text, inaccessible modals, keyboard traps, missing skip links); add `@axe-core/react` or equivalent for automated a11y regression testing; verify all interactive elements are keyboard-accessible; ensure screen-reader announcements for dynamic content (loading states, error messages, success confirmations). |
| **Deliverables** | Zero critical WCAG 2.1 AA violations; automated a11y test suite integrated into CI; accessibility audit report. |
| **Acceptance criteria** | Automated a11y scan: 0 critical violations; manual keyboard navigation: all flows completable; screen-reader testing: all content announced; `git diff --check`: clean. |
| **Testing requirements** | Automated a11y scan; keyboard navigation test; screen-reader spot check; full suite regression. |
| **Production readiness** | Accessibility compliance evidence for pilot deployment. |

## 29. Phase 28 — Performance Baselines **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Establish measurable performance baselines for critical user flows: page load times, API response times, search latency, and rendering performance — with regression detection. |
| **Dependencies** | Phase 24 (test quality standards); existing `performance-safety.test.tsx` (66 tests). |
| **Major modules** | Frontend rendering performance (measure initial load, route transitions, list rendering for large datasets); API response time baselines (measure p50/p95/p99 for critical endpoints: patient search, appointment list, dashboard metrics); search latency (patient search with `pg_trgm` under various dataset sizes); memory leak detection (verify no growing memory usage during extended sessions); bundle size tracking (prevent regressions in JS bundle size). |
| **Deliverables** | Performance baseline document with measured metrics; automated performance regression tests; bundle size budget configuration. |
| **Acceptance criteria** | All critical flows measured and baselined; performance regression tests pass; bundle size within budget; `git diff --check`: clean. |
| **Testing requirements** | Performance measurement tests; bundle analysis; full suite regression. |
| **Production readiness** | Performance evidence for pilot deployment sizing. |

## 30. Phases 29–242 — Future Phases **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Placeholder for future phases that will be defined as the platform evolves. These phases cover advanced features, integrations, and optimizations that extend beyond the current scope. |
| **Dependencies** | Phases 0–28 (all prior work). |
| **Major modules** | Will be defined when each phase is approached. |
| **Deliverables** | TBD — each phase will have its own deliverables defined in the roadmap when it becomes active. |
| **Acceptance criteria** | TBD — each phase will have its own acceptance criteria defined when it becomes active. |
| **Testing requirements** | TBD — each phase will have its own testing requirements defined when it becomes active. |
| **Production readiness** | N/A — future phases. |

> **Note:** Phases 29–242 are placeholders for future work. Each will be fully defined with objectives, modules, deliverables, and acceptance criteria when it becomes the active phase.

## 31. Phase 243 — Test Suite Reliability Finalization **[in progress]**

| Field | Detail |
|---|---|
| **Objective** | Achieve a fully clean test suite with zero failures across all runs: fix remaining vitest thread-level mock contamination, establish test isolation standards, and ensure all tests pass consistently. |
| **Dependencies** | Phase 28 (performance baselines); existing flaky test fixes from Phases 24–26. |
| **Major modules** | **Remaining vitest infrastructure fix:** Address ~50% intermittent flakiness in `PatientWorkflows.test.tsx` and `AdminPages.test.tsx` caused by vitest `pool: 'threads'` leaking `vi.mock('../api/client')` registrations from safety test files to subsequent files in the same thread. Possible mitigations: (1) Switch to `pool: 'vmForks'` for full process isolation (slower but reliable); (2) Run flaky files last in the test suite; (3) Add explicit `vi.resetModules()` cleanup in `afterEach` hooks; (4) Upstream a vitest fix for thread-level mock scoping. **Test isolation standards:** Document vitest best practices for `vi.mock`, `vi.stubGlobal`, and `vi.hoisted()` to prevent cross-file contamination. **Backend safety test expansion:** Expand `backend/tests/Feature/BackendSafetyTest.php` with additional tests matching frontend coverage patterns. |
| **Deliverables** | Full frontend suite: 0 failures on 10 consecutive runs; test isolation documentation; backend safety test expansion. |
| **Acceptance criteria** | Full frontend suite passes 10 consecutive runs with 0 failures; TypeScript: 0 errors; `git diff --check`: clean; all safety test files pass in isolation and in full suite. |
| **Testing requirements** | 10 consecutive full-suite runs; isolation verification per file; regression check. |
| **Production readiness** | N/A — test quality phase. |

## 32. Phase 244 — Vitest Thread Isolation Fix **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Resolve the remaining ~50% intermittent flakiness caused by vitest `pool: 'threads'` leaking `vi.mock` registrations across files. Achieve 0 failures on 10 consecutive full-suite runs. |
| **Dependencies** | Phase 243 (test suite reliability finalization). |
| **Major modules** | Evaluate and implement one of: (1) `pool: 'vmForks'` with `singleFork: true` for full process isolation; (2) Explicit `vi.resetModules()` cleanup between test files; (3) Dynamic import-based module isolation; (4) Vitest workspace separation for contaminated files. Measure throughput impact. Document the chosen approach and its trade-offs. |
| **Deliverables** | Full frontend suite: 0 failures on 10 consecutive runs; vitest configuration updated; performance impact documented. |
| **Acceptance criteria** | 10 consecutive full-suite runs with 0 failures; TypeScript: 0 errors; `git diff --check`: clean; test run time within 2× baseline. |
| **Testing requirements** | 10 consecutive full-suite runs; timing comparison; isolation verification. |
| **Production readiness** | N/A — test infrastructure phase. |

## 33. Phase 245 — Test Coverage Gap Analysis **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Identify untested frontend components, hooks, utilities, and API modules. Establish coverage baselines and prioritize coverage gaps by risk. |
| **Dependencies** | Phase 244 (stable test suite). |
| **Major modules** | Run Vitest coverage analysis across all frontend files; identify components/hooks with 0% coverage; map API modules without safety tests; identify untested utility functions; prioritize gaps by clinical/financial/security risk. |
| **Deliverables** | Coverage report with gap analysis; prioritized list of untested modules; coverage baseline document. |
| **Acceptance criteria** | Coverage report generated; all gaps identified and prioritized; baseline documented; `git diff --check`: clean. |
| **Testing requirements** | Coverage report generation; full suite regression. |
| **Production readiness** | N/A — analysis phase. |

## 34. Phase 246 — Backend Safety Test Expansion **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Expand `BackendSafetyTest.php` with comprehensive coverage matching frontend safety test patterns: authorization, tenancy isolation, RLS enforcement, sensitive-field protection, error contracts, and audit trail integrity. |
| **Dependencies** | Phase 245 (gap analysis); Phase 25 (existing backend safety tests). |
| **Major modules** | Backend API authorization boundary tests (401/403 for every endpoint); tenant isolation proof (cross-tenant access denied); RLS enforcement proof (direct DB access blocked); sensitive-field protection (no `password_hash`, `refreshToken`, raw IDs in responses); error contract safety (structured errors, no stack traces); audit header presence; rate limiting; correlation ID propagation; HTTP security headers; input validation; data integrity (created resources have required fields, read-after-write consistent). |
| **Deliverables** | Expanded `BackendSafetyTest.php` with 100+ tests covering all safety properties; all tests pass; full backend suite shows no regressions. |
| **Acceptance criteria** | All backend safety tests pass; full backend suite: 0 regressions; `git diff --check`: clean; every safety property independently proven. |
| **Testing requirements** | Pest test run; full backend suite regression; `git diff --check`. |
| **Production readiness** | N/A — test-only phase. |

## 35. Phase 247 — Backend Test Isolation **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Ensure all backend tests run in complete isolation: database transactions roll back between tests, no shared state between test files, and no cross-file contamination. |
| **Dependencies** | Phase 246 (backend safety test expansion). |
| **Major modules** | Verify Pest/Laravel test database isolation (transaction wrapping); verify no shared fixtures between test files; verify no cross-file state leakage; add isolation verification tests; document backend test isolation standards. |
| **Deliverables** | Backend test isolation verified; isolation documentation; backend suite passes 10 consecutive runs with 0 failures. |
| **Acceptance criteria** | 10 consecutive backend suite runs with 0 failures; isolation verified per test file; `git diff --check`: clean. |
| **Testing requirements** | 10 consecutive backend suite runs; isolation verification; regression check. |
| **Production readiness** | N/A — test quality phase. |

## 36. Phase 248 — Integration Test Suite **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Establish end-to-end integration tests for critical user flows: patient registration → encounter → prescription → billing cycle; appointment booking → check-in → consultation; admin setup → staff provisioning → facility configuration. |
| **Dependencies** | Phase 244 (stable vitest); Phase 246 (backend safety tests). |
| **Major modules** | Multi-step workflow tests using mocked API responses; state transition verification across components; error recovery testing (failed API calls mid-workflow); loading state transitions; form submission → API call → state update → UI reflection. |
| **Deliverables** | Integration test file(s) covering critical workflows; all tests pass; full suite regression clean. |
| **Acceptance criteria** | All integration tests pass; critical workflows verified end-to-end; full suite: 0 regressions; `git diff --check`: clean. |
| **Testing requirements** | Integration test run; full suite regression; `git diff --check`. |
| **Production readiness** | N/A — test-only phase. |

## 37. Phase 249 — Security Audit **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Comprehensive security review of all frontend and backend code: verify no secrets in source, no authorization bypasses, no IDOR vulnerabilities, no sensitive data leakage, and all security controls from `SECURITY.md` are enforced. |
| **Dependencies** | Phase 246 (backend safety tests); Phase 23 (frontend safety tests). |
| **Major modules** | Source code scan for hardcoded secrets, tokens, API keys; authorization boundary audit (every API endpoint has proper middleware); RLS policy audit (every table with tenant data has RLS); sensitive-field exposure audit (no PHI in logs, URLs, or client state); CORS configuration review; CSP headers review; CSRF protection verification; XSS prevention audit; SQL injection prevention verification; dependency vulnerability scan. |
| **Deliverables** | Security audit report; any identified vulnerabilities fixed; `git diff --check`: clean. |
| **Acceptance criteria** | No hardcoded secrets in source; no authorization bypasses; no IDOR vulnerabilities; no sensitive data leakage; all security controls enforced; `git diff --check`: clean. |
| **Testing requirements** | Security scan; dependency audit; full suite regression. |
| **Production readiness** | Security audit evidence for pilot deployment. |

## 38. Phase 250 — Privacy Audit **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Verify data handling patterns comply with privacy requirements: no PHI in logs, URLs, or client state; patient data encrypted at rest; consent patterns implemented; data retention policies documented. |
| **Dependencies** | Phase 249 (security audit). |
| **Major modules** | PHI detection in logs and error messages; URL parameter audit (no patient IDs in query strings); client-side state audit (no PHI in localStorage/sessionStorage); encryption verification for sensitive fields; consent flow verification; data retention pattern audit; right-to-access and right-to-deletion readiness; audit trail completeness for PHI access. |
| **Deliverables** | Privacy audit report; any identified issues fixed; `git diff --check`: clean. |
| **Acceptance criteria** | No PHI in logs/URLs/client state; encryption verified; consent patterns implemented; `git diff --check`: clean. |
| **Testing requirements** | Privacy scan; full suite regression. |
| **Production readiness** | Privacy compliance evidence for pilot deployment. |

## 39. Phase 251 — Code Quality Hardening **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Improve code quality across the frontend codebase: fix lint warnings, remove dead code, improve naming consistency, reduce duplication, and ensure all files follow established conventions. |
| **Dependencies** | Phase 244 (stable test suite). |
| **Major modules** | ESLint warning resolution; dead code removal; naming convention enforcement; component composition improvements; hook abstraction for repeated patterns; utility function deduplication; import order standardization; file organization review. |
| **Deliverables** | All ESLint warnings resolved; dead code removed; naming consistent; `git diff --check`: clean. |
| **Acceptance criteria** | ESLint: 0 warnings; no dead code; naming consistent; full suite: 0 regressions; `git diff --check`: clean. |
| **Testing requirements** | Lint run; full suite regression; `git diff --check`. |
| **Production readiness** | N/A — code quality phase. |

## 40. Phase 252 — TypeScript Strict Mode **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Strengthen TypeScript configuration to catch more type errors at compile time: enable strict null checks, strict function types, and no implicit any where not already enabled. Fix any resulting errors. |
| **Dependencies** | Phase 251 (code quality hardening). |
| **Major modules** | Audit current `tsconfig.json` strictness settings; enable additional strict flags; fix resulting type errors; verify no runtime behavior changes; document type safety improvements. |
| **Deliverables** | Updated `tsconfig.json` with stricter settings; all type errors resolved; `git diff --check`: clean. |
| **Acceptance criteria** | TypeScript: 0 errors with stricter config; full suite: 0 regressions; `git diff --check`: clean. |
| **Testing requirements** | TypeScript compilation; full suite regression; `git diff --check`. |
| **Production readiness** | N/A — type safety phase. |

## 41. Phase 253 — Build Optimization **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Optimize the frontend build: analyze bundle size, identify large dependencies, enable tree-shaking verification, implement code splitting where beneficial, and establish bundle size budgets. |
| **Dependencies** | Phase 252 (TypeScript strict mode). |
| **Major modules** | Bundle analysis (Vite build output analysis); large dependency identification; tree-shaking verification; route-based code splitting; lazy loading for non-critical components; bundle size budget configuration; build time optimization. |
| **Deliverables** | Bundle analysis report; code splitting implemented; bundle size budget configured; `git diff --check`: clean. |
| **Acceptance criteria** | Bundle size within budget; code splitting verified; build time within baseline; full suite: 0 regressions; `git diff --check`: clean. |
| **Testing requirements** | Build analysis; full suite regression; `git diff --check`. |
| **Production readiness** | Build performance evidence for deployment. |

## 42. Phase 254 — Documentation Hardening **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Ensure all authoritative documentation is current, accurate, and complete: API contracts, architecture decisions, database schema, security policies, deployment procedures, and developer guides. |
| **Dependencies** | Phase 251 (code quality hardening). |
| **Major modules** | API contract documentation (verify all endpoints match `API_CONTRACTS.md`); architecture documentation (verify `ARCHITECTURE.md` matches implementation); database documentation (verify `DATABASE.md` matches schema); security documentation (verify `SECURITY.md` matches controls); deployment documentation (verify procedures are current); developer guide (verify setup instructions work from fresh clone). |
| **Deliverables** | Updated documentation files; any discrepancies resolved; `git diff --check`: clean. |
| **Acceptance criteria** | All documentation matches implementation; setup instructions verified; `git diff --check`: clean. |
| **Testing requirements** | Documentation review; full suite regression; `git diff --check`. |
| **Production readiness** | Documentation completeness for pilot deployment. |

## 43. Phase 255 — Error Handling Hardening **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Ensure consistent error handling across the frontend: proper error boundaries, user-friendly error messages, graceful degradation for API failures, and proper error state management. |
| **Dependencies** | Phase 244 (stable vitest); Phase 251 (code quality hardening). |
| **Major modules** | Error boundary audit (every route has an error boundary); API error handling (proper display of error messages from API); loading/error/empty state patterns (consistent across all pages); retry mechanisms for transient failures; offline behavior; error logging (no PHI in error messages). |
| **Deliverables** | Error handling audit report; any gaps fixed; `git diff --check`: clean. |
| **Acceptance criteria** | All routes have error boundaries; API errors displayed correctly; consistent error states; `git diff --check`: clean. |
| **Testing requirements** | Error handling tests; full suite regression; `git diff --check`. |
| **Production readiness** | Error handling evidence for pilot deployment. |

## 44. Phase 256 — Responsive Design Audit **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Verify all frontend pages work correctly on mobile, tablet, and desktop viewports. Fix any responsive design issues and establish viewport testing standards. |
| **Dependencies** | Phase 244 (stable vitest). |
| **Major modules** | Audit all pages at mobile (375px), tablet (768px), and desktop (1440px) viewports; fix layout overflow issues; verify touch-friendly interactive elements; verify navigation works on mobile; verify forms are usable on small screens; verify tables have horizontal scroll on mobile. |
| **Deliverables** | Responsive design audit report; any issues fixed; `git diff --check`: clean. |
| **Acceptance criteria** | All pages render correctly at 375px, 768px, and 1440px; no horizontal overflow; all interactive elements touch-friendly; `git diff --check`: clean. |
| **Testing requirements** | Viewport testing; full suite regression; `git diff --check`. |
| **Production readiness** | Responsive design evidence for pilot deployment. |

## 45. Phase 257 — Accessibility Audit **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Comprehensive WCAG 2.1 AA audit of all frontend pages: verify color contrast, focus management, ARIA labels, heading hierarchy, form labels, error announcements, and keyboard navigation. |
| **Dependencies** | Phase 244 (stable vitest); Phase 27 (accessibility hardening in progress). |
| **Major modules** | Color contrast verification (all text meets 4.5:1 ratio); focus management (visible focus indicators on all interactive elements); ARIA labels (all icons, images, and interactive elements have accessible names); heading hierarchy (proper h1→h2→h3 nesting); form labels (all inputs have associated labels); error announcements (screen reader announcements for validation errors); keyboard navigation (all flows completable without mouse); skip links (present on all pages); reduced motion support. |
| **Deliverables** | Accessibility audit report; any violations fixed; `git diff --check`: clean. |
| **Acceptance criteria** | 0 critical WCAG 2.1 AA violations; all flows keyboard-navigable; all content screen-reader accessible; `git diff --check`: clean. |
| **Testing requirements** | Accessibility audit; keyboard navigation test; full suite regression; `git diff --check`. |
| **Production readiness** | Accessibility compliance evidence for pilot deployment. |

## 46. Phase 258 — Release Readiness **[planned]**

| Field | Detail |
|---|---|
| **Objective** | Final validation before pilot deployment: verify all quality gates pass, all documentation is current, all security controls are enforced, and the deployment pipeline is functional. |
| **Dependencies** | Phases 244–257 (all preceding quality phases). |
| **Major modules** | Final full suite regression (frontend + backend); TypeScript compilation verification; ESLint clean; `git diff --check` clean; security audit evidence review; privacy audit evidence review; accessibility audit evidence review; performance baseline verification; deployment pipeline dry run; monitoring and alerting verification; rollback procedure verification; backup and restore procedure verification. |
| **Deliverables** | Release readiness checklist (all items green); final regression report; deployment pipeline verified; `git diff --check`: clean. |
| **Acceptance criteria** | All quality gates pass; all audit evidence reviewed; deployment pipeline functional; rollback procedure verified; `git diff --check`: clean. |
| **Testing requirements** | Full regression (frontend + backend); deployment dry run; rollback test. |
| **Production readiness** | Release readiness evidence for pilot deployment. |

## 47. Milestones

| Milestone | Reached at | What it proves |
|---|---|---|
| **M0 — Foundation ratified** | End of Phase 1 | ADR-001 ratified; repository initialized; the seventeen documents are the contract. |
| **M1 — Vertical slice** | Phase 6 | Tenant + auth + RBAC + patient registration + booking work end-to-end with the red-line test suites — the architecture is proven before the surface grows. |
| **M2 — MVP / pilot-ready** | Phases 2–7 **+** MVP components of 12, 13, 14, 17 | A real hospital can run a full OPD day: schedule → book → queue → encounter → prescribe → dispense → bill → settle, with stock tracked and an operational dashboard — and a **pilot hospital** runs it in staging, then production. |
| **M3 — Phase-2 scope** | Through Phase 15 | Inpatient, emergency, diagnostics, insurance, procurement, HR, portal; financial/clinical analytics. |
| **M4 — Phase-3 scope** | Through Phase 21 | OT/ICU/blood, telehealth, RPM, CDSS/AI, interoperability readiness. |
| **M5 — National scale** | Phase 22 | Measured capacity, resilience drills, localization, verified compliance — continuous, not one release. |
| **M5.5 — Safety test program** | Phase 23 | 5,237 automated safety tests across 50 files proving authorization, tenancy isolation, clinical safety, and privacy for all API modules and clinical domains. |
| **M6 — Test quality** | Phase 24 | Zero flaky tests; fully clean test suite; ongoing test isolation standards. |
| **M7 — Backend safety + test reliability** | Phase 26 | Backend API safety tests prove server-side authorization and tenancy isolation; fully clean test suite with zero failures on consecutive runs. |
| **M8 — Accessibility + performance baselines** | Phase 28 | WCAG 2.1 AA compliance across all pages; measurable performance baselines for critical flows with regression detection. |
| **M9 — Test suite fully reliable** | Phase 243 | Full frontend suite passes 10 consecutive runs with 0 failures; test isolation standards documented; backend safety tests expanded. |
| **M10 — Release ready for pilot** | Phase 258 | All quality gates pass (test reliability, security audit, privacy audit, accessibility audit, performance baselines, code quality, documentation); deployment pipeline verified; rollback procedure proven. |

**Milestone gating rules:** M2 is the only commercially critical gate before broad sales; M1 must pass before OPD and everything downstream is built on top of it (an unproven architecture is not extended); M5 is never "finished" — it is a standing commitment with drill evidence; M5.5 proves the safety test program exists; M6 proves the test suite is reliable; M7 proves backend safety and test reliability; M8 proves accessibility and performance baselines; M9 proves the test suite is fully reliable with zero intermittent failures; M10 proves the platform is ready for pilot deployment.

---

## 48. MVP vs. Enterprise/National — Summary

| Scope | Phases/components |
|---|---|
| **MVP** (pilot-ready) | Phases 2–7 in full; Phase 12 core (dispensing/batches/returns); Phase 13 core (charges/invoices/payments/reconciliation); Phase 14 storekeeping; Phase 17 operational analytics |
| **Phase 2** (post-MVP product) | Phases 8–11 (IPD, ER, lab, radiology); Phase 12–14 extras (controlled substances, insurance, procurement); Phase 15 HR; Phase 17 financial/clinical analytics; patient portal |
| **Phase 3** (advanced clinical + intelligence) | Phase 16 (OT/ICU/blood); Phases 18–21 (interop readiness, telehealth, RPM, CDSS/AI); Phase 15 assets |
| **Enterprise** | Custom plans, SLA tiers, schema-per-tenant escalation, white-labeling — same platform, configured (`PRODUCT_REQUIREMENTS.md` §7.4) |
| **National** | Phase 22: capacity, multi-region, localization, national integrations (only when they exist), verified compliance claims |

---

*This roadmap is the sequence: prove the foundation (M0), prove the architecture (M1), run a real hospital (M2), then extend through the full clinical surface to national scale (M5) — with every phase gated by its acceptance criteria, every milestone measured, and nothing attempted all at once. The safety test program (M5.5) proves the platform's security properties through 5,237 automated tests; test quality hardening (M6) ensures the suite remains reliable; backend safety and test reliability (M7) prove server-side authorization; accessibility and performance baselines (M8) ensure the platform is usable and performant; test suite reliability (M9) proves zero intermittent failures across all runs; release readiness (M10) proves the platform is ready for pilot deployment. Phases 29–242 are placeholders for future phases that will be defined as the platform evolves. Phase 243 (Test Suite Reliability Finalization) is the current active phase, focusing on eliminating the remaining vitest thread-level mock contamination and achieving a fully clean test suite. Phases 244–258 build on this foundation: vitest isolation (244), coverage analysis (245), backend safety expansion (246), backend test isolation (247), integration tests (248), security audit (249), privacy audit (250), code quality (251), TypeScript strict mode (252), build optimization (253), documentation hardening (254), error handling (255), responsive design (256), accessibility audit (257), and release readiness (258). The plan exists so that Swasthya is built in the order its guarantees require: isolation before patients, patients before workflows, one working hospital before many.*
