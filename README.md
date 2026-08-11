# Swasthya

**A production-grade, nationally scalable Hospital Management System (HMS) SaaS — under development.**

> **Status: Phases 0–5 substantially complete, the first clinical workflow (Phase 6/7 + billing/payment spine) is implemented end to end, and the Swasthya web application (`frontend/`) is now real: a React + TypeScript SPA connected to the backend.**
> This repository contains the engineering contract (nineteen documents), a working backend (environment, PostgreSQL configuration, migrations, the API envelope/error/middleware stack, authentication with rotating refresh tokens, RBAC, tenant/facility context, authorization gates, the append-only audit trail, and the Patient Master — all isolated per tenant/facility with PostgreSQL RLS enforced), the complete vertical clinical workflow (patient → appointment → check-in → queue → doctor → encounter → clinical note → diagnosis → prescription → invoice → payment, every step real and audited), and the web application that runs that workflow: login, tenant/facility context, patient list/registration/profile, availability-driven booking, check-in, queue, the doctor workspace (note → diagnosis → prescription → sign), invoice + idempotent payment, and the authorized audit view — mobile-first with a bottom navigation and no horizontal overflow, verified by Playwright end-to-end tests against the real backend and real database (desktop + iPhone viewport). **IPD, emergency, pharmacy dispensing, laboratory, radiology, inventory, and the rest of the roadmap are not implemented.** Nothing in this README describes a feature that does not exist.

---

## What Swasthya Is

Swasthya is management software that hospitals run on: a multi-tenant SaaS covering patient registration, front desk, outpatient and inpatient care, pharmacy, laboratory, radiology, billing, inventory, and the operations around them — sold as one platform serving many hospital organizations, each with isolated data and multiple facilities and branches.

It is **not** a public hospital website, not a demo, not a prototype. It is a commercial healthcare product in the making.

**Where the project actually is:** per `ROADMAP.md`, Phase 0 (Discovery) and Phase 1 (Architecture) are substantially complete as documents; **Phases 2–7 are implemented in `backend/`** — API foundation, PostgreSQL schema, authentication (access + rotating refresh tokens), RBAC, tenant/facility context, authorization middleware, audit, hospital-administration catalogs, the Patient Master (registration, MRN, encrypted identifiers with duplicate detection, contacts, insurance, consents, documents, timeline), and **Tenancy V2 hardening**: the Branch level of the tenancy hierarchy, PostgreSQL row-level security on every tenant-owned table, the non-owner `swasthya_app` runtime role, request-scoped tenant context projected onto the database via transaction-local GUCs, and explicit audited platform support sessions. **The first complete clinical workflow is also implemented and verified live over HTTP as the least-privilege application role under RLS**: schedule/availability → booking (double-booking-proof) → check-in (race-safe tokens) → queue → encounter → clinical documentation → diagnosis → prescription → sign → charges → invoice → idempotent payment — all tenant/facility-isolated and audited. A staging-readiness validation pass is recorded in `STAGING_READINESS_REPORT.md` (verdict: READY FOR STAGING): the OPD workflow re-verified live under RLS; a CI pipeline written (`.github/workflows/ci.yml`) and executed locally on a disposable PostgreSQL (`backend/ci/run-local-ci.sh`); RLS load-tested to a synthetic 1M-patient workload (`backend/ci/load-benchmark.sh`); a real backup/restore drill executed with an idempotent post-restore grants fixup (`database/security/grants.sql`); and a concrete staging build specification (`STAGING.md` — no staging environment exists yet). Remaining work is recorded in `DEVELOPMENT_LOG.md` (ADR-001 ratification, repository initialization + first CI run on a real runner, staging build per `STAGING.md`, legal-counsel engagement, MFA TOTP, and the roadmap phases from IPD onward).

---

## Product Vision

Give hospitals of any size — from a single polyclinic to a multi-facility hospital group — a single, modern system to run their entire operation: patients, front desk, clinical care, pharmacy, labs, billing, inventory, and reporting — on one secure platform where each hospital's data is isolated, and the platform can grow from one room to national scale without migrating systems.

Vision details: `PRODUCT_REQUIREMENTS.md` §1.

---

## Target Users

