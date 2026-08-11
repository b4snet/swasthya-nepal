# PRODUCT_REQUIREMENTS.md — Swasthya

> **Status:** Working baseline · **Applies to:** product and engineering planning
> **Version:** 1.0 · **Owner:** Product (ratified with the Principal Architect)
> **Relationship:** This document is the product-level companion to `MASTER_RULES.md` (engineering constitution). Where they conflict, `MASTER_RULES.md` governs *how* we build; this document governs *what* we build.

**What Swasthya is:** a production-grade, nationally scalable **Hospital Management System (HMS) SaaS** — management software that hospitals run on. It is not a public hospital website, not a marketing site, not a consumer health app, and not a demo.

**How to read this document:**

- Every module defines: **purpose, users, core workflows, major entities, dependencies, permissions, audit requirements, future integrations, production considerations.**
- Every module is tagged with a **phase**: MVP / Phase 2 / Phase 3 / Enterprise (Section 7 defines each phase and its exit criteria; "National scale" is a cross-cutting operational commitment, not a feature phase).
- Requirements are stated as product outcomes. Implementation choices (frameworks, tenancy mechanics, RLS) belong to the architecture docs and `MASTER_RULES.md`.
- This document does **not** claim regulatory compliance (Section 9 states exactly what is and is not claimed) and does **not** invent business data — no fabricated pricing, volumes, or customers. Where a real-world factor matters (currency, identity systems, payment providers), it is stated as an integration/configuration requirement, not as assumed fact.

---

## 1. Product Vision

Swasthya gives hospitals of any size — from a single polyclinic to a multi-facility hospital group — a single, modern system to run their entire operation: patients, front desk, clinical care, pharmacy, labs, billing, inventory, and reporting. It is sold as a multi-tenant SaaS: many hospitals on one secure platform, each with isolated data, multiple facilities and branches, and the ability to grow from one room to national scale without migrating systems.

**The product's single job:** help a hospital deliver care and get paid — accurately, safely, and with a complete audit trail — while removing the paper, the scattered spreadsheets, and the disconnected systems that dominate small and mid-size hospitals.

**What success looks like, per stakeholder:**

- **Hospital leadership:** know what is happening (census, revenue, receivables, wait times) in real time; run on one system instead of five.
- **Clinical staff:** spend time on patients, not paperwork; see the right information about the right patient, with safety checks (identity, allergies, drug interactions) built in.
- **Front desk and billing:** book, check in, queue, charge, and settle without re-typing data or chasing paper.
- **Patients:** are registered once, identified reliably, kept informed (appointments, results, bills), and able to do simple self-service — without the hospital exposing any data they have not consented to.
- **The SaaS business:** one platform, many tenants, low marginal cost per hospital, metered and billed fairly, expandable to national scale and national integration.

## 2. Product Principles

1. **One platform, one truth.** Every patient, encounter, charge, and stock movement exists once, in one place, shared across modules. No module keeps its own copy of the patient or the price.
2. **The record is the product.** Every clinical and financial fact is recorded, versioned, and auditable. If it is not in the record, it did not happen.
3. **Safety before speed.** Wrong-patient, wrong-dose, wrong-charge risks are designed out of workflows, and automation assists but never overrides a clinician (see CDSS/AI sections).
4. **Mobile-first.** The busiest users (nurses, front desk, doctors on rounds) work on phones and tablets. Every screen is designed mobile-first and works on desktop.
5. **Configurable, not forked.** Hospitals differ by configuration and data, never by custom code (per `MASTER_RULES.md` §1.3).
6. **Assistive AI, audited.** AI writes drafts, summarizes, and forecasts — it never decides clinical or financial outcomes alone, and every AI action is labeled and audited.
7. **Honest integrations.** Every integration is real, tested, and monitored. We ship nothing that merely looks connected.
8. **Compliance is designed, not claimed.** The platform is built to be auditable and consent-aware; specific regulatory claims are made only with documented legal assessment (Section 9).

## 3. Non-Goals (explicitly out of scope)

- A public-facing hospital website or patient-marketing portal (a hospital's public site is separate; the patient portal here is a secure, logged-in service for that hospital's own patients).
- A standalone telemedicine consumer app, a standalone billing system, or a standalone pharmacy POS — these are modules *of* Swasthya, not products themselves.
- Building custom features per hospital (Section 2.5).
- Full payroll *processing* (tax filings, salary disbursement) — HR provides payroll **readiness** (the data payroll engines need, with an export interface).
- Manufacturing-grade DICOM *viewing*: radiology integrates with PACS; Swasthya manages orders, studies, and reports and can embed/launch a viewer — it is not a PACS replacement.
- Claiming certifications or compliance statuses that have not been legally assessed and documented (Section 9).
- Inventing or presenting fabricated operational/financial metrics (analytics reflect observed data only).

## 4. Users and Personas

| Persona | Group | What they need from Swasthya |
|---|---|---|
| **Platform superadmin** | Platform | Operate the SaaS: tenants, entitlements, support, monitoring |
| **Organization admin** | Org | Manage the customer org: facilities, branches, staff, roles, subscription |
| **Facility admin** | Facility | Configure one hospital: departments, schedules, catalogs, billing rules |
| **Branch manager** | Branch | Run a branch: staff, queues, stock, daily settlement |
| **Receptionist / front desk** | Staff | Register patients, book and manage appointments, queues, check-in, collect deposits |
| **Doctor (OPD/IPD/ER)** | Clinical | See patients, document encounters, prescribe, order investigations, admit/discharge |
| **Nurse** | Clinical | Triage, vital signs, medication administration, nursing notes, ward workflows |
| **Pharmacist** | Clinical/Support | Dispense against prescriptions, manage stock, batches, expiry, returns |
| **Lab technician / radiologist** | Clinical | Process orders, specimens, results, verification, reports |
| **Billing clerk / cashier** | Finance | Charge, invoice, collect, allocate payments, refund, reconcile |
| **Accounts / finance officer** | Finance | Outstanding, reconciliations, insurance claims, reports |
| **HR / admin staff** | Organization | Employees, shifts, attendance, leave |
| **Store/inventory clerk** | Support | Items, stores, stock, transfers, adjustments |
| **Patient (portal)** | Patient | View own records, book/cancel appointments, see bills and results (per consent) |

## 5. Platform Foundations

The following capabilities are **platform-wide foundations**: every module assumes they exist and behaves correctly. They are listed here once, not repeated in every module.

### 5.1 SaaS Tenancy, Organizations, Facilities, Branches

- **Purpose:** the tenancy model that isolates every tenant's data and configures the SaaS structure: **Organization (tenant) → Facility (hospital) → Branch (unit/site within a facility)**.
- **Rules (from `MASTER_RULES.md` §4):** tenant isolation is enforced at the database layer (RLS) and re-validated at the application layer on every request; the tenant context is never taken from client input; onboarding and offboarding are designed, tested flows.
- **Entities:** `organization`, `facility`, `branch`, `department`, `tenant_settings`, `subscription`, `entitlement`.
- **Production considerations:** provisioning a new tenant must be safe and repeatable; offboarding purges per retention policy; usage metering is accurate (Section 6.7).

### 5.2 Users, Staff, Authentication

- **Purpose:** every human actor is a `user` (account) and, if employed by the organization, also a `staff` record (with HR and clinical identity). Patients are users only when they use the portal.
- **Authentication (from `MASTER_RULES.md` §7):** token-based; MFA mandatory for staff and admins; four identity classes (patients, clinical staff, org admins, platform admins); every auth event audited.
- **Entities:** `user`, `staff`, `credential`, `mfa`, `session`, `token`, `password_reset`, `auth_event`.
- **Permissions:** identity administration is a platform/org-admin function; staff cannot see each other's credentials.
- **Audit:** login/logout/lockout/token issuance/password change/MFA changes.
- **Production considerations:** staff joining/leaving must revoke access immediately; one person at multiple facilities is supported with per-facility scoping.

