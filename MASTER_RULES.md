# MASTER_RULES.md — Swasthya Engineering Constitution

> **Status:** Ratified baseline · **Applies to:** every engineer, every pull request, every deploy
> **Version:** 1.0 · **Owner:** Principal Architect (ratified with the team)
> **Amendment process:** This document changes only through an Architecture Decision Record (ADR). No drive-by edits, no personal exceptions. If a rule costs more than it protects, propose the ADR — do not quietly ignore the rule.

This is the engineering constitution of **Swasthya**, a production-grade, nationally scalable, multi-tenant Hospital Management System (HMS) SaaS. It is a commercial healthcare product, not a demo, prototype, mockup, or college project. Every rule below exists because violating it endangers patients, hospitals, the business, or the team.

**Conflict rule:** When any instruction, habit, or convenience conflicts with this document, this document wins. Exceptions require a written ADR or written approval from the Principal Architect.

---

## 0. How to read this document

- **Must / Must not** = mandatory, CI- or review-enforced.
- **Should / Should not** = strongly expected; deviation needs a written justification in the PR.
- **May** = permitted choice.
- Each section lists the rule, the reason, and where possible the enforcement mechanism.

---

## 1. Product Scope

1.1. Swasthya is a multi-tenant HMS SaaS serving national-scale deployment: multiple organizations (tenants), multiple hospitals per organization, multiple branches/facilities per hospital.

1.2. **In scope:** patient registration and records, appointments, clinical encounters (EHR), billing, pharmacy, labs, inventory, staff management, reporting, and (later) interoperability, telehealth, RPM, and AI/CDSS.

1.3. **Out of scope — prohibited:** bespoke, hospital-specific code. Every difference between hospitals is configuration, data, or a product feature — never a fork, never a patch bolted onto one tenant's request path. A hospital that needs custom behavior opens a product request.

1.4. **No demo-only functionality.** Every feature shipped is a real feature with real tests, real observability, and a real owner. Nothing is built to look good in a screenshot and abandoned.

1.5. Every module must state its audience (patient, clinical staff, org admin, platform admin) and its single job. Features that cannot name their user and their job are not built.

1.6. Roadmap discipline: features enter the codebase only through the prioritized roadmap. No drive-by features in PRs.

---

## 2. Architecture

2.1. **Modular monolith behind one API.** All business logic lives in one deployable Laravel application, organized into bounded domains (identity, tenancy, clinical, billing, pharmacy, labs, inventory, reporting). Domain boundaries must be clean enough that a hot domain *could* become a service later — but splitting is only done when load or team structure demands it, never preemptively.

2.2. **API-first.** The frontend is a pure client of the API. No business rule is enforced only in the frontend; the API is the single source of truth.

2.3. **No logic duplication.** A capability is implemented exactly once, in exactly one owned technology (Section 3). Copying a rule from PHP into React or into a cron script is a violation.

2.4. New architectural patterns (queues, event sourcing, a new service, a new data store) require an ADR before production code.

2.5. Every request path must be traceable: request → authn → authz → tenancy → domain logic → persistence → response, with the layers in that order and no shortcuts.

---

## 3. Technology Ownership

3.1. The ownership table is fixed by ADR-001:

| Concern | Technology | Owns |
|---|---|---|
| All business logic, CRUD, workflows, tenancy, RBAC, billing, notifications, queues, audit | **Laravel (PHP)** | the entire product API |
| All client UI — patient portal and staff workspace | **React + TypeScript** | one SPA, role-based |
| All data | **PostgreSQL** | sole database |
| Cache, queues, sessions, realtime | **Redis** | nothing else |
| Files and medical documents | **S3-compatible object storage** | signed, encrypted objects |
| AI / CDSS inference (future only, when funded) | **Python (FastAPI)** | inference and model serving ONLY — zero business logic, no CRUD |
| Interoperability (future) | FHIR R4 projection layer inside Laravel; OAuth2/OIDC for external systems | exchange contracts |

3.2. **Excluded technologies:** CodeIgniter (never), MySQL/SQLite for production (never), a second frontend framework (never), session-cookie auth for the mobile API (never).

3.3. **One implementation per capability.** If two frameworks can do the same job, the owning technology from the table above does it. Introducing a new technology requires an ADR demonstrating the existing owner cannot do the job.

3.4. Never build the same functionality in two frameworks "just in case." Duplicated capability is a defect, not insurance.

---

## 4. Multi-Tenancy

4.1. Hierarchy: **Organization (the tenant) → Hospitals → Branches/Facilities → Departments.** The tenant is the paying customer; hospitals and branches are domain entities scoped inside the tenant.

4.2. **Every tenant-scoped table carries the tenant key.** The data model is reviewed for tenancy completeness before any table is created; a table missing its tenant key is a design failure, not a later fix.

4.3. **PostgreSQL Row-Level Security (RLS) is mandatory.** Tenant isolation is enforced at the database layer, so a bug in application code cannot leak data across tenants.

4.4. The tenant context is always derived from the authenticated principal by middleware — **never from a client-supplied header, query parameter, or body field**. The middleware validates the principal's membership in the requested tenant and sets the database session context (`app.tenant_id` via `SET LOCAL`) before any query runs.

4.5. Tenant switching re-validates membership every time. No cached tenant context outlives the request.

4.6. **Cross-tenant access is prohibited** at every layer: queries, jobs, queues, caches, files, and reports. Background jobs and queue payloads must be tenant-tagged and tenant-validated on execution.

4.7. The application layer must be written so the isolation strategy can escalate from single-DB RLS to schema-per-tenant without rewriting business logic. Tenant access goes through a central context abstraction, never ad hoc `WHERE tenant_id = ...` scattered through code.

4.8. The tenant provisioning flow (create org → hospitals → branches → admin roles → defaults) is a designed, tested flow. Onboarding a new organization is never done by hand-editing the database.

4.9. Cross-tenant isolation is proven by tests, not asserted (Section 16.4).

---

## 5. Database Integrity

