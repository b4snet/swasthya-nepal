# ROADMAP.md — Swasthya Staged Roadmap

> **Status:** Working baseline · **Owner:** Product (ratified with the Principal Architect)
> **Version:** 1.0
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

---

## 24. Milestones

| Milestone | Reached at | What it proves |
|---|---|---|
| **M0 — Foundation ratified** | End of Phase 1 | ADR-001 ratified; repository initialized; the seventeen documents are the contract. |
| **M1 — Vertical slice** | Phase 6 | Tenant + auth + RBAC + patient registration + booking work end-to-end with the red-line test suites — the architecture is proven before the surface grows. |
| **M2 — MVP / pilot-ready** | Phases 2–7 **+** MVP components of 12, 13, 14, 17 | A real hospital can run a full OPD day: schedule → book → queue → encounter → prescribe → dispense → bill → settle, with stock tracked and an operational dashboard — and a **pilot hospital** runs it in staging, then production. |
| **M3 — Phase-2 scope** | Through Phase 15 | Inpatient, emergency, diagnostics, insurance, procurement, HR, portal; financial/clinical analytics. |
| **M4 — Phase-3 scope** | Through Phase 21 | OT/ICU/blood, telehealth, RPM, CDSS/AI, interoperability readiness. |
| **M5 — National scale** | Phase 22 | Measured capacity, resilience drills, localization, verified compliance — continuous, not one release. |

**Milestone gating rules:** M2 is the only commercially critical gate before broad sales; M1 must pass before OPD and everything downstream is built on top of it (an unproven architecture is not extended); M5 is never "finished" — it is a standing commitment with drill evidence.

---

## 25. MVP vs. Enterprise/National — Summary

| Scope | Phases/components |
|---|---|
| **MVP** (pilot-ready) | Phases 2–7 in full; Phase 12 core (dispensing/batches/returns); Phase 13 core (charges/invoices/payments/reconciliation); Phase 14 storekeeping; Phase 17 operational analytics |
| **Phase 2** (post-MVP product) | Phases 8–11 (IPD, ER, lab, radiology); Phase 12–14 extras (controlled substances, insurance, procurement); Phase 15 HR; Phase 17 financial/clinical analytics; patient portal |
| **Phase 3** (advanced clinical + intelligence) | Phase 16 (OT/ICU/blood); Phases 18–21 (interop readiness, telehealth, RPM, CDSS/AI); Phase 15 assets |
| **Enterprise** | Custom plans, SLA tiers, schema-per-tenant escalation, white-labeling — same platform, configured (`PRODUCT_REQUIREMENTS.md` §7.4) |
| **National** | Phase 22: capacity, multi-region, localization, national integrations (only when they exist), verified compliance claims |

---

*This roadmap is the sequence: prove the foundation (M0), prove the architecture (M1), run a real hospital (M2), then extend through the full clinical surface to national scale — with every phase gated by its acceptance criteria, every milestone measured, and nothing attempted all at once. The plan exists so that Swasthya is built in the order its guarantees require: isolation before patients, patients before workflows, one working hospital before many.*