### 5.3 Roles and Permissions (RBAC)

- **Purpose:** deterministic, seeded role/permission model per `MASTER_RULES.md` §9: platform / org / facility / branch / clinical-function / patient roles, scoped by facility and branch.
- **Rules:** permission checks live in policies, not scattered conditionals; least privilege; role changes take effect immediately and are audited; the authorization matrix is tested for every role × action.
- **Entities:** `role`, `permission`, `role_assignment` (with scope), `permission_grant`.
- **Permissions:** RBAC administration itself is restricted to org admins and platform superadmin only.
- **Audit:** role and permission changes, including who changed what and when.
- **Production considerations:** a role matrix change affects every tenant — changes are versioned and rolled out like code.

### 5.4 Notifications and Communication

- **Purpose:** one notification service (in-app, email, SMS, push) used by every module for appointment reminders, lab results, billing alerts, stock alerts, and clinical escalations.
- **Core workflows:** template-driven dispatch; per-tenant/per-user preference and opt-in; failure handling (a failed SMS does not silently disappear); delivery audit.
- **Entities:** `notification`, `notification_template`, `notification_preference`, `delivery_attempt`.
- **Dependencies:** identity; settings; audit.
- **Permissions:** module owners trigger domain notifications; users control their own preferences; admins configure templates.
- **Audit:** dispatch of sensitive notifications (results, clinical alerts) is audited; delivery attempts logged.
- **Future integrations:** national/local SMS aggregators, email providers, push providers — each a real, contract-tested integration (§6.31).
- **Production considerations:** reminder volume is high (national scale); never send PHI without consent and transport security; rate limits per provider.

### 5.5 Settings and Configuration

- **Purpose:** tenant-wide and facility-wide configuration: locale(s), timezone, currency and tax, clinical catalogs, document templates, queue rules, feature toggles. All settings are data — never code (per `MASTER_RULES.md`).
- **Entities:** `setting`, `setting_group`, `setting_audit`.
- **Permissions:** configuration is role-gated (facility admin and above); clinical settings additionally gated by clinical authority.
- **Audit:** every settings change is versioned and audited (who, what, when, from where).
- **Production considerations:** settings must be exportable/importable for tenant onboarding and support.

### 5.6 Audit and Compliance Trail

- **Purpose:** the single append-only audit trail defined in `MASTER_RULES.md` §19: who did what, to what, in which tenant/facility, when, and the outcome — tamper-evident and central.
- **Rules:** clinical, financial, auth, consent, data-export, admin, and AI actions are audited; audit data is backed up with the same rigor as clinical data.
- **Entities:** `audit_event` (versioned payloads).
- **Permissions:** read-only to sanctioned audit roles; no in-app editing or purging.
- **Production considerations:** high write volume — design for partitioning; audit availability is part of the platform's RPO/RTO.

### 5.7 Subscriptions and Feature Entitlements

- **Purpose:** the SaaS commercial model: tenants subscribe to a plan; plans grant **feature entitlements** (which modules and capacities a tenant may use); metering measures usage for fair billing.
- **Core workflows:** subscription lifecycle (trial → active → suspended → cancelled); entitlement enforcement (a tenant without the lab entitlement cannot open lab workflows); metering events (users, facilities, encounters, storage).
- **Entities:** `plan`, `subscription`, `entitlement`, `meter_event`, `invoice` (from Finance).
- **Dependencies:** tenancy; finance (billing the tenant); notifications.
- **Permissions:** platform superadmin manages plans and subscriptions; org admin views own subscription.
- **Audit:** plan changes, suspensions, and entitlement changes are audited; metering is accurate, never fabricated (per `MASTER_RULES.md` P.15).
- **Production considerations:** a suspended tenant's data stays isolated and retained per policy; downgrades must not corrupt existing data (cap, don't delete).

---

## 6. Module Catalog

Each module below uses the phase tags **MVP / Phase 2 / Phase 3 / Enterprise**. Phase definitions and the full roadmap are in Section 7.

### 6.1 Patient Registration and Records — **MVP**

- **Purpose:** the master patient record: register a patient once, identify them reliably forever, and give every other module (clinical, billing, pharmacy) the same correct identity. Wrong-patient identification is the platform's most serious safety risk; this module is where it is controlled.
- **Users:** receptionist, registration clerk, doctor (in emergency/OPD registration), patient (portal self-registration, Phase 2).
- **Core workflows:**
  1. New-patient registration (search first to avoid duplicates → capture demographics → issue **MRN**).
  2. Duplicate detection and merge (two records found → controlled merge with audit; merged records retain full history).
  3. Identity verification (photo, documents, optional national ID linkage with consent — e.g., NPRN where applicable).
  4. Contact, emergency contact, and next-of-kin capture; update with versioning.
  5. Insurance coverage attachment (policy → patient) and validity checks.
  6. Document upload (consent forms, referrals, IDs) with access control and expiry review.
  7. Consent capture and versioning (treatment consent, data use, marketing opt-out).
  8. Patient timeline: one chronological view of every encounter, admission, visit, result, bill, and consent change for that patient.
- **Major entities:** `patient`, `mrn`, `demographic`, `identifier`, `contact`, `emergency_contact`, `insurance_coverage`, `patient_document`, `consent`, `patient_timeline_entry`, `duplicate_candidate`, `merge_record`.
- **Dependencies:** tenancy/identity (foundations); notifications (portal events).
- **Permissions (indicative):** `patient:read` (scoped to facility and need-to-know), `patient:create`, `patient:update`, `patient:merge`, `patient:document`, `patient:consent` — access to full records is clinical/front-desk role-gated; the patient portal sees only the patient's own data with consent.
- **Audit requirements:** every create/update/merge, every record **view** by staff, document access, consent changes, and identifier changes. Merges are among the most-audited events in the platform.
- **Future integrations:** national identity/health-number systems (with consent); patient-facing apps; referral networks.
- **Production considerations:** MRN must be unique per tenant/facility, stable for the patient's lifetime, never reused after deletion; registration volume spikes (morning OPD rush) must be handled; duplicate-rate is a monitored operational metric.

### 6.2 Patient Portal (self-service) — **Phase 2** (read-only view: MVP-lite)

- **Purpose:** the patient's secure window into their own hospital record, subject to the hospital's consent and policy: upcoming and past appointments, results, prescriptions, bills, documents; self-booking and payments (Phase 2+).
- **Users:** patients (and guardians with consent).
- **Core workflows:** login (patient class, MFA-optional by tenant policy) → verify identity → view appointments → view results/bills/consents → book/cancel appointment → pay a bill online → download documents → update contact details.
- **Major entities:** `portal_account`, `portal_session`, `patient` (read views), `appointment`, `result`, `invoice` (read-only), `payment_attempt`.
- **Dependencies:** patient records; front desk (availability); finance (payments); notifications.
- **Permissions:** the portal surfaces only the authenticated patient's own data, filtered by consent and by the hospital's visibility policy; portal endpoints are versioned and authorized like staff APIs.
- **Audit requirements:** portal logins, data views, booking/cancellation, and payment actions are audited; a patient can request a record-access report.
- **Future integrations:** national patient identity verification; payment wallets; telehealth entry point (Phase 3).
- **Production considerations:** the portal is a public-facing attack surface — rate limiting, lockout, and consent enforcement are critical; patient accounts must never expose other patients' data through shared devices or referrer leakage.

### 6.3 Front Desk: Appointments, Schedules, Queues, Tokens — **MVP**