5.1. PostgreSQL is the only production database. No embedded or file-based databases in production.

5.2. Primary keys are UUIDs. All timestamps are `timestamptz`. Money is stored as integers in minor units (Section 37).

5.3. Every relationship has a foreign key. Every column has a deliberate, explicit type — no generic catch-all strings for structured data.

5.4. Constraints (NOT NULL, CHECK, UNIQUE, FKs) are defined in migrations and enforced by the database, never only in application code.

5.5. Business code queries through the ORM / query builder with bound parameters. Raw SQL is prohibited in application code except through a vetted, reviewed, named abstraction (and never concatenated with user input).

5.6. **No `SELECT *`, no N+1 queries, no queries in loops.** Every feature's query plan is reviewed in code review.

5.7. `JSONB` is used only where the schema is genuinely variable (e.g., flexible clinical observations, config). Structured, queried data is columns — JSONB is not a replacement for a schema.

5.8. Soft deletes for clinical and financial records, with documented retention and purge schedules (Section 10). Hard deletes are prohibited for anything auditable.

5.9. Transactions wrap multi-step writes; business logic never commits half a transaction. Explicit transaction boundaries, not implicit per-query autocommit for compound operations.

5.10. Indexes are designed with the query, not added after the fact. High-volume tables (vitals, logs, audit, activity) have a partitioning plan before they exist.

5.11. No schema drift: migrations are the only way the schema changes; the schema in the repo always matches a fresh provisioned database.

---

## 6. Security

6.1. TLS 1.2+ everywhere, all the time. HSTS, CSP, strict security headers, and strict CORS on every response.

6.2. The security baseline is OWASP ASVS-aligned and documented in an ADR. Anything touching patient data, authentication, or payments gets a security review gate in addition to code review.

6.3. Encryption at rest and in transit. Sensitive patient identifiers are encrypted at the column level (e.g., pgcrypto) with keys held in a managed key store (KMS) — keys never in code or in the database itself.

6.4. All input is validated on the server. Unvalidated API input is prohibited (Section 12). Output is encoded for its context; no `innerHTML` with dynamic content (Section 13).

6.5. Rate limiting and account lockout on authentication endpoints. Brute-force protection is on by default, not bolted on after an incident.

6.6. Least privilege everywhere: service accounts, database roles, storage buckets, queue consumers. No shared superuser credentials.

6.7. Signed, expiring URLs for document access. Every document access is audited (Section 19).

6.8. Dependency and container-image vulnerability scanning run in CI and block merges on critical findings.

6.9. Any suspected or actual breach is an incident: contain, preserve evidence, notify per the incident runbook and legal obligations. Covering up is a firing offense, not a preference.

---

## 7. Authentication

7.1. **Token-based authentication** (short-lived access tokens with refresh-token rotation). No session cookies for the mobile/API clients.

7.2. Four identity classes from day one: **patients**, **clinical staff**, **organization administrators**, **platform superadmins** — each with its own credential, MFA, and session policy.

7.3. **MFA (TOTP) is mandatory for staff and administrators**, not optional and not "later." Patients may start without MFA, but the mechanism must make it cheap to require it per-tenant.

7.4. Password policy: minimum length and complexity enforced server-side; breached-password checking; no password hints; password resets invalidate all outstanding tokens.

7.5. Every authentication event is audited: success, failure, lockout, token issuance, refresh, revocation, password change, MFA enrollment (Section 19).

7.6. Tokens carry the tenant context and role scopes; token revocation (logout, password change, role change, offboarding) is immediate and effective everywhere.

7.7. No hardcoded credentials, no default passwords, no backdoor accounts — including in staging and dev (Section 29).

---

## 8. Authorization

8.1. **Defense in depth: policy layer + RLS.** Application-level authorization (Laravel policies/gates) decides what an action may do; database RLS guarantees tenant scope even if the policy layer fails. Both layers are mandatory; neither is optional.

8.2. Every endpoint and every domain action is authorized. There is no "trusted internal" endpoint that skips authorization.

8.3. Resource access is by ownership/scope, never by untrusted ID alone. Fetching a record by ID always checks that the caller may see that record in that tenant/hospital/branch.

8.4. The frontend hides controls for UX; the API enforces for security. Never rely on the frontend.

8.5. Authorization decisions are deterministic and testable: the same role × same resource × same context must always produce the same decision.

8.6. Bypassing authorization — even to "fix it quickly" or "make it work for a demo" — is prohibited and treated as a security defect.

---

## 9. RBAC

9.1. The role model is fixed and seeded, never invented ad hoc:

- **Platform:** superadmin
- **Organization:** org admin, org finance
- **Hospital/branch:** hospital admin, branch manager, receptionist, billing clerk
- **Clinical function:** doctor, nurse, pharmacist, lab technician
- **Patient:** self-service portal role

9.2. Roles carry both **what** they may do and **which scope** they may do it in (org / hospital / branch / record). A doctor's permissions are scoped to their hospital and their assigned patients.

9.3. Permission checks live in policies/gates at the domain layer — not scattered `if` statements in controllers, and never frontend-only checks.

9.4. Least privilege: the default is no access; roles are granted deliberately. Role changes are audited, and role changes take effect immediately (session/token scopes refreshed).

9.5. New permissions require a test in the authorization matrix (Section 16.5). A permission that cannot be tested is not added.

9.6. Staff access to patient data is need-to-know and scoped; broad "see everything" roles exist only for explicitly sanctioned audit/support functions and are themselves audited.

---

## 10. Healthcare Data Protection

10.1. Patient data is the highest-sensitivity data in the system. It is treated as confidential at every layer: storage, transit, logs, cache, backups, analytics, and support tooling.

10.2. **Consent and purpose limitation are first-class domain objects.** A patient's data may be used for the purpose it was collected (treatment, billing, the hospital's lawful operations) and no other. Marketing and secondary use require separate consent. Consent changes are versioned and audited.

10.3. Data minimization: collect what the workflow needs, nothing more. No speculative data collection.