- **Doctors, nurses, pharmacists, laboratory and radiology staff** — clinical users working at speed, mobile-first.
- **Receptionists, billing clerks, accountants** — front-desk and financial operations.
- **Hospital and organization administrators, executives** — management, configuration, and dashboards.
- **Patients** — a secure portal for their own appointments, results, and bills (per consent).
- **Platform operators** — the SaaS team running tenant lifecycle, support, and the platform itself.

Persona details: `PRODUCT_REQUIREMENTS.md` §4.

---

## SaaS Architecture

Implemented so far (see `ARCHITECTURE.md`, `TENANCY.md` — the remaining layers are design):

- **One platform, many tenants.** The tenant is the organization; each organization owns facilities → branches → departments. User access is expressed as scoped role assignments — implemented for organizations, facilities, and users in `backend/`.
- **Modular monolith behind one API.** A single Laravel application (`backend/`) with disciplined domain boundaries, designed to evolve into services only when measured load justifies it.
- **Tenant isolation is enforced in layers.** The application layer derives tenant/facility context from the authenticated principal and scopes every query; the final hard guarantee — PostgreSQL row-level security (RLS) with a dedicated non-owner app role (`swasthya_app`, no `BYPASSRLS`) — is **implemented** on every tenant-owned table (`TENANCY.md` §6, `SECURITY.md` §8, `database/migrations/2026_08_11_100100_enable_row_level_security.php`). The tenant context middleware runs inside one transaction per request, sets the RLS GUCs (`app.tenant_id`, `app.facility_id`, `app.branch_id`, `app.user_id`, `app.is_platform`) from the authenticated principal — never client input — and the settings die with the transaction, so pooled connections can never leak context.

Architecture details: `ARCHITECTURE.md`, `TENANCY.md`.

---

## Supported Healthcare Organizations

Swasthya serves, by configuration and data — never by custom code per customer:

- Standalone hospitals and clinics (single facility, single or multiple branches).
- Multi-facility hospital groups (one organization owning several hospitals).
- Specialized centers (diagnostics, day surgery, polyclinics).

Differences between organizations are **configuration, not forks**: catalogs, price lists, settings, and entitlements. Any hospital needing bespoke behavior opens a product request — custom per-customer code is prohibited by `MASTER_RULES.md` §1.3.

---

## Technology Stack

The stack is **decided by design** (formal ratification of the choice itself is recorded as ADR-001); the status of each layer is in the table below:

| Layer | Choice | Rationale (one line) | Status |
|---|---|---|---|
| Frontend | **React + TypeScript** (single SPA, Vite) | One app for patients and staff, mobile-first | Implemented — foundation + OPD workflow UI (`frontend/`) |
| Backend | **Laravel (PHP)** | Sole business API: tenancy, RBAC, billing, clinical workflows, queues | Implemented (Phases 2–7 scope) |
| Database | **PostgreSQL** | Only database; RLS tenancy, UUIDs, integer money | Implemented — dev/test (16.4); RLS enforced on all tenant tables via `swasthya_app` |
| Cache / queues / realtime | **Redis** | Cache, queues, notifications, realtime | Designed — not built |
| Files | **S3-compatible object storage** | Documents via signed, audited URLs | Designed — not built |
| AI / CDSS (future) | **Python (FastAPI)** | Inference only, when AI features are funded | Future |
| Interoperability (future) | **FHIR / HL7 / DICOM** readiness layers | Standards at the boundary | Future |

**Deliberately not used:** Angular, CodeIgniter, Node.js for business logic — the platform assigns one responsibility to one technology and does not duplicate capability (`ARCHITECTURE.md` §2.1).

---

## Repository Structure

**Current state (what exists):**