- **Purpose:** manage the patient's journey through the facility: finding and booking appointments, running queues and tokens, and handling check-in, cancellation, and rescheduling — the highest-traffic operational surface of the product.
- **Users:** receptionist, front-desk clerk, doctor (own schedule), facility admin (schedules), patient (portal booking, Phase 2).
- **Core workflows:**
  1. **Doctor schedules & availability:** recurring schedule templates (day-of-week, sessions), exceptions (leave, holidays), capacity per slot; availability is the single source of truth for booking.
  2. **Appointment booking:** search patient → select service/doctor → choose available slot → book (with type: OPD, follow-up, procedure, teleconsult) → confirm; supports walk-in conversion.
  3. **Queue & token management:** per-day, per-service, per-doctor queues; token issuance in arrival order with priority rules (emergencies, elderly, prior tokens); display at reception.
  4. **Check-in:** verify patient identity → check in → queue position confirmed.
  5. **Cancellation & rescheduling:** with rules (notice windows, charges per tenant policy); cancelled slots become bookable; notifications to affected patients.
  6. **No-show handling:** mark no-show, record reason, policy per tenant.
- **Major entities:** `schedule_template`, `schedule_exception`, `appointment`, `appointment_type`, `queue`, `queue_entry`, `token`, `check_in`, `no_show`.
- **Dependencies:** patient records; identity; notifications; settings (queues/slots rules); billing (booking charges if applicable).
- **Permissions (indicative):** `appointments:book`, `appointments:cancel`, `appointments:reschedule`, `schedules:manage`, `queue:manage` — scoped to facility/branch; doctors manage their own schedules within facility policy.
- **Audit requirements:** booking/cancel/reschedule/check-in/no-show events, token assignment, and any queue manipulation (priority overrides must be traceable).
- **Future integrations:** patient portal booking (Phase 2); SMS reminders; national appointment/exchange systems (Phase 3+).
- **Production considerations:** peak-hour concurrency (many bookings/check-ins at 8–10 AM) must not cause queue corruption or double-booking; the queue must survive a client refresh (server-authoritative state); schedule data must be timezone-safe.

### 6.4 OPD (Outpatient Department) — **MVP**

- **Purpose:** the core outpatient consultation: capture the encounter, document the clinical record, and produce the outputs that drive pharmacy, labs, radiology, and billing.
- **Users:** doctor, nurse (vitals/assist), patient (portal summary view, Phase 2).
- **Core workflows:**
  1. Start encounter from queue/token (or walk-in/teleconsult).
  2. Vital signs and triage-lite capture (weight, BP, temperature, etc.) by nurse or doctor.
  3. **Clinical documentation:** history, examination, systems review — structured templates plus free text.
  4. **Diagnosis:** coded (configurable coding — e.g., ICD-10 readiness) plus provisional/differential/final statuses.
  5. **Prescription:** medicines with dose, frequency, duration, route; checked against allergies and drug interactions (CDSS, Phase 3) — flags are warnings, never silent.
  6. **Investigations:** lab/radiology order requests with clinical indication.
  7. **Follow-up:** planned return visit or teleconsult, linked to this encounter.
  8. Complete encounter → sign → record is final (amendments are new, audited entries, never silent edits).
- **Major entities:** `encounter` (type OPD), `vital_sign`, `clinical_note`, `diagnosis`, `prescription`, `prescription_line`, `investigation_order`, `follow_up`, `encounter_amendment`.
- **Dependencies:** patient records; front desk (queue); pharmacy (prescriptions); lab/radiology (orders); finance (charges); CDSS (Phase 3).
- **Permissions (indicative):** `encounter:create`, `encounter:read` (scoped to patient + facility), `encounter:sign`, `encounter:amend`, `prescription:write`, `diagnosis:write` — clinical roles only.
- **Audit requirements:** encounter creation/read/sign/amendment, prescription changes, diagnosis changes, and every view of a signed record. Amendments preserve the original.
- **Future integrations:** ICD coding servers; e-prescription exchange (Phase 3/national); CDSS and AI documentation assistance (Phase 3).
- **Production considerations:** the record must be complete before the patient leaves (the prescription and bill depend on it); concurrent edits (nurse vitals + doctor note) must not lose data; typing latency on tablets in real clinics is a UX and performance requirement.

### 6.5 IPD (Inpatient Department) — **Phase 2**

- **Purpose:** manage the admitted patient end-to-end: admission, ward/room/bed assignment, transfers, nursing care, and discharge with a structured discharge summary.
- **Users:** admitting doctor, ward nurse, charge nurse, facility admin (ward/bed config), billing (discharge settlement).
- **Core workflows:**
  1. **Admission:** from OPD/ER (with reason and admitting diagnosis) or direct; assign ward/room/bed from live availability; generate admission record.
  2. **Ward & bed management:** wards → rooms → beds; bed states (available, occupied, reserved, cleaning, out of service); live occupancy view.
  3. **Inpatient care:** daily nursing notes, medication administration record (MAR) from prescriptions, vital observations, doctor visits, procedure notes.
  4. **Transfers:** bed-to-bed, ward-to-ward, doctor-approved; historical bed timeline preserved.
  5. **Discharge:** discharge decision → discharge instructions → **structured discharge summary** (diagnoses, procedures, medications, follow-up) → bed released → settlement of outstanding charges.
  6. **Readmission handling:** link to prior admissions; full history visible.
- **Major entities:** `admission`, `ward`, `room`, `bed`, `bed_assignment`, `admission_diagnosis`, `mar_entry`, `nursing_note`, `transfer_event`, `discharge`, `discharge_summary`.
- **Dependencies:** patient records; OPD/ER (admission source); pharmacy (MAR); lab/radiology (inpatient orders); finance (daily charges, settlement); notifications (admission/discharge events).
- **Permissions (indicative):** `admission:create`, `admission:discharge`, `beds:manage`, `nursing:document`, `mar:administer` — scoped to ward/facility; discharge requires clinical authority.
- **Audit requirements:** admission, bed assignment and every transfer (who moved the patient where and why), MAR administrations, discharge and discharge-summary reads (patients and payers access it), and any override of bed state.
- **Future integrations:** bed-occupancy feeds to analytics; nurse-call systems; telehealth rounds (Phase 3).
- **Production considerations:** wrong-bed/wrong-patient errors are a known safety risk — bed transfer requires explicit identity confirmation; occupancy is a live, multi-user surface (two clerks must not book the same bed); discharge summary must be complete before settlement.

### 6.6 Emergency Department (ER) — **Phase 2**

- **Purpose:** the ER is where speed and safety collide: rapid registration, triage, treatment, and disposition — with priority handling and complete documentation.
- **Users:** ER receptionist, triage nurse, ER doctor, on-call specialties, billing (ER settlement).
- **Core workflows:**
  1. **Emergency registration:** minimal-data registration (name, age/sex, presenting complaint) that is completed later — speed over completeness, with a full record created.
  2. **Triage:** structured triage using a configurable acuity scale (e.g., 5-level); triage category drives queue priority and reassessment intervals; triage time and category are audited.
  3. **Emergency encounter:** parallel documentation — assessment, vitals, orders, treatment while the patient is in the department; time-stamped events.
  4. **Treatment & observation:** medications, procedures, investigations, observation-bay stays with reassessment.
  5. **Disposition:** admit to IPD, transfer to another facility (with transfer documentation), discharge with instructions, or observe-and-discharge.
- **Major entities:** `er_registration`, `triage`, `triage_scale`, `er_encounter`, `er_event` (time-stamped), `disposition`.
- **Dependencies:** patient records; triage config; OPD (shared encounter model); IPD (admission from ER); lab/radiology (stat orders); pharmacy (stat meds); finance.
- **Permissions (indicative):** `er:register`, `triage:assign`, `er:document`, `er:disposition` — clinical roles; triage override requires clinical authority and is audited.
- **Audit requirements:** triage time/category and reassessments, every ER event timestamp, medication administrations, disposition, and transfers out. ER events are among the most time-critical audits (medico-legal).
- **Future integrations:** ambulance/national emergency coordination (Phase 3/national); ER dashboard feeds.
- **Production considerations:** the ER must keep working under peak load and partial information (incomplete demographics); triage wait times are a monitored operational metric; identity of an unidentified patient is resolved later with a controlled link.

### 6.7 Pharmacy — **MVP**