10.4. Retention and deletion are scheduled and enforced per compliance and per-tenant policy: clinical records keep for the legally required period, then are purged or anonymized on schedule. Deletion is never skipped because "it's work."

10.5. **No PHI in logs, ever** — not in application logs, not in error traces, not in metrics labels, not in support tickets (Section 18).

10.6. National identifier linkage (e.g., NPRN) is handled with explicit consent and purpose-limitation rules; identifiers are encrypted at rest and not exposed in APIs that don't need them.

10.7. Analytics and reporting use anonymized/aggregated data where possible. Any report that could identify an individual requires justification and access control.

10.8. Every access to a patient record by staff is auditable (Section 19). Patients can be told, on request, who accessed their record and when.

10.9. Compliance with Nepal's privacy law (2075) and applicable health-sector regulation is a launch requirement, reviewed by qualified counsel before production go-live — not a post-launch project.

---

## 11. Clinical Safety

11.1. **No unsafe clinical automation.** Anything that changes a clinical workflow (orders, results, dosing suggestions, discharge criteria, alerts) has a human-in-the-loop review. Automation assists; it does not decide.

11.2. Critical clinical values (lab results, vitals, drug administration) use double-entry or explicit confirmation where the risk justifies it, and always a full audit trail.

11.3. Clinical failures fail **loudly**: a failed result transmission, a failed order, a failed alert is escalated, retried, and surfaced to a human — never silently swallowed.

11.4. Clinical rules (alerts, flags, calculations) are versioned, reviewed by clinical authority before release, and documented. Unreviewed clinical logic is prohibited.

11.5. Any AI or decision-support output is labeled as assistive and shows its limits; it is never presented as prescriptive without clinician review (Sections 33–34).

11.6. Clinical incidents (wrong value shown, missed alert, data entered against the wrong patient) have a reporting and correction path that is used without blame. The system must make "wrong patient" mistakes hard: identity confirmation is designed into workflows.

11.7. Nothing in the product is ever allowed to claim a clinical capability it does not have.

---

## 12. API Design

12.1. RESTful JSON API under versioned paths (`/api/v1`). The frontend consumes only this API.

12.2. A single, documented response envelope: consistent success shape, error shape with machine-readable codes, human-readable message, and field-level validation errors. No ad hoc response shapes per endpoint.

12.3. Consistent conventions: kebab-case URLs, camelCase JSON fields, ISO-8601 timestamps, UUID identifiers, standardized pagination, filtering, and sorting — documented once in the API contract and followed everywhere.

12.4. **Idempotency keys are mandatory on every request that creates or mutates financial or clinical records** (charges, payments, encounters, orders) so retries cannot double-charge or double-document.

12.5. All API input is validated server-side against explicit rules (types, formats, ranges, referential validity). Unvalidated input is prohibited; validation failures return structured errors, never a 500.

12.6. OpenAPI 3.1 documentation is generated from code and is part of the definition of done for every endpoint. **Undocumented APIs are not shipped** (Section 40).

12.7. Versioning policy (Section 31): additive changes within a version; breaking changes require a new version with a deprecation window.

12.8. Deprecated endpoints keep working through the deprecation window, return deprecation headers, and are removed only after the announced date.

---

## 13. Frontend Architecture

13.1. **One React + TypeScript SPA** for all clients (patient portal and staff workspace), with role-based routing. No second frontend application for the same capability.

13.2. **Direct database access from the frontend is prohibited.** The frontend talks to the API, period — no direct connection strings, no exposed Postgres credentials, no client-side SQL.

13.3. Feature-folder structure: each feature owns its components, hooks, and styles; shared code lives in a shared layer. No god-components, no feature code hiding in shared.

13.4. TypeScript in strict mode. The API contract types are generated from the OpenAPI spec so the frontend and backend cannot drift silently.

13.5. Server state lives in the API layer's data-fetching discipline (query caching, loading/error states); client state holds only UI state. The frontend never re-implements business rules from the backend.

13.6. A single design system (tokens, components, spacing, type) used by both portal and workspace — no parallel ad hoc styling.

13.7. No `innerHTML`/`dangerouslySetInnerHTML` with dynamic content; no unescaped user data in the DOM. React escapes by default; never opt out for anything that includes user input.

13.8. Bundle size and performance budgets are enforced (Section 14.3); regressions block merge.

---

## 14. Mobile-First Design

14.1. Design and build mobile-first: smallest supported viewport first, then up. Every screen is designed, built, and tested at mobile width before desktop.

14.2. Touch targets ≥ 44×44 px; no hover-dependent functionality; thumb-reachable primary actions; text remains legible without zoom.

14.3. Performance budgets are enforced in CI: LCP under the budget on mobile emulation, and a maximum bundle size. Performance regressions block merge.

14.4. The product is an installable PWA where it adds real value. Offline behavior is limited to safe, read-only caching; nothing that mutates clinical data happens offline without a designed reconciliation flow.

14.5. Real-device testing on representative low- and mid-range Android and iOS devices before every release; emulator-only verification is not sufficient for release.

14.6. Forms and workflows are tested at mobile width end-to-end, including keyboards, autofill, and interrupted-network behavior.

---

## 15. Accessibility

15.1. **WCAG 2.1 AA is the minimum** for every screen, patient and staff.

15.2. Full keyboard operability; visible focus indicators; logical focus order; no focus traps. Nothing is reachable only by mouse or only by touch.

15.3. Color contrast meets AA; information is never conveyed by color alone.

15.4. Semantic HTML and correct ARIA where needed; labels on every form control; proper landmarks and headings.

15.5. `prefers-reduced-motion` is respected; motion is never essential to understanding.

15.6. Automated accessibility checks run in CI (axe-style scans on rendered screens) and block merge. Manual screen-reader review is required for every critical flow before release.

15.7. Accessibility regressions are defects, treated with the same severity as functional regressions.

---

## 16. Testing

16.1. Test pyramid: many fast unit tests, fewer feature/contract tests, a small number of end-to-end tests for critical flows. Every critical workflow is tested end-to-end (Section 40).