```
Swasthya Nepal/
├── README.md                  ← this file
├── DEVELOPMENT_LOG.md         ← permanent chronological engineering record
├── MASTER_RULES.md            ← engineering constitution
├── PRODUCT_REQUIREMENTS.md    ← product vision and functional scope
├── ARCHITECTURE.md            ← system architecture
├── DATABASE.md                ← conceptual and logical data design
├── DESIGN_SYSTEM.md           ← mobile-first design system
├── SECURITY.md                ← security controls design
├── TENANCY.md                 ← multi-tenancy architecture
├── API_CONTRACTS.md           ← API contract conventions
├── TESTING_STRATEGY.md        ← testing strategy
├── DEPLOYMENT.md              ← deployment design
├── DISASTER_RECOVERY.md       ← disaster recovery strategy
├── OBSERVABILITY.md           ← observability design
├── CLINICAL_SAFETY.md         ← clinical safety principles
├── INTEROPERABILITY.md        ← interoperability readiness
├── AI_RULES.md                ← AI governance rules
├── BILLING.md                 ← SaaS billing design (separate from patient billing)
├── ROADMAP.md                 ← staged delivery roadmap
├── .gitignore                 ← protects .toolchain/, .freebuff/, and all local .env files
├── .toolchain/                ← project-local dev toolchain (portable PHP + PostgreSQL, gitignored)
├── docs/                      ← generated documentation dashboard (self-contained HTML preview)
├── backend/                   ← the implemented Laravel API (Phases 2–4 scope)
│   ├── app/                   ← controllers, middleware, models, services, support
│   ├── config/                ← PostgreSQL-only, argon2id, JSON logging, swasthya.php platform config
│   ├── database/migrations/   ← organizations, facilities, departments, locations, wards/rooms/beds, staff, services, facility_settings, roles/permissions, role_assignments, audit_events, refresh_tokens, users
│   ├── routes/api.php         ← versioned /api/v1 surface
│   ├── ci/                    ← runnable local pipeline, RLS load benchmark, backup/restore drill
│   ├── database/security/     ← swasthya_app role bootstrap + restore-time grants
│   └── tests/                 ← 241 tests / 1,748 assertions against real PostgreSQL (RLS enforced)
├── frontend/                  ← the React + TypeScript SPA (Vite)
│   ├── src/                   ← design-system components, API client, auth + tenant context, pages
│   ├── e2e/                   ← Playwright specs: full OPD workflow (desktop) + mobile receptionist flow
│   └── package.json           ← dev server on :5173, proxies /api to the backend
├── .github/workflows/ci.yml   ← CI pipeline definition (backend + disposable PostgreSQL)
└── .freebuff/                 ← agent tooling (not project code)
```

**Planned monorepo layout** (per `ARCHITECTURE.md` §2): `infra/` (Docker, IaC, CI/CD runner wiring) is not yet created; `backend/` and the `frontend/` SPA exist. `STAGING.md` is the concrete staging build spec.

---

## Development Setup

**The backend runs today, without Docker.** Because this machine had no PHP/PostgreSQL and Docker's engine was unavailable, the project uses a **project-local portable toolchain** (gitignored, no admin rights, no global installs):

- **Toolchain:** `../.toolchain/` — portable PHP 8.4, Composer, and PostgreSQL 16.4; a PostgreSQL cluster on `127.0.0.1:54329` with a randomly generated local password stored only inside `.toolchain/` (never in source).
- **Run it:**
  ```bash
  cd backend
  ../.toolchain/php/php.exe artisan serve --host=127.0.0.1 --port=8000
  ```
- **Migrate + seed the platform catalogs (roles/permissions):**
  ```bash
  ../.toolchain/php/php.exe artisan migrate:fresh --seed --force
  ```
- **Tests (real PostgreSQL, never SQLite):**
  ```bash
  ../.toolchain/php/php.exe vendor/bin/pest
  ```

The designed one-command Docker Compose stack (`DEPLOYMENT.md` §2) remains the production-shaped target and is not yet exercised locally. Full reproduction steps: `DEVELOPMENT_LOG.md` (Phase 2 toolchain entries).

---

## Environment Setup

- Environment configuration comes from environment variables; **`backend/.env.example` exists** with placeholders only (`MASTER_RULES.md` §28) — copy it to `backend/.env` locally, never commit it. All `SWASTHYA_*` variables are documented there.
- Secrets live only in local `.env` / the toolchain / (later) a managed secrets store — never in the repository (`SECURITY.md` §13); `.gitignore` protects them.
- Local `.env.testing` points the test suite at the `swasthya_test` database.

---

## Documentation

All eighteen documents are the engineering contract. Recommended reading order for a new engineer:

1. **README.md** (this file) — orientation.
2. **ROADMAP.md** — where the project is going and where it currently stands.
3. **PRODUCT_REQUIREMENTS.md** — what we are building.
4. **MASTER_RULES.md** — how we build; the constitution every contribution must obey.
5. **ARCHITECTURE.md** and **TENANCY.md** — the shape and the isolation model.
6. **DATABASE.md**, **API_CONTRACTS.md**, **DESIGN_SYSTEM.md** — the data, the contract, the UI rules.
7. **SECURITY.md**, **TESTING_STRATEGY.md**, **DEPLOYMENT.md**, **DISASTER_RECOVERY.md**, **OBSERVABILITY.md** — how it stays safe, proven, run, recovered, and observed.
8. **CLINICAL_SAFETY.md**, **AI_RULES.md**, **INTEROPERABILITY.md**, **BILLING.md** — domain-specific governance.
9. **DEVELOPMENT_LOG.md** — the chronological record of what has actually been done.

---

## Development Phases

The platform is built in 23 gated phases, never all at once — `ROADMAP.md`:

- **Phase 0 – Discovery** (substantially complete: product scope, design system)
- **Phase 1 – Architecture** (substantially complete: this document set; remaining: ADR-001, repo init)
- **Phase 2 – Platform Foundation** (implemented: environment, PostgreSQL config, migrations, API envelope/errors/middleware, health checks, validation, testing foundation)
- **Phase 3 – Identity and Tenancy** (implemented: organizations, facilities, users, token auth with refresh rotation, roles/permissions/assignments, tenant+facility context, authorization gates, audit hash-chain; **Tenancy V2**: Branch hierarchy, PostgreSQL RLS + non-owner `swasthya_app` role, request-scoped DB context, audited support sessions; remaining: MFA TOTP flow)
- **Phase 4 – Hospital Administration** (implemented: departments, locations, wards→rooms→beds, staff profiles with license encrypted at rest, hospital services catalog, versioned facility configuration; tenant-safe composite FKs, bed state machine + optimistic locking)
- **Phase 5 – Patient Master** (implemented: registration with atomic MRN, encrypted identifiers + duplicate detection/merge, contacts, insurance, versioned consents, documents, timeline)
- **Phase 6 – Front Desk** (implemented: schedule templates/exceptions, derived availability, row-locked booking, check-in with race-safe tokens, queue, cancellation with reason)
- **Phase 7 – OPD** (implemented: encounter spine, structured notes, diagnoses, prescriptions, immutable sign-off) **+ billing/payment spine** (charges, invoices, idempotent payments)
- **Phases 8–22** — IPD, emergency, laboratory, radiology, pharmacy, inventory, HR/assets, OT/ICU/blood, analytics, interoperability, telehealth, RPM, CDSS/AI, national scale.

**Milestones:** M0 (foundation ratified) → **M1 (vertical slice: tenant + auth + RBAC + patient registration + booking — the full slice is now built and verified: register → book → check-in → queue → encounter → note → diagnosis → prescription → invoice → payment)** → **M2 (MVP / pilot-ready: a real hospital runs a full OPD day)** → M3/M4 (Phase 2/3 scope) → M5 (national scale). The MVP is a horizontal cut across phases, not a single phase.

---

## Testing

**201 tests (1475 assertions) pass against real PostgreSQL** — the red-line suites for the implemented foundation are green: authentication (login, refresh rotation with reuse detection, lockout, logout revocation), authorization, the role×permission matrix, tenant isolation, facility isolation, audit (hash-chain tamper-evidence, scoped reads), org/facility/user CRUD, the Phase 4 catalogs (department/location/ward/room/bed chains, bed state machine + optimistic locking, staff encryption at rest, service catalog, versioned facility settings), the Phase 5 patient master (registration/MRN, encrypted identifiers, duplicate candidates + merge, contacts, insurance, consents, documents, isolation), and the Phase 6/7 clinical workflow — booking availability + double-booking guard, check-in tokens, queue, encounter lifecycle, notes/diagnoses/prescriptions, immutable signing, invoice issue idempotency, payment idempotency, overpayment refusal, cross-tenant/facility isolation, role gates, audit integrity — including a full end-to-end patient→payment workflow test. "Production-grade" will be a property CI proves, not a claim the README makes — CI does not exist yet.

---

## Security