- **Purpose:** safe dispensing against prescriptions and full control of medicine stock: catalog, batches, expiry, returns — so the right medicine, from a valid batch, reaches the right patient, and stock is never a guessing game.
- **Users:** pharmacist, pharmacy technician, doctor (prescribing view), facility admin (catalog, pricing).
- **Core workflows:**
  1. **Medicine catalog:** formulary with generic/brand names, strengths, forms, units, and default dispensing rules.
  2. **Prescription fulfillment:** receive prescription (OPD/IPD/ER) → check allergy and interaction warnings (CDSS, Phase 3) → prepare → **verify by a second pharmacist where policy requires** → dispense with batch selection → record.
  3. **Stock management:** stock-in (purchase receipt, transfer), stock-out (dispensing, internal use, wastage), **batch and expiry tracking** (FEFO), low-stock and expiring-stock alerts.
  4. **Returns & reversals:** patient returns (reason captured, stock restored to batch, refund path), dispensing reversal with reason and audit.
  5. **Stock adjustments:** cycle counts and corrections with approval workflow (never silent edits).
- **Major entities:** `medicine`, `formulary`, `stock_item`, `stock_batch`, `stock_movement`, `dispensing`, `prescription`, `return_event`, `stock_adjustment`, `stock_alert`.
- **Dependencies:** OPD/IPD/ER (prescriptions); inventory (stock integration); finance (charges for dispensed items); settings (alerts); CDSS (Phase 3).
- **Permissions (indicative):** `pharmacy:dispense`, `pharmacy:verify`, `pharmacy:return`, `stock:adjust` (approval-gated), `formulary:manage` — clinical/support roles, scoped to branch.
- **Audit requirements:** every dispensing (who, what batch, against which prescription, verification), returns/reversals, stock adjustments with approval trail, and expiry handling. Medication errors and their corrections must be fully reconstructable.
- **Future integrations:** wholesaler ordering (procurement), national e-prescription exchange, barcode scanning, automated dispensing cabinets (Phase 3).
- **Production considerations:** batch/expiry accuracy is a safety issue — a wrong batch can be a wrong product; stock movements must be transactional (a dispensing failure must not lose stock or charge twice); expiring-stock handling must be visible to staff without data fabrication.

### 6.8 Laboratory — **Phase 2**

- **Purpose:** manage the complete lab workflow from order to verified report, with critical-value alerting and results that flow back to the clinical record.
- **Users:** ordering clinician, lab receptionist, phlebotomist, lab technician, lab supervisor (verification), facility admin (catalog, reference ranges).
- **Core workflows:**
  1. **Test catalog:** tests and panels with sample types, methods, reference ranges (age/sex/clinical-context aware), and turnaround targets.
  2. **Order & specimen:** clinician orders → sample collection (phlebotomy) → specimen accession with unique ID → specimen tracking.
  3. **Processing & result entry:** run tests → enter results (instrument interface or manual) → flag out-of-range and **critical/panic values**.
  4. **Verification:** results verified by authorized personnel (supervisor) before release; verification is an explicit, audited step.
  5. **Report delivery:** verified report to the ordering clinician, the patient record, and the patient portal (per consent); reprints and corrections are new, audited versions.
  6. **Critical value escalation:** panic values trigger immediate, escalated notification with acknowledgment — fail loudly, never silently (per `MASTER_RULES.md` §11.3).
- **Major entities:** `lab_test`, `test_panel`, `lab_order`, `specimen`, `accession`, `result`, `reference_range`, `verification`, `lab_report`, `critical_value_event`.
- **Dependencies:** patient records; OPD/IPD/ER (orders); radiology (shared order model); notifications (critical values); billing (lab charges).
- **Permissions (indicative):** `lab:order`, `lab:result_entry`, `lab:verify`, `lab:report` — verification is a distinct permission from entry; clinical read access scoped.
- **Audit requirements:** order creation, specimen chain (collection → accession → processing), result entry vs. verification (who entered, who verified), report releases/corrections, and critical-value escalations with acknowledgment timestamps.
- **Future integrations:** LIS/HL7 instrument interfaces (bidirectional), barcode systems, national lab reporting (Phase 3/national), DICOM where applicable.
- **Production considerations:** result accuracy and verification discipline are safety-critical; turnaround time is a monitored metric; instrument downtime must have a documented manual-entry path that is still fully audited.

### 6.9 Radiology — **Phase 2**

- **Purpose:** manage radiology orders, scheduling, studies, and reports — with readiness to integrate PACS/DICOM rather than becoming a PACS itself.
- **Users:** ordering clinician, radiology receptionist, radiographer, radiologist, facility admin (modalities).
- **Core workflows:**
  1. **Order:** clinician orders a study (X-ray, USG, CT, MRI) with indication → order approved/scheduled.
  2. **Scheduling:** modality (machine) schedules with capacity and priorities (routine/urgent/stat); patient arrival and preparation instructions.
  3. **Study management:** study record per order; images arrive from modality/PACS; study states (ordered → scheduled → performed → reported).
  4. **Reporting:** radiologist dictates/types report → **verified** → released to the referring clinician and patient record; preliminary vs. final reports with amendment handling.
  5. **Result delivery:** report notification (see Laboratory escalation patterns for critical findings).
- **Major entities:** `radiology_order`, `modality`, `study`, `image_reference`, `radiology_report`, `report_status`.
- **Dependencies:** patient records; OPD/IPD/ER (orders); lab (shared order/report model); billing; PACS (integration, Phase 2 readiness).
- **Permissions (indicative):** `radiology:order`, `radiology:schedule`, `radiology:perform`, `radiology:report`, `radiology:verify`.
- **Audit requirements:** orders, scheduling changes, study states, report drafts vs. finals, amendments, and reads of reports; report timing (prelim → final) is auditable.
- **Future integrations:** DICOM modality worklists (MWL) and PACS, AI image triage (Phase 3), national imaging exchange where defined.
- **Production considerations:** report turnaround is a monitored metric; modality downtime has a documented fallback; image storage is out of scope (PACS owns it) but the study/image references must never dangle.

### 6.10 Operating Theatre (OT) — **Phase 3**

- **Purpose:** schedule and document surgical procedures safely: theatre scheduling, procedure records, surgical team, anesthesia, and recovery — with surgical-safety discipline (e.g., time-out/sign-out checklists).
- **Users:** surgeon, anesthesiologist, OT nurse, OT scheduler, facility admin (theatres, procedure catalog).
- **Core workflows:**
  1. **Theatre scheduling:** procedure request → theatre/date/time assignment with equipment and team requirements; conflict detection.
  2. **Pre-operative:** pre-op assessment, consent confirmation, checklist completion (identity, site, procedure).
  3. **Intra-operative:** procedure record, team log (surgeon, assistants, anesthetist, nurses), anesthesia record, medications, fluids, implants/materials used (links to inventory), time-stamped events.
  4. **Time-out / sign-out checklists:** structured safety checklists with recorded completion.
  5. **Recovery (PACU):** post-anesthesia care, observations, discharge from recovery.
- **Major entities:** `procedure_request`, `procedure`, `theatre`, `surgical_team`, `anesthesia_record`, `surgical_event`, `checklist`, `recovery_record`.
- **Dependencies:** patient records; IPD (inpatient procedures) / OPD (day surgery); inventory (implants/materials); pharmacy (anesthesia meds); billing (procedure charges); HR (team scheduling).
- **Permissions (indicative):** `ot:schedule`, `ot:document`, `ot:checklist`, `ot:close` (case completion) — clinical roles; theatre scheduling is administrator-managed.
- **Audit requirements:** every scheduled procedure and any change, checklist completion (each step, who, when), anesthesia drug administrations, materials used per case, and case closure. Surgical records are high-value medico-legal documents.
- **Future integrations:** implant registries, surgical video, national surgical reporting where defined.
- **Production considerations:** scheduling conflicts must be prevented (two cases on one theatre); materials used must reconcile with inventory and patient billing; checklist compliance is a monitored safety metric.

### 6.11 ICU / Critical Care — **Phase 3**