16.2. **Backend:** Pest/PHPUnit — unit tests for domain logic; feature tests per endpoint against real PostgreSQL (never SQLite in-memory; RLS behaves differently). Factories for all seeding.

16.3. **Frontend:** Vitest + Testing Library for unit/component; Playwright for end-to-end against a seeded environment.

16.4. **Mandatory test suites:**
- **Cross-tenant leakage suite:** attempts to read, write, and reference another tenant's data at the API and database layer; proves RLS holds.
- **Authorization matrix:** every role × every endpoint/action, asserting allow/deny.
- **Billing suite:** idempotency, double-entry, refunds, proration, tax (Section 37).
- **Clinical safety suite:** the loud-failure and wrong-patient protections (Section 11).

16.5. Tests run in CI on every pull request against the same PostgreSQL/Redis topology as production. Flaky tests are fixed or deleted immediately; re-running a flaky test until green is prohibited.

16.6. Coverage floors apply to critical modules (tenancy, auth, RBAC, billing, clinical); unreviewed drops in coverage block merge.

16.7. Tests assert behavior, not implementation. No tests that only assert the code they call, no snapshot sprawl, no tests that pass because they never run.

16.8. Untested critical workflows do not ship. "We tested it manually" is not a substitute for an automated test in the repo.

---

## 17. Error Handling

17.1. Errors are structured and typed: machine-readable error codes, human-readable messages, field-level validation errors where applicable. Clients can distinguish validation, authorization, not-found, rate-limit, and server errors.

17.2. **Never leak stack traces, SQL, internal paths, or PHI to clients.** Internal detail goes to logs with a correlation ID; the client gets the safe envelope.

17.3. Clinical and financial paths fail loudly (Section 11.3): failures are logged, alerted, and surfaced to a human operator — never silently swallowed or caught-and-ignored.

17.4. Graceful degradation: partial failures (a report provider down, a third-party unavailable) degrade a screen or flow with a clear message and a retry path; they do not blank the page or wedge the workflow.

17.5. Every request carries a correlation ID from entry to logs, from API to database to queue (Section 18.2). Error responses and logs share it so a user's report can be traced.

17.6. The frontend renders actionable errors: what happened, what the user can do, and a path to support. Error messages are written for humans, in plain language, in the interface's voice.

---

## 18. Logging

18.1. Structured JSON logs everywhere (backend, workers, frontend client errors). No prose-only log lines, no logging to files in production (logs go to the central pipeline).

18.2. **Correlation ID end-to-end:** generated at the request edge, carried through the API, workers, queue jobs, and outbound calls. Any log line can be tied to its request.

18.3. Log levels are disciplined: debug in dev, info for meaningful lifecycle events, warning for anomalies, error for failures. Logging volume is reviewed; log noise that buries real signals is a defect.

18.4. **No PHI in logs** (Section 10.5), no secrets, no tokens, no full credit-card or identifier values. Log scrubbing rules are enforced and reviewed.

18.5. Logs have defined retention; dev logs never ship to the production pipeline; local development never writes to production log destinations.

---

## 19. Audit Logging

19.1. **Audit logging is append-only and central** — a dedicated mechanism, not scattered `logger->info` calls in controllers.

19.2. Every auditable event records: who (actor), what (action), on what (resource + identifier), in which tenant/hospital/branch, when, from where (IP, device), and the outcome. Event payloads are versioned so history stays interpretable.

19.3. Audited domains — at minimum: authentication (Section 7.5), authorization denials on sensitive resources, clinical record reads and all clinical mutations, billing and financial mutations, role and permission changes, consent changes, data exports, document access (Section 6.7), tenant provisioning and offboarding, admin actions, and AI/CDSS actions (Section 33).

19.4. The audit trail is tamper-evident (hash-chained or written to an append-only external store) and cannot be edited or purged by application code. Audit data is never stored only in the same table it describes.

19.5. Audit logs have retention per compliance requirements and are included in backups and DR (Section 22–23). Losing the audit trail is a production incident.

19.6. Audit events are written synchronously for clinical and financial actions where the event is part of the transaction's correctness; asynchronously only where acceptable and never for the high-sensitivity classes.

---

## 20. Observability

20.1. Every service exposes metrics, traces, and structured logs (OpenTelemetry-based). A request that disappears from traces is a defect.

20.2. Health endpoints (liveness/readiness) exist for every component and are used by load balancers and orchestrators.

20.3. Alerting with real thresholds and owners: every alert has an owner, a severity, and a runbook link. Alert storms are tuned; alert fatigue that causes ignored alerts is a defect.

20.4. SLOs are defined for the critical user journeys (login, record retrieval, booking, billing) with error budgets, reviewed on a cadence.

20.5. Tenancy isolation is observable: metrics and alerts for anomalies that could indicate cross-tenant access (unusual tenant patterns, denied-access spikes).

20.6. Error tracking (production stack traces with correlation IDs) and synthetic checks on critical journeys exist before the first production release.

20.7. Dashboards exist for the platform team (traffic, errors, latency, queues, backups) and are part of the definition of done for any new service.

---

## 21. Deployment

21.1. **Zero-downtime deploys only.** Production is never taken down for a release; releases are rolling/blue-green with health checks.

21.2. Deploys happen only through CI/CD. Manual deploys to production are prohibited (hotfixes go through the hotfix process, still via CI/CD).

21.3. Staging mirrors production (versions, config, topology); nothing ships to production that has not been green in staging. Promotion rules: staging green → release candidate → production.

21.4. Migrations are release-based and backward-compatible (Section 30); the deploy runs migrations as a distinct, monitored step with a rollback plan.

21.5. Every release has a rollback plan that is documented and rehearsed. Releases without a rollback path do not happen.

21.6. Deploy windows and change freezes are documented and respected. A release in a freeze requires written approval.

21.7. Release notes are written from the user's perspective and published with the release (Section 25.5).

---