**The foundation controls are implemented; the deep controls are designed.** Implemented: argon2id hashing, token auth (short-lived access tokens, rotating refresh tokens with reuse detection), account lockout, per-IP rate limits, RBAC with live permission checks (role changes take effect immediately), tenant/facility context derived from the principal, 404-for-reads/403-for-writes isolation semantics, append-only hash-chained audit, security headers, no-PHI logging, app-layer at-rest encryption (staff license numbers and patient identifier values — AES-256-GCM; ciphertext in the DB, never logged or audited), signed-clinical-record immutability (no edit path on signed encounters/notes), idempotent financial operations (payment keys, charge-once invoicing), tenant-safe composite foreign keys that make cross-tenant references structurally impossible, and **PostgreSQL row-level security with the dedicated non-owner `swasthya_app` role** (`SECURITY.md` §8, `DATABASE.md` §1.5): RLS is enabled with per-table policies on all tenant-owned tables, enforced at the engine with transaction-local GUCs set by the tenant-context middleware. Designed, not yet implemented: MFA TOTP flow (`SECURITY.md` §3), breach-list checking, secrets store (`SECURITY.md` §13), full `SECURITY.md` required-control list. `OBSERVABILITY.md` includes an absolute never-log rule for patient data and secrets.

**Compliance:** Swasthya claims **no** regulatory compliance or certification — none is claimed until verified by qualified assessment with documented evidence (`PRODUCT_REQUIREMENTS.md` §9). The design is positioned for assessment; it is not an assessment.

---

## Deployment

**Nothing is deployed.** `DEPLOYMENT.md` specifies the design: environment stages (local/CI/staging/production), build-once promote-same-artifact releases, zero-downtime rolling deploys, forward-only migrations, infrastructure as code, and a provider-agnostic posture (no cloud provider assumed). `DISASTER_RECOVERY.md` sets RPO/RTO as **targets validated by drills** — no recovery guarantee is claimed.

---

## Contribution Rules

Swasthya is governed by **`MASTER_RULES.md`** — read it before contributing. The essentials:

- **Everything lands via reviewed pull requests** on a protected `main`; conventional commits; branch names per convention.
- **Definition of done** (`MASTER_RULES.md` §40) gates every merge: code, tests, docs, security, tenancy, audit, observability, accessibility, deployment.
- **Decisions change through ADRs**, never by accretion — including any change to the technology ownership table.
- **Tests, docs, and audit accompany code**; untested critical workflows and undocumented APIs do not ship.
- **The engineering log is permanent:** every meaningful change is recorded in `DEVELOPMENT_LOG.md` with its date, decision, reason, and impact — only work actually performed.
- **No fabricated anything:** no fake data, no fake integrations, no fake analytics, no demo functionality masquerading as production (`MASTER_RULES.md` Prohibited Practices).

---

## Honest Status Summary

| What exists | What does not |
|---|---|
| Nineteen design/governance documents | Any clinical, financial, or operational business module |
| Laravel backend foundation (Phase 2): env, PostgreSQL config, migrations, API envelope/errors/middleware, health checks, validation | Git repository (not yet initialized) |
| Identity and tenancy (Phase 3): orgs, facilities, users, token auth + refresh rotation, RBAC, tenant/facility context, authorization gates, audit | Database-level RLS (designed; app-layer isolation tested), MFA TOTP flow, CI, Docker, any deployment |
| Hospital administration (Phase 4): departments, locations, wards/rooms/beds, staff (license encrypted at rest), services catalog, facility configuration | IPD, emergency, laboratory, radiology, pharmacy dispensing, inventory, HR/assets, OT/ICU/blood, analytics, interoperability, telehealth, RPM, CDSS/AI, national scale |
| Patient Master (Phase 5): registration + MRN, encrypted identifiers with duplicate detection/merge, contacts, insurance, consents, documents, timeline | Any claimed feature, integration, or compliance status |
| First clinical workflow (Phase 6/7 + billing spine): schedules/availability, booking, check-in/queue, encounter, notes, diagnoses, prescriptions, sign, charges, invoices, payments | — |
| 201 tests / 1475 assertions green against real PostgreSQL | — |
| A development log recording only real work | — |

**Swasthya is a production healthcare platform under development.** The design contract is complete and honest; the platform foundation, identity/tenancy spine, hospital-administration catalogs, the Patient Master, and the first complete clinical workflow (patient → appointment → check-in → queue → encounter → documentation → diagnosis → prescription → billing → payment, all audited) are built and tested against real PostgreSQL, with the full vertical slice verified over live HTTP. The next milestones — M0 (ADR-001, repository initialization), Phase 3 hardening (RLS, MFA), and the roadmap phases from IPD onward — are recorded in `ROADMAP.md` and `DEVELOPMENT_LOG.md`.