- **Purpose:** manage critically ill patients with high-frequency monitoring, structured observations, and escalation-prone workflows.
- **Users:** intensivist, ICU nurse, nursing lead, facility admin (beds, protocols).
- **Core workflows:**
  1. **Admission & bed assignment:** from IPD/ER/OT; ICU bed state management with acuity-based assignment.
  2. **High-frequency observations:** vitals and scores (e.g., NEWS-style early-warning scoring, configurable) at policy-defined intervals.
  3. **Monitoring:** vital trends, fluid balance, ventilator settings where applicable; alerts on threshold breaches and score escalations.
  4. **Critical care documentation:** daily goals, sedation scales, weaning plans, procedures (lines, intubation).
  5. **Step-down / discharge:** transfer out of ICU with handover documentation.
- **Major entities:** `icu_admission`, `icu_bed`, `observation_set`, `warning_score`, `alert`, `critical_care_note`, `procedure_record`.
- **Dependencies:** IPD; pharmacy (infusions); lab (stat results); ER/OT (source); RPM (Phase 3 device feeds).
- **Permissions (indicative):** `icu:admit`, `icu:observe`, `icu:document`, `icu:transfer` — clinical roles, ward-scoped.
- **Audit requirements:** observations and scores (who entered, when), alert generation and acknowledgment (who saw it, when), procedures, and transfers. Missing an ICU observation is a patient-safety event — the audit trail must prove the schedule was met.
- **Future integrations:** bedside monitors and ventilators (device feeds), RPM device streams (Phase 3), national critical-care reporting where defined.
- **Production considerations:** observation scheduling must be enforced (overdue observations escalate); the system must work with network interruptions at the bedside (queued sync with reconciliation).

### 6.12 Blood Bank — **Phase 3**

- **Purpose:** manage the blood supply chain safely: donors, units, components, compatibility, and transfusion — where a wrong unit is a life-threatening error.
- **Users:** blood bank technician, blood bank in-charge, ordering clinician, phlebotomist (donation).
- **Core workflows:**
  1. **Donor management:** donor registration, screening questionnaire, eligibility, donation history and deferrals.
  2. **Unit & component management:** donation → unit number → processing into components (packed cells, plasma, platelets) → testing (blood group, screening) → inventory with expiry.
  3. **Compatibility:** crossmatch and compatibility check (ABO/Rh, antibodies) against the patient's record before issue.
  4. **Issue & transfusion:** issue to patient (positive identification of unit and patient) → transfusion start/stop with dual verification where policy requires → reaction reporting.
  5. **Stock & discard:** expiry monitoring, discard with reason, stock alerts.
- **Major entities:** `donor`, `donation`, `blood_unit`, `component`, `compatibility_result`, `crossmatch`, `transfusion`, `reaction_report`, `discard`.
- **Dependencies:** patient records (recipient identity); lab (testing); inventory (units as stock); IPD/ER (transfusions); notifications.
- **Permissions (indicative):** `bloodbank:register_donor`, `bloodbank:process`, `bloodbank:issue`, `bloodbank:transfuse`, `bloodbank:discard` — technician vs. in-charge vs. clinical roles; issue requires specific authorization.
- **Audit requirements:** the complete unit lifecycle (donation → processing → testing → storage → issue → transfusion → discard), compatibility results, dual verifications, and any recall/withdrawal of units.
- **Future integrations:** blood-bank information standards, national blood inventory systems (Phase 3/national), barcode unit tagging.
- **Production considerations:** unit traceability must be exact (every unit traceable to donor and recipient); expired or unsuitable units must never be issuable; this module's correctness is directly life-critical.

### 6.13 Finance: Charges, Invoices, Payments — **MVP**

- **Purpose:** capture every charge, invoice it, collect payment, and reconcile — so the hospital gets paid accurately for what it actually did, and the ledger always balances.
- **Users:** billing clerk/cashier, accounts officer, facility finance admin, org finance, patient (portal payments, Phase 2).
- **Core workflows:**
  1. **Charge capture:** charges generated by clinical actions (consultation, procedures, medicines, lab tests, bed days) and manual charges, each linked to the source event (never free-floating).
  2. **Invoicing:** consolidate charges into invoices (per visit, per admission, per patient-account); tax (e.g., VAT) configuration per tenant; invoice lifecycle (draft → issued → paid/partial → adjusted).
  3. **Payments:** cash, card, wallet, bank, insurance share; **deposits** (advance payments) held and allocated; part-payments tracked; receipts generated from the ledger.
  4. **Refunds:** full/partial with reason, approval workflow, and reversal of the original transaction.
  5. **Outstanding balances:** patient-account aging, collection follow-up, statement generation.
  6. **Reconciliation:** daily settlement (per cashier/branch), payment-provider reconciliation, and general-ledger export — discrepancies alert immediately (per `MASTER_RULES.md` §37.3).
- **Major entities:** `charge`, `charge_source`, `invoice`, `invoice_line`, `payment`, `deposit`, `refund`, `receipt`, `patient_account`, `aging`, `settlement`, `ledger_entry`.
- **Dependencies:** every module that produces charges (OPD, IPD, ER, pharmacy, lab, radiology, OT); inventory (pricing); insurance (payer share); settings (tax, price lists); audit (financial events).
- **Permissions (indicative):** `finance:charge`, `finance:invoice`, `finance:collect`, `finance:refund` (approval-gated), `finance:adjust` (approval-gated, audited), `finance:reconcile`, `finance:void` (restricted) — segregation of duties: the person who charges is not the only person who can void.
- **Audit requirements:** every charge, invoice, payment, deposit allocation, refund, adjustment, void, and reconciliation. Financial events are immutable once posted; corrections are reversing entries, never edits.
- **Future integrations:** payment gateways (national wallets, cards), bank reconciliation feeds, accounting exports (Phase 2), insurance claims (Phase 2).
- **Production considerations:** idempotency on every financial write (per `MASTER_RULES.md` §12.4, §37.2); money as integer minor units; daily reconciliation is mandatory and monitored; cashier-level settlement must be practical at the counter during peak OPD hours.

### 6.14 Insurance: Policies, Claims, Approvals, Settlements — **Phase 2**

- **Purpose:** manage third-party payer relationships: patient policies, pre-authorization, claims submission, and settlement — turning insurance from a spreadsheet problem into a tracked workflow.
- **Users:** billing/insurance clerk, accounts officer, org finance, payer (via import/exchange where available).
- **Core workflows:**
  1. **Policy management:** payer master → policy products → patient coverage (link to patient records, validity, benefit limits).
  2. **Pre-authorization / approvals:** request approval for planned admissions/procedures; track approval status and expiry.
  3. **Claims:** build claim from invoiced services (charge lines mapped to payer benefit codes) → submit → track status (submitted, pending, partial, paid, denied) → denials with reasons and resubmission.
  4. **Settlements:** payer payment against claims → allocation → shortfall/co-pay to patient account → reconciliation.
- **Major entities:** `payer`, `policy_product`, `policy`, `coverage`, `authorization`, `claim`, `claim_line`, `claim_status`, `settlement`.
- **Dependencies:** finance (invoices/charges); patient records (coverage); settings (payer rules).
- **Permissions (indicative):** `insurance:policy`, `insurance:authorization`, `insurance:claim`, `insurance:settle` — finance roles, org-scoped.
- **Audit requirements:** policy changes, authorizations, claim submissions and every status change, denials, and settlements. Claim disputes must be reconstructable from the audit trail.
- **Future integrations:** payer portals/EDI (where available), national insurance schemes (Phase 3/national, when defined), claims clearinghouses.
- **Production considerations:** claims data must map exactly to invoice truth (no fabricated claim lines); benefit limits must be enforced at charge time where configured; payer downtime must not block cash billing.

### 6.15 Inventory: Items, Stores, Stock, Transfers, Adjustments — **MVP**