## 22. Disaster Recovery

22.1. **Targets (default):** RPO ≤ 15 minutes, RTO ≤ 4 hours for the production platform. These are ratified with the business and reviewed annually; lower is better where affordable.

22.2. Production runs across multiple availability zones; no single component is a single point of failure without a documented, accepted risk.

22.3. A written DR runbook exists and is exercised: **restore drills run at least quarterly** — actually restore backups into a clean environment and verify critical journeys, not just "the backup file opens."

22.4. A failover test (primary database / primary region) is executed at least annually with evidence.

22.5. Off-site or second-region copies of backups exist so a regional event cannot destroy both the system and its recovery data.

22.6. DR is not someone's private knowledge — the runbook, contact list, and credentials access path are documented and current.

---

## 23. Backups

23.1. Backups are automated, monitored, and tested. An unattended, untested backup is not a backup.

23.2. PostgreSQL: point-in-time recovery (WAL archiving) plus scheduled full/periodic backups. Backup success/failure is monitored; a failed backup alerts within the hour.

23.3. Backup storage is encrypted, isolated from production credentials, and retained per compliance policy (including audit logs, Section 19.5).

23.4. Restore is proven: quarterly drills restore into a clean environment and verify data integrity and critical journeys (Section 22.3).

23.5. Backups respect tenancy: restoring must not mix tenants, and offboarding a tenant includes purging that tenant's data from backups per the retention schedule.

23.6. Object storage (documents) has its own versioning/replication policy; document loss is a patient-safety event, not just an ops issue.

---

## 24. Version Control

24.1. Git, with **`main` protected**: no direct pushes, force-push prohibited, all changes land via reviewed pull requests. Branch naming: `feature/<slug>`, `fix/<slug>`, `chore/<slug>`, `hotfix/<slug>`.

24.2. **Conventional commits** (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `test:`, `perf:`, `build:`, `ci:`) with concise, factual messages. Commit messages describe the change and its why, not a play-by-play.

24.3. Commits are atomic: one logical change per commit; no mixed refactor-and-feature commits; no "wip" commits merged into main.

24.4. Lockfiles are committed. Generated artifacts, build output, local env files, and secrets are never committed (`.gitignore` enforced, CI checks for stray files).

24.5. Large binaries and datasets never enter the repository (stored in object storage with references).

24.6. Rebase to keep history clean; never rewrite pushed history on shared branches.

24.7. Deleting useful existing code requires justification in the same PR that deletes it (Section 29 of the prohibitions — see Prohibited Practices P.13).

---

## 25. Documentation

25.1. **ADRs for decisions.** Architecture, tenancy, security, and tech choices are recorded as ADRs with context, decision, and consequences. Undocumented decisions are indistinguishable from accidents.

25.2. Every module/package has a README: what it does, how to run it, how to test it, and its owner.

25.3. API documentation is generated from code (Section 12.6) and ships with every endpoint.

25.4. **Docs update in the same PR as the code.** A PR that changes behavior without updating the relevant docs is incomplete. Stale docs are a defect; a docs bug is a code review finding like any other.

25.5. Runbooks exist for every operational procedure (deploys, restores, incidents, onboarding a tenant) and are kept current.

25.6. A release changelog is maintained and user-facing changes are described in plain language.

---

## 26. Code Quality

26.1. Linting and formatting are enforced by CI (PHP: Pint/PHPStan at the maximum practical level; TS: ESLint + Prettier, `tsc` strict, no `any` escapes).

26.2. **No dead code**: no unused imports, unreachable branches, commented-out blocks, or speculative abstractions. Unused code is deleted, not commented out.

26.3. Code review requires: small PRs (one logical change, reviewable in one sitting), no drive-by features, no merged PRs with unresolved review threads.

26.4. Complexity is kept reviewable: prefer small functions, explicit names, and simple data flow. A function that needs a paragraph to explain is a candidate to split.

26.5. Naming rules (Section on process rules below): names say what things are; no `data`, `temp`, `utils`, `misc`; file names match their primary export.

26.6. Every public API surface (method, endpoint, component) has a doc comment or contract — code that cannot be understood from reading is not done.

---

## 27. Dependency Management

27.1. Dependencies are declared and locked (Composer lock, package-lock). Installations in CI use the locked versions.

27.2. **Minimal dependencies:** add a dependency only when the owning technology cannot do the job with reasonable effort; each addition is justified in the PR. No "kitchen-sink" installs.

27.3. Abandoned, unmaintained, or pre-1.0-critical-path dependencies are prohibited without an ADR.

27.4. **Security audit in CI:** `composer audit` / `npm audit` (or equivalents) run on every PR; critical and high vulnerabilities block merge. License checks run as well.

27.5. Upgrade discipline: patch/minor updates on a cadence (automated where safe); major upgrades are deliberate, tested, and reviewed. Unreviewed major upgrades do not land.

27.6. A dependency with a known critical vulnerability in production is an incident, not a backlog item.

---

## 28. Environment Management

28.1. **Dev / staging / production parity:** same stack, same PostgreSQL version, same topology shape. Bugs that exist only in staging or only in prod are deployment defects.

28.2. Environment configuration comes from environment variables / the secrets store — never hardcoded in code. `.env.example` is committed with placeholders only; real values never enter the repository.

28.3. Environment-specific behavior lives in configuration, not in scattered conditionals (`if (app()->environment('production'))` in business code is a code smell and prohibited for behavior changes).

28.4. A fresh clone plus `.env.example` plus the documented setup script must produce a working dev environment with no tribal knowledge.

28.5. Dev and staging data are synthetic, never production data, unless explicitly sanctioned for a tested, access-controlled purpose (Section on fake data, P.1).

---

## 29. Secrets Management

29.1. **Secrets never live in source code, in committed `.env` files, in images, in CI logs, in chat, or in docs.** Hardcoded credentials are prohibited in every environment.

29.2. Secrets live in the managed secrets store (KMS / secrets manager) with least-privilege access; the app reads them at runtime.