- **Purpose:** the storekeeping backbone: what is stocked, where, in what quantity, and who moved it — with controlled transfers and adjustments, shared by pharmacy, lab, OT, and general stores.
- **Users:** store/inventory clerk, store in-charge, facility admin (item master, stores), department requesters.
- **Core workflows:**
  1. **Item master:** consumables, medicines, equipment spares, with categorization, units, pricing, and reorder points.
  2. **Stores:** multiple stores per facility/branch (central store, pharmacy, ward stores); item-store balances.
  3. **Stock movements:** receipts (from procurement), issues, transfers between stores, internal usage — every movement is a typed, referenced event.
  4. **Transfers:** store-to-store with approval for high-value items; in-transit tracking; receipt confirmation.
  5. **Adjustments & counts:** cycle counts and corrections with reason and approval — never silent edits (per `MASTER_RULES.md` §30.4).
  6. **Alerts:** reorder-level alerts, expiring stock, dead stock.
- **Major entities:** `item`, `item_category`, `store`, `stock_balance`, `stock_movement`, `transfer`, `adjustment`, `reorder_point`, `stock_alert`.
- **Dependencies:** procurement (receipts); pharmacy (medicine stock integration); finance (valuation); settings.
- **Permissions (indicative):** `inventory:receive`, `inventory:issue`, `inventory:transfer`, `inventory:adjust` (approval-gated), `inventory:manage` — clerk vs. in-charge segregation.
- **Audit requirements:** every movement and adjustment with reason codes, approvals, and valuation impact; transfers tracked from dispatch to receipt confirmation.
- **Future integrations:** barcode/RFID (Phase 3), supplier catalogs, national medicine registries where available.
- **Production considerations:** stock accuracy is a prerequisite for pharmacy safety and financial truth — the movement log is the source of truth; concurrent movements (two clerks) must be serialized correctly; stock valuation must reconcile with finance.

### 6.16 Procurement: Vendors, Requests, Orders, Goods Receipt, Contracts — **Phase 2**

- **Purpose:** buy what the hospital needs with control: from purchase request to goods receipt to vendor payment, with contract terms enforced.
- **Users:** requester (department/store), procurement officer, finance (approvals, payment), facility admin (vendor master, contracts).
- **Core workflows:**
  1. **Vendor management:** vendor master, credentials, ratings, bank details (validated), blacklist.
  2. **Purchase request:** department requests with need and budget → approval workflow by value thresholds.
  3. **Purchase order:** issue PO from approved requests (single or consolidated) → vendor confirmation → PO states tracked.
  4. **Goods receipt (GRN):** receive against PO with quantity/quality check; partial receipts; link to inventory stock-in and invoice matching.
  5. **Contracts:** vendor contracts with pricing, validity, and terms; POs checked against contract prices.
  6. **Three-way match:** PO ↔ GRN ↔ vendor invoice before payment; mismatches block payment.
- **Major entities:** `vendor`, `purchase_request`, `approval_step`, `purchase_order`, `po_line`, `goods_receipt`, `vendor_invoice`, `contract`, `three_way_match`.
- **Dependencies:** inventory (item master, stock-in); finance (payment); HR (approval routing); settings.
- **Permissions (indicative):** `procurement:request`, `procurement:approve`, `procurement:order`, `procurement:receive`, `procurement:contract` — separation of requester, approver, and receiver.
- **Audit requirements:** every request/approval/order/receipt and any PO or price deviation, contract changes, and the match status of every vendor payment.
- **Future integrations:** supplier portals/e-procurement, catalog feeds, national framework contracts where defined.
- **Production considerations:** approval routing must never be bypassable by a requester approving their own request; GRN accuracy feeds inventory truth; payment blocks on match failure are enforced, not advisory.

### 6.17 HR: Employees, Departments, Shifts, Attendance, Leave, Payroll Readiness — **Phase 2**

- **Purpose:** manage the hospital's people: employment records, departments, shift scheduling, attendance, leave — and hand payroll-ready data to payroll systems without becoming a payroll processor.
- **Users:** HR staff, department heads, employees (self-service views), facility admin.
- **Core workflows:**
  1. **Employee records:** hire → employee file (personal data with consent and confidentiality), department, designation, licenses/qualifications (clinical credential tracking), history.
  2. **Departments & positions:** department structure and positions; staff assignment per facility/branch.
  3. **Shift scheduling:** shift templates (day/night/rotating), roster per department, conflict detection (overlaps, rest rules).
  4. **Attendance:** clock-in/out or schedule-based attendance, corrections with approval.
  5. **Leave:** leave types and entitlements, requests → approval → balance tracking.
  6. **Payroll readiness:** structured export (worked days, shifts, leave, deductions inputs) to payroll engines — not tax filing or salary disbursement.
- **Major entities:** `employee`, `department`, `position`, `shift_template`, `roster`, `attendance_record`, `leave_type`, `leave_request`, `payroll_export`.
- **Dependencies:** identity (staff = users); settings; notifications.
- **Permissions (indicative):** `hr:employee`, `hr:roster`, `hr:attendance`, `hr:leave`, `hr:payroll_export` — HR roles, org-scoped; employees see their own records.
- **Audit requirements:** employee data changes, roster changes, attendance corrections, leave approvals, and every payroll export (who exported what). Staff personal data is sensitive and access-controlled.
- **Future integrations:** payroll engines, biometric/biometric-reader attendance devices, credential/licensing registries (Phase 3/national).
- **Production considerations:** staff data is personal data under the same protection discipline as patient data (consent, minimization, retention); roster and attendance correctness drives payroll — errors here are money errors.

### 6.18 Asset Management: Equipment, Maintenance, Lifecycle — **Phase 3**

- **Purpose:** track equipment through its whole life: acquisition, deployment, maintenance, and retirement — with RFID/IoT readiness for location and condition tracking.
- **Users:** facility admin, biomedical engineer / maintenance staff, department heads, finance (valuation).
- **Core workflows:**
  1. **Asset register:** equipment with category, location, value, warranty, serial/barcode/RFID tag, and lifecycle state (procured → deployed → under repair → retired).
  2. **Maintenance:** scheduled maintenance with service contracts, work orders, downtime tracking, and certification records.
  3. **Lifecycle:** transfers between departments, decommission/retirement with disposition and financial write-off.
  4. **IoT/RFID readiness:** tag-based location tracking and condition/usage feeds (design the data model now, integrate devices in Phase 3).
- **Major entities:** `asset`, `asset_category`, `asset_location`, `maintenance_schedule`, `work_order`, `asset_transfer`, `retirement`, `iot_reading`.
- **Dependencies:** inventory (spares for maintenance); procurement (acquisition); finance (depreciation inputs); HR (maintenance staff).
- **Permissions (indicative):** `assets:register`, `assets:transfer`, `assets:maintain`, `assets:retire` — admin and maintenance roles.
- **Audit requirements:** asset lifecycle events, maintenance completions, transfers, and retirements; maintenance certification records must be provable.
- **Future integrations:** RFID/IoT device platforms, manufacturer service APIs, CMMS interchange.
- **Production considerations:** downtime tracking must be honest (a machine listed as available while down is a safety and planning hazard); maintenance history must survive equipment transfer.

### 6.19 Analytics and Reporting — **MVP (operational) → Phase 2 (financial/clinical) → Phase 3 (executive/AI)**

- **Purpose:** turn the platform's real operational data into decisions: operational dashboards (live), financial analytics, clinical analytics, and executive reporting — always from observed data, never fabricated (per `MASTER_RULES.md` P.15).
- **Users:** facility admin, org leadership, finance, clinical leads, platform operations.
- **Core workflows:**
  1. **Operational analytics (MVP):** live census, queue/wait times, appointments, registrations, discharges, bed occupancy.
  2. **Financial analytics (Phase 2):** revenue by department/payer, receivables aging, collection rates, day-sales-outstanding, charge capture gaps.
  3. **Clinical analytics (Phase 2/3):** readmission rates, diagnosis patterns, lab turnaround, critical-value response times, safety indicators.
  4. **Executive dashboards (Phase 2):** curated KPI dashboards per role with drill-down.
  5. **Reports:** scheduled and ad-hoc reports with export (PDF/CSV), access-controlled and audited (report content can be sensitive).
- **Major entities:** `dashboard`, `kpi_definition`, `report_template`, `report_run`, `report_schedule`, `metric_snapshot`.
- **Dependencies:** every source module; audit (definitions of metrics); settings.
- **Permissions (indicative):** `analytics:view` (role- and scope-gated), `reports:run`, `reports:schedule`, `reports:export` — finance and clinical analytics are separately gated.
- **Audit requirements:** every report run and export (who, what scope, when) — data-export audit per `MASTER_RULES.md` §19.3; aggregate definitions are versioned.
- **Future integrations:** BI-tool exports, AI forecasting (Phase 3), national reporting where defined.
- **Production considerations:** analytics must not degrade transactional performance (read replicas, snapshots); metric definitions are agreed and stable — a changing KPI is not a KPI; patient-level drill-down is access-controlled like clinical data.

### 6.20 Telehealth: Virtual Consultation, Video, E-Prescription, Follow-Up — **Phase 3**

- **Purpose:** extend care beyond the facility: scheduled virtual consultations with secure video, documentation, e-prescription, and follow-up — integrated with the same record, not a separate product.
- **Users:** doctor (teleconsult), patient (portal), front desk (scheduling), facility admin (policy).
- **Core workflows:**
  1. **Scheduling:** teleconsult appointment types in the same schedule/queue model as in-person.
  2. **Pre-visit:** consent for telehealth, patient preparation, identity verification.
  3. **Video consultation:** secure, standards-based video (WebRTC); recording is explicit, consent-based, and stored per policy (or disabled).
  4. **Documentation:** the consultation is an encounter (shared model) with notes, diagnosis, prescriptions, and investigation orders.
  5. **E-prescription & follow-up:** prescription to pharmacy (and patient), follow-up teleconsult or in-person booking.
- **Major entities:** `teleconsult`, `video_session`, `telehealth_consent`, `encounter` (shared), `e_prescription`.
- **Dependencies:** patient records; front desk; OPD (encounter model); pharmacy; notifications; finance (consultation charges).
- **Permissions (indicative):** `telehealth:schedule`, `telehealth:conduct`, `telehealth:prescribe`, `telehealth:record` — clinical roles; recording permission is separate and restricted.
- **Audit requirements:** teleconsult scheduling and conduct, consent capture, video-session metadata (start/end, participants), any recording, prescriptions, and charges.
- **Future integrations:** national telehealth frameworks and verification (Phase 3/national), wearable/device feeds (with RPM).
- **Production considerations:** video quality and connectivity failure handling (reconnect, fallback to phone) must be designed in; clinical documentation of a virtual visit must meet the same standard as in-person; privacy of the video channel is mandatory.

### 6.21 RPM: Device Integration, Measurements, Alerts, Monitoring — **Phase 3**