29.3. Rotation: credentials and keys rotate on a schedule; leaked secrets are revoked and rotated immediately, and a leak is reported as an incident even if "nothing happened."

29.4. CI scans for committed secrets and blocks merges when found. A committed secret is revoked regardless of whether it was "only staging."

29.5. Service accounts get scoped credentials, never shared master keys.

---

## 30. Data Migration

30.1. Migrations are code, reviewed like code, and the only way the schema changes.

30.2. **Forward-only:** applied migrations are never edited. Corrections are new migrations. Rewriting history on a shared environment is prohibited.

30.3. **Backward-compatible migrations** for zero-downtime deploys: expand/contract patterns (add nullable column → backfill → deploy code → tighten). Locking migrations and destructive changes on live tables require a plan and a review.

30.4. Data fixes (correcting bad data) run through reviewed migrations or dedicated, approved scripts — never ad hoc SQL against production.

30.5. Every migration runs cleanly on a fresh database (CI builds one) and in staging before production. Rollback paths are considered even where rollback migrations are not written.

30.6. Migrations touching clinical or financial data get extra review: they are reversible-in-spirit, logged, and audited.

---

## 31. API Versioning

31.1. The API is versioned in the URL (`/api/v1`). The version is part of the contract.

31.2. **Additive changes are allowed within a version** (new endpoints, new optional fields). **Breaking changes require a new version.**

31.3. Every new version announces a deprecation window (minimum 6 months default) during which the old version keeps working, returns deprecation headers, and is documented as deprecated.

31.4. A changelog documents every endpoint change per version. Clients pin versions; the platform documents supported versions.

31.5. Breaking change checklist: announce, document migration path, monitor old-version traffic, remove only after the window closes.

---

## 32. Interoperability

32.1. Standards-first: where a standard exists (FHIR R4 for clinical exchange, HL7 where the ecosystem demands it), the exchange layer speaks it — via a mapping/projection layer inside Laravel, never by bending the internal schema to the standard prematurely.

32.2. **Never claim an integration or interop capability that is not tested and demonstrated.** P.16 applies with full force here.

32.3. Interop endpoints are versioned, authenticated with OAuth2/OIDC scoped tokens, and audited like any clinical access.

32.4. External exchange is explicit-consent aware and compliant with the data-protection rules (Section 10).

32.5. Mapping layers (internal → FHIR) are contract-tested against fixture payloads; mapping drift is a defect.

---

## 33. AI Safety

33.1. **AI output is assistive, never autonomous** for clinical or financial decisions. Every AI result that reaches a clinician is labeled as AI-generated, shows its confidence/limits, and requires human review before acting.

33.2. Human-in-the-loop is non-negotiable: no AI feature may take a clinical action without a human decision, and no AI feature may present itself as authoritative.

33.3. AI features are evaluated before release (accuracy, bias, failure modes) with evidence in the PR/ADR; unvalidated AI does not ship. Rollback and flagging-off are always possible via feature flags (Section 38).

33.4. Training data provenance: models are trained only on data we may use, with consent and compliance considered; **no patient data is sent to unapproved external models or APIs.**

33.5. AI actions are audited (input, output, who reviewed, what was decided). An AI feature without an audit trail is not a feature.

33.6. When AI/CDSS arrives, it is a Python FastAPI inference service (Section 3.1) — inference only, no business logic, no CRUD, versioned models, deterministic-enough outputs for testing.

---

## 34. CDSS Safety

34.1. Clinical decision support (alerts, rules, calculators, suggestions) is evidence-based: each rule cites its source and is versioned, with the version visible in the audit trail.

34.2. Rules are reviewed and signed off by clinical authority before release; unreviewed clinical rules are prohibited (Section 11.4).

34.3. Clinicians can override, and overrides are recorded and analyzed — the override path is a first-class workflow, not a workaround.

34.4. Alert discipline: alerts are tiered by severity and tuned to avoid fatigue; alert fatigue that trains users to ignore warnings is a safety defect, not a metric to maximize.

34.5. Safety testing includes false-positive and false-negative analysis, and wrong-patient/wrong-dose scenarios, before release.

34.6. CDSS never silently auto-applies. Anything a clinician would be legally and ethically responsible for is confirmed by a clinician.

---

## 35. Third-Party Integrations

35.1. Every integration (payment provider, SMS, email, lab interfaces, telehealth, government systems) has: a named owner, contract tests, documented status, and a kill-switch (feature flag or circuit breaker).

35.2. **Assume third parties fail:** every outbound call has timeouts, retries with backoff, circuit-breaking, and a defined degraded mode (Section 17.4). An integration that hangs a request is a bug in our code.

35.3. **No fake integrations in production.** A stub, mock, or "simulated" provider that looks real is prohibited. Integrations are wired to real endpoints with real credentials in staging and production (P.14).

35.4. Credentials for third parties live in the secrets store (Section 29), scoped per integration.

35.5. Data mapping to/from third parties is validated with fixtures and contract tests; payload drift is detected, not discovered by a production incident.

35.6. An integration's actual status (live, degraded, down) is visible on a status page — never claimed green because it isn't monitored.

---

## 36. SaaS Tenancy

36.1. Tenancy economics: shared infrastructure with isolated data (Section 4). Tenancy is a product capability, not an afterthought — every feature is designed, built, and tested for multiple tenants from the first commit.

36.2. Onboarding and offboarding are designed flows: provisioning (org → hospitals → branches → roles → defaults), and offboarding (export per policy → purge per retention law → revoke access) — both tested, both audited.

36.3. Tenant-level usage metering exists so the business can bill per usage where agreed; metering is accurate and auditable, never fabricated (P.15).

36.4. Shared services (queues, caches, workers) tag and validate tenant context on every job; a job must never process another tenant's data because it picked up the wrong context (Section 4.6).

36.5. The escalation path (schema-per-tenant for enterprise/compliance demands) is documented and the app layer is written so it is possible without rewriting business logic (Section 4.7).