- **Purpose:** continuous or periodic patient measurements from devices (wearables, home monitors) feeding alerts and clinical review — extending care between visits.
- **Users:** patient (device wearer), care team (doctor/nurse), facility admin (programs, thresholds).
- **Core workflows:**
  1. **Device & program setup:** enroll patient into an RPM program; associate devices; consent for data collection.
  2. **Measurement capture:** device data via integration (wearable platform, gateway, manual entry fallback) with validation (units, plausibility, device identity).
  3. **Thresholds & alerts:** personalized thresholds → out-of-range alerts with severity and escalation to care team; acknowledgment workflow.
  4. **Monitoring view:** trends and timelines for the care team; integration with the clinical record (observations appended to the patient's chart as device-sourced, clearly labeled).
  5. **Intervention:** alerts may trigger telehealth follow-up, medication review, or hospital guidance — always human-mediated.
- **Major entities:** `rpm_program`, `rpm_enrollment`, `device`, `device_reading`, `alert_threshold`, `rpm_alert`, `acknowledgment`.
- **Dependencies:** patient records; telehealth (intervention path); notifications; analytics (device data for trends).
- **Permissions (indicative):** `rpm:enroll`, `rpm:manage_thresholds`, `rpm:review_alerts`, `rpm:acknowledge` — clinical roles; patients see their own device data.
- **Audit requirements:** enrollments, threshold changes, alert generation/escalation/acknowledgment, and any clinical action taken from device data.
- **Production considerations:** device data is not clinically verified — it must be labeled as device-sourced and never silently treated as a verified clinical measurement; alert fatigue must be tuned (per `MASTER_RULES.md` §34.4); data volume (continuous readings) needs ingestion and retention design.
- **Future integrations:** wearable platform APIs, BLE/medical gateway devices, national RPM programs where defined.

### 6.22 CDSS: Drug Interactions, Allergy Alerts, Clinical Rules, Pathways — **Phase 3**

- **Purpose:** give clinicians decision support at the point of care: drug–drug and allergy interaction checks, clinical rules, and evidence-based pathways — as *support*, with human review always in control (per `MASTER_RULES.md` §34).
- **Users:** prescribing clinicians, clinical authority (rules governance), facility admin (enablement).
- **Core workflows:**
  1. **Interaction checks:** drug–drug and drug–allergy checking at prescription time against a maintained knowledge base; severity-tiered alerts.
  2. **Clinical rules:** configurable rules (e.g., dose-range checks, duplicate-therapy warnings, monitoring prompts) versioned and reviewed by clinical authority before release.
  3. **Pathways:** evidence-based care pathways with step guidance; deviation is documented, not blocked.
  4. **Override management:** clinician override with reason; overrides recorded and analyzed (alert fatigue monitoring).
- **Major entities:** `interaction_rule`, `drug_allergy`, `clinical_rule`, `rule_version`, `pathway`, `alert`, `override_record`, `rule_governance_log`.
- **Dependencies:** pharmacy (prescriptions); patient records (allergies, diagnoses); OPD/IPD/ER (prescribing surfaces).
- **Permissions (indicative):** `cdss:configure`, `cdss:review_rules` (clinical authority), `cdss:override` — prescribing clinicians; override is never granted to non-clinical roles.
- **Audit requirements:** every alert shown, every override (who, why, when), rule version in effect at the moment of the alert, and rule governance changes. Audit must reconstruct the exact alert context.
- **Future integrations:** interaction knowledge-base vendors, national pharmacovigilance reporting (Phase 3/national).
- **Production considerations:** the knowledge base must be versioned and update-tested (an update must not break existing prescriptions); alerts must be precise to avoid fatigue; a CDSS failure must fail open to prescribing (never block care) while still logging loudly.

### 6.23 AI: Documentation Assistance, Summarization, Forecasting, Decision Support — **Phase 3**

- **Purpose:** reduce documentation burden and surface insight using AI — drafting clinical notes, summarizing records, forecasting demand, and supporting (never replacing) decisions — under strict safety and audit rules (per `MASTER_RULES.md` §33).
- **Users:** clinicians (documentation assistance, summaries), leadership (forecasts), platform (model operations).
- **Core workflows:**
  1. **Documentation assistance:** draft notes from structured encounter data; clinician reviews, edits, and signs — the draft is labeled AI-generated.
  2. **Summarization:** patient history/care summaries for handover and review; clearly labeled, with provenance links.
  3. **Forecasting:** demand forecasting (appointments, admissions, occupancy), financial projections — labeled as estimates.
  4. **Decision support:** pattern suggestions (e.g., "patients with these characteristics often...") — assistive only, never prescriptive without clinician review.
- **Major entities:** `ai_draft`, `ai_summary`, `ai_forecast`, `ai_action` (audit record), `model_version`.
- **Dependencies:** clinical record (structured data); OPD/IPD (documentation surfaces); analytics (forecasts); audit (every AI action).
- **Permissions (indicative):** `ai:use` (clinical roles), `ai:model_manage` (platform ML ops) — a clinician always owns the final signed document.
- **Audit requirements:** every AI output (prompt context, model version, output, whether reviewed/signed, who signed); any AI action is traceable per `MASTER_RULES.md` §33.5.
- **Production considerations:** never send patient data to unapproved external models; model versions are pinned and evaluated before release; AI features ship behind feature flags with kill-switch (per `MASTER_RULES.md` §38); hallucinations in clinical text are mitigated by making all drafts clinician-signed.
- **Future integrations:** the AI/CDSS inference service is a Python (FastAPI) service per `MASTER_RULES.md` §3.1 — inference only, no business logic; NLP for free-text extraction; national AI guidelines as they emerge.

### 6.24 Interoperability: FHIR/HL7/DICOM Readiness, External APIs, National Integrations — **Phase 3 (readiness from the start)**

- **Purpose:** exchange clinical and operational data with other systems — labs, PACS, payers, and national systems — through standards, honestly and securely, without claiming integrations that do not exist (per `MASTER_RULES.md` §32).
- **Core workflows:**
  1. **FHIR R4 readiness:** a projection/mapping layer from the internal model to FHIR R4 resources (Patient, Encounter, Observation, MedicationRequest, DiagnosticReport); export/import with contract tests.
  2. **HL7 v2 readiness:** ADT (admissions/transfers/discharges) and lab order/result messaging patterns where the ecosystem uses HL7.
  3. **DICOM readiness:** modality worklists and study references with PACS (see Radiology).
  4. **External APIs:** versioned, OAuth2-secured public APIs for sanctioned partners; scoped tokens; full audit.
  5. **National integrations:** integration with national systems **only when those systems exist and are specified** — Swasthya does not invent or simulate them; each national integration is its own contract-tested project.
- **Major entities:** `interop_connection`, `interop_message`, `fhir_mapping`, `hl7_message`, `oauth_client`, `webhook`, `integration_status`.
- **Dependencies:** every clinical module (source data); audit (exchange events); security (OAuth2).
- **Permissions (indicative):** `interop:manage` (platform/integration roles), scoped tokens per partner system; exchange events are read-audited like clinical access.
- **Audit requirements:** every message exchanged (direction, payload type, correlation, outcome), consent where patient data leaves the tenant, and connection credential changes.
- **Production considerations:** an integration's true status (live/degraded/down) is monitored and visible — never claimed green without evidence (`MASTER_RULES.md` P.16); message retries/queues must be idempotent; outbound PHI exchange requires consent and transport security per Section 10 of the rules.

---

## 7. Phasing and Roadmap

### 7.1 MVP — "a single hospital can run on Swasthya"

**Scope (modules):** Platform foundations (tenancy, identity, RBAC, notifications, settings, audit, subscriptions/entitlements-lite), Patient Registration, Front Desk (appointments/schedules/queues/tokens), OPD, Pharmacy, Finance, Inventory (storekeeping), Operational Analytics.

**What MVP must prove:** a real hospital can register patients, run an OPD day (schedules → booking → queue → encounter → prescription → dispensing → billing → settlement), track medicine stock, and see a trustworthy operational dashboard — with full tenancy isolation, audit, and tests. This is the vertical slice that validates the architecture before the rest is built.

**Exit criteria:** all MVP modules pass the Definition of Done (MASTER_RULES §40); at least one pilot hospital runs real workflows in staging and then production; tenancy-leakage and authorization-matrix suites green; billing reconciles daily; no untested critical workflow.

### 7.2 Phase 2 — "inpatient and diagnostics"

**Scope:** IPD (admission → discharge), Emergency, Laboratory, Radiology, Insurance, Procurement, HR (payroll readiness), Financial + Clinical Analytics, Patient Portal.

**Exit criteria:** a hospital can run an inpatient stay end-to-end with billing and discharge summary; lab/radiology results flow to the record with verification and critical-value escalation; insurance claims are tracked; staff and payroll data are exportable; portal gives patients read access to their own records.

### 7.3 Phase 3 — "advanced clinical care and intelligence"

**Scope:** Operating Theatre, ICU, Blood Bank, Telehealth, RPM, CDSS, AI (documentation assistance, summarization, forecasting), Asset Management, Interoperability (FHIR/HL7/DICOM readiness, external APIs, national integrations as they exist).

**Exit criteria:** surgical, critical-care, and transfusion workflows documented and audited to the same standard as OPD/IPD; virtual care and remote monitoring operational with alert workflows; CDSS and AI live behind flags with clinician sign-off on all outputs; first national/partner integrations live and contract-tested.

### 7.4 Enterprise

**What it means:** the *offerings* for large hospital groups and compliance-sensitive customers, not a different codebase: escalated tenancy options (e.g., schema-per-tenant or dedicated deployments per `MASTER_RULES.md` §36.5), SLA tiers, advanced security/compliance packs, white-labeling, custom integrations under the integration discipline, professional services and training, and dedicated support.

**Rule:** enterprise features are the same product, configured — never a fork (per `MASTER_RULES.md` §1.3).

### 7.5 National Scale

**What it means:** an *operational commitment*, not a feature list:

- **Availability & performance:** multi-AZ, RTO/RPO targets from `MASTER_RULES.md` §22, load-tested to national capacity, read-replica strategy for analytics.
- **Resilience:** DR drills, backups verified, region redundancy.
- **Compliance posture:** documented legal assessment before any compliance claim (Section 9); consent, retention, and audit designed in.
- **National integration:** readiness to integrate with national systems **when they exist and are specified** — each is a real, contract-tested project with a named owner.
- **Localization:** Nepali and English UI readiness, timezone and currency (NPR) configuration, Nepal-specific workflows (e.g., national ID linkage with consent) as they are legally and technically defined.

## 8. Cross-Cutting Requirements

- **Security & data protection:** as defined in `MASTER_RULES.md` §§6–10 — tenant isolation (RLS), encryption, no PHI in logs, consent and purpose limitation, retention schedules, audit everywhere. These apply to every module in this document.
- **Mobile-first:** every module's core workflows are usable on phones/tablets (per `MASTER_RULES.md` §14).
- **Accessibility:** WCAG 2.1 AA for patient-facing and staff-facing surfaces (per `MASTER_RULES.md` §15).
- **Performance:** per-module performance budgets; the peak-hour OPD rush, ER load, and lab result volume define the load tests.
- **Localization & config:** language, timezone, currency, tax, and clinical catalogs are tenant configuration, never hardcoded.
- **Identity safety:** wrong-patient prevention (identity confirmation, duplicate detection, merge discipline) is a product-wide requirement, not a patient-module concern.

## 9. Compliance Posture (what we do and do not claim)

- **We do not claim** compliance with any specific law, regulation, certification, or standard — including Nepal's privacy law (2075), health-sector regulations, or any international framework (e.g., HIPAA-style or ISO certifications) — **until** qualified legal review has assessed the platform and the claim is documented with evidence.
- **We do design for compliance readiness:** auditability of every sensitive action, consent capture and versioning, purpose limitation, data minimization, retention and deletion schedules, access control, and export support — so that when legal review occurs, the platform can demonstrate its controls rather than being retrofitted.
- **National integrations** (identity, health-number, insurance, telehealth frameworks) are built only when the national system exists and is specified; Swasthya does not simulate them or imply they exist.
- **Clinical and AI claims** are scoped to what the product demonstrably does (Sections 6.22–6.23) — assistive, clinician-controlled, and audited — and no feature is marketed as autonomous clinical decision-making.

## 10. How This Document Changes

- This document is a living requirements baseline. Changes are proposed like code: with rationale, in review, versioned.
- A requirement added here must be implementable under `MASTER_RULES.md`; if it conflicts, the conflict is resolved in writing (an ADR) before implementation.
- Phases are scope boundaries, not promises of dates. A module moves phases only through the roadmap process — never silently in a PR.

---

*This document defines what Swasthya is for and what it will do. The engineering constitution (`MASTER_RULES.md`) governs how it is built. Neither document is code; both are the contract the code must honor.*