36.6. Tenant data is deleted on offboarding per the retention schedule — including from caches, queues, and backups per policy (Section 23.5).

---

## 37. Billing

37.1. **Money is stored as integers in minor units** (e.g., paisa/cents) — never floats, never strings. Currency is explicit per tenant (Nepal: NPR, with VAT 13% configuration).

37.2. Every financial write carries an idempotency key and is audited (Sections 12.4, 19.3). Double-charging or double-documenting is a critical defect.

37.3. Double-entry bookkeeping: every transaction balances; reconciliation runs daily and discrepancies alert immediately. Silent billing failures are prohibited — a failed charge is escalated, retried, and surfaced (Section 17.3).

37.4. Refunds, proration, and adjustments follow documented, reviewed rules with a full audit trail. No ad hoc "fix the books" mutations.

37.5. Tax (VAT) configuration is data, per tenant, versioned; tax changes are audited and go through review.

37.6. Payment providers are third-party integrations (Section 35): contract-tested, kill-switchable, with credential handling per Section 29. Receipts and invoices are generated from ledger truth.

37.7. **Never invent invoices, receipts, or charges.** Every financial artifact traces to a real, audited transaction.

---

## 38. Feature Flags

38.1. Features that touch clinical, financial, or tenancy behavior ship behind feature flags with a rollout plan and a kill-switch that actually works.

38.2. Flags are configuration (versioned, environment-aware), evaluated server-side where behavior is security- or data-relevant. Client-side flags never gate a security decision.

38.3. **Flags are short-lived:** every flag has an owner and a removal checklist; flags live in code until the rollout is complete, then are removed. Flags are not permanent branches of logic.

38.4. Flag state is auditable and visible in config/logs; a flag that flips behavior silently with no trace is a defect.

38.5. Every feature flag is tested in both states (on and off).

---

## 39. Production Readiness

39.1. **No production release without the readiness checklist green:**

- [ ] All CI green (lint, static analysis, tests, scans, a11y, performance)
- [ ] Security review done where the change touches PHI, auth, or money
- [ ] Backups verified and restore drill current (Section 22–23)
- [ ] Observability: dashboards, alerts with owners, error tracking for the new surface
- [ ] Runbooks updated for any operational change
- [ ] Rollback plan documented and rehearsed
- [ ] Staging deployed and green; release candidate promoted via CI/CD
- [ ] Secrets rotated/configured via the store — no new secrets in code
- [ ] Release notes and changelog written
- [ ] Definition of Done (Section 40) met

39.1a. **Drill evidence is a Phase 22 standing item:** `NATIONAL_SCALE.md`
records measured load (1M patients, reference cluster), a national-scale
restore drill (34 s backup / 104 s restore, isolation re-verified 1/0/0),
and a failover-readiness drill (app serves from standby, `health/ready`
ok). Production-scale RPO/RTO/SLO and multi-region cutover remain
**NOT PROVEN** until the deployment environment's drills record them
(`DISASTER_RECOVERY.md` §13.1); no release may claim them earlier.

39.2. The checklist is enforced by the release process, not by memory. A release missing a checklist item is aborted or explicitly approved in writing by the architect.

39.3. Post-release: the release owner watches alerts and metrics for the defined soak window; a regression that reaches production is rolled back or hotfixed per the incident process, and a postmortem follows (blameless, with actions).

---

## 40. Definition of Done

A task, story, or feature is **Done** only when **all** of the following hold:

- [ ] **Scope:** matches the agreed requirement; no drive-by additions; no demo-only remnants (P.13)
- [ ] **Code:** reviewed and approved; lint/format/static analysis clean; naming and structure per this document
- [ ] **Tests:** critical-path tests present and green (tenancy, authz, billing, clinical where applicable); no untested critical workflow (P.9); no flaky tests
- [ ] **Docs:** ADRs/READMEs/API docs updated in the same PR; no stale docs introduced
- [ ] **API:** versioned correctly; documented (OpenAPI); validated input; error contract honored (Sections 12, 17)
- [ ] **Security:** authz enforced at policy + RLS layers; no secrets; no PHI in logs; scan clean
- [ ] **Tenancy:** tenant-scoped and RLS-covered; leakage tests pass; onboarding/offboarding considered
- [ ] **Audit:** auditable events are audited; the trail cannot be bypassed by this feature
- [ ] **Observability:** logs/metrics/traces present; alerts owned; failure modes degrade gracefully and loudly
- [ ] **Accessibility:** WCAG 2.1 AA; keyboard + focus + contrast verified; a11y checks green
- [ ] **Mobile-first:** built and tested at mobile width; performance budgets met
- [ ] **Flags/rollout:** feature flags with kill-switch where relevant; rollout plan recorded
- [ ] **Deployment:** staging green; zero-downtime; rollback path; release notes written
- [ ] **Production readiness checklist (Section 39) complete**

If any box is unchecked, the work is **not done** — it is in progress.

---

## Prohibited Practices

The following are prohibited unconditionally. Violations are defects or incidents depending on severity, and they are caught in review or CI:

**P.1 — Fake data in production.** No synthetic, mock, or placeholder data in production environments. Dev/staging use synthetic data only (Section 28.5). Production data is real and traceable.

**P.2 — Hardcoded business logic.** Business rules (pricing, tax, eligibility, clinical rules, tenancy scoping) are configuration/data or domain code — never literals scattered in controllers, frontend components, or SQL strings.

**P.3 — Hardcoded credentials.** No usernames, passwords, tokens, API keys, or connection strings in source, config files, images, or docs (Section 29). Not even "temporary" ones, not even in staging.

**P.4 — Bypassing authorization.** No skipping policy checks, no `authorize` bypass flags, no internal endpoints without authz, no "trusted client" shortcuts (Section 8.6).

**P.5 — Cross-tenant data access.** No code path, job, query, or report that can read or write outside its tenant context (Section 4.6). This includes caches, queues, and object storage.

**P.6 — Direct database access from the frontend.** No DB credentials, SQL, or ORM usage in the browser (Section 13.2).

**P.7 — Unvalidated API input.** Every endpoint validates all input server-side. Missing validation is a defect that blocks merge (Sections 6.4, 12.5).

**P.8 — Undocumented APIs.** Endpoints ship with generated OpenAPI documentation and contract types, or they do not ship (Sections 12.6, 40).

**P.9 — Untested critical workflows.** Authentication, tenancy, authorization, billing, and clinical flows always have automated tests (Section 16.4). "Manual testing" is not a substitute.

**P.10 — Unsafe clinical automation.** No clinical decision, order, or alert acts without human-in-the-loop, loud failure, and audit (Sections 11, 33, 34).

**P.11 — Storing secrets in source code.** See P.3; also prohibits secrets in comments, fixtures, seed files, and screenshots.

**P.12 — Deleting useful existing code without justification.** Deletions are explained in the same PR: what was removed, why, and what replaces it (Section 24.7). Deleting code to "clean up" without a purpose is prohibited; deleting code that is in use without review is prohibited.

**P.13 — Building demo-only functionality.** No screens, endpoints, or modules built for screenshots/demos that are not real features with real tests and real observability (Section 1.4).

**P.14 — Creating fake integrations.** No stubs or simulated providers wired into staging/production that pretend to be real (Section 35.3).

**P.15 — Creating fake analytics.** No fabricated metrics, dashboards, usage numbers, or billing meters. Analytics reflect observed reality; metering is accurate (Section 36.3).

**P.16 — Claiming an integration works when it does not.** Integration status is measured, not asserted (Sections 32.2, 35.6). A green status page without monitoring is a lie.

---

## Process Rules

### Naming

- **Files:** kebab-case for configs and assets; PascalCase for React components (file = component name); snake_case for PHP classes matching PSR-4; descriptive names — no `utils.js`, `data.ts`, `misc.php`.
- **Database:** snake_case tables (plural), `tenant_id` convention, clear FK names (`<table>_id`); indexes named for their purpose.
- **API:** kebab-case URLs, camelCase JSON fields, REST resource names (Section 12.3).
- **Branches:** `feature/<slug>`, `fix/<slug>`, `chore/<slug>`, `hotfix/<slug>`.
- **Commits:** Conventional Commits (Section 24.2).
- **Environment variables:** `SWASTHYA_` prefix for app-specific vars, uppercase snake_case.
- **Roles/permissions:** singular, lower_snake_case, namespaced by domain (e.g., `encounter:create`).

### Folder structure

Monorepo with exactly these top-level domains:

```
backend/        # Laravel — the sole API
  app/          # domain code (controllers thin, services/domain own logic)
  database/     # migrations, seeders, factories
  routes/
  tests/
frontend/       # React + TypeScript — the sole SPA
  src/          # features/, shared/, app/
docs/           # ADRs, architecture, API contract, runbooks, compliance
infra/          # Docker, IaC, CI/CD, deployment configs
```

New top-level directories require an ADR or architect approval. Feature code lives in its feature folder — no cross-feature shared-logic dumping grounds.

### Commits

Atomic, conventional, one logical change each (Section 24.3). Never commit generated files, secrets, or WIP. Messages state the why, not the play-by-play.

### Branches

`main` is protected and always releasable. Work happens on short-lived branches from `main`; branches are merged via PR and deleted after merge. No long-lived feature branches without justification.

### Migrations

Reviewed like code, forward-only, backward-compatible, fresh-database-clean (Section 30). Clinical/financial migrations get extra review.

### Pull requests

- Every change lands via PR. Template includes: what/why, testing evidence, docs updates, checklist self-assessment (Sections 39–40).
- Small and focused: one logical change; large PRs are split.
- Required checks must pass: CI (lint, static analysis, tests, scans, a11y, performance), at least one approving review, no unresolved threads.
- Security/tenancy/clinical changes require a second reviewer; billing/clinical changes require domain review.

### Code reviews

- Review for: tenancy correctness, authorization coverage, security, tests, docs, observability, naming, dead code, and whether the PR's claim matches its tests.
- Reviews are timely and specific; findings are threads, not lectures. Blocking findings block merge; the author resolves or escalates — "resolve without discussion" is prohibited.
- No rubber stamps: an approving review asserts the reviewer actually verified the change.

### Tests

Mandatory suites (Section 16.4) always exist. Tests run in CI on real PostgreSQL/Redis. Flaky tests are fixed or deleted. Untested critical workflows do not ship.

### Documentation updates

Docs change in the same PR as code (Section 25.4). ADRs for decisions; READMEs for modules; OpenAPI for APIs; runbooks for operations; changelog for releases.

### Release management

- Semantic versioning; releases cut from `main` via CI/CD only; staging green → production (Section 21).
- Hotfixes: branch from the release tag, minimal fix, tested, deployed via CI/CD, merged back — never a manual production edit.
- Release notes in plain language for users; changelog maintained (Section 25.6).
- Each release runs the production-readiness checklist (Section 39) and a post-release soak with an owner.

---

## Enforcement Summary

| Rule class | Enforced by |
|---|---|
| Code quality, lint, formatting | CI (Pint/PHPStan, ESLint/Prettier, tsc strict) |
| Tests, coverage, scans | CI (PHPUnit/Pest, Vitest, Playwright, composer/npm audit) |
| Tenancy, authz, security behavior | Mandatory test suites + review gates + RLS (database layer) |
| Secrets in repo | CI secret scan + review |
| Docs with code | PR review (DoD checklist) |
| Deployments | CI/CD pipeline only; readiness checklist |
| Architecture and technology changes | ADR process + architect approval |
| Process violations | Code review findings; repeat offenders escalate to architect/lead |

**Last resort:** any rule can be amended — by ADR, with written rationale. What cannot happen is a silent exception. If a rule is wrong, change it in the light; if it is right, follow it.

*This document is the engineering constitution of Swasthya. It exists to protect patients, hospitals, the business, and the team — and it is followed on every PR, every deploy, and every decision.*
