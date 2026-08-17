# DEVELOPMENT_LOG.md — Swasthya Engineering Log

> **Status:** Permanent · **Owner:** The engineering team (append-only)
> **Purpose:** A permanent, chronological record of the engineering work performed on Swasthya. This log is the project's memory: what was done, why, what it touched, and what it left behind.
>
> **Honesty rules (non-negotiable):**
> 1. **Only record work that has actually been performed.** Nothing is logged before it is done; no placeholder entries, no aspirational entries.
> 2. **Entries are chronological and append-only.** The past is never rewritten; a correction is a *new* entry that references the earlier one.
> 3. **Every entry is factual** — if no code, no tests, or no migrations exist yet, the corresponding fields say so. "None" is a truthful answer; silence is not.
> 4. **Each entry answers its fields against the state at that date**, not against what later happened.

---

## 1. Entry Format

Every entry uses the following 13 fields, in order. Fields that do not apply are recorded as **`none`** or **`n/a (no code exists)`** — never omitted.

```markdown
### YYYY-MM-DD — <short task title>

- **Date:** YYYY-MM-DD (time optional, for multiple entries on one day)
- **Phase:** <ROADMAP.md phase, e.g., Phase 0 / Phase 1>
- **Task:** <what was performed, one line>
- **Decision:** <the decision taken>
- **Reason:** <the rationale, referencing a foundation document where applicable>
- **Files changed:** <paths created/modified/deleted; "none" if no files>
- **Database changes:** <migrations, schema, data; "none" if none>
- **API changes:** <endpoints, contract changes; "none" if none>
- **Security changes:** <controls, access, crypto; "none" if none>
- **Tests:** <suites run/added with outcome; "none" if none>
- **Known issues:** <unresolved imperfections discovered>
- **Risks:** <open risks introduced or discovered>
- **Next steps:** <immediate follow-up>
```

**Maintenance rules:**

- Entries are appended in date order at the end of Section 3; the newest entry is last.
- A multi-part task is one entry (the task field names the whole) or several (if the parts were separate decisions) — never both.
- When a task creates multiple files for one decision (e.g., a document cluster), record them as one entry with all paths listed, or as separate entries — whichever matches how the work actually happened.
- When the repository gains git, the log cross-references commits (e.g., "Files changed: … (commit `abc123`)") but remains a human record; it is never auto-generated to replace judgment.

---

## 2. Current Project Initialization State

**Snapshot as of 2026-08-11** — the truthful starting point this log opens from:

- **Repository:** `C:\Users\dipso\OneDrive\Desktop\Swasthya Nepal` — **no git repository initialized** (verified: `git status` returns "not a git repository").
- **Contents:** seventeen markdown documents (listed in Section 3) plus `.freebuff/` — the agent's internal workspace (SQLite database + settings), which is **tooling, not project code**, and has not been modified.
- **Code:** none. No `package.json`, no `src/`, no backend, no migrations, no tests, no Docker, no CI/CD, no configuration files, no `.env.example`.
- **What the foundation documents are:** a ratified-by-conversation design contract. They are **documentation**, not implementation — the engineering contract for the code that does not yet exist.
- **Status of the roadmap:** Phase 0 (Discovery) and Phase 1 (Architecture) are substantially complete in the form of documents; their remaining items (ADR-001 ratification, repository initialization, legal counsel engagement) are recorded as next steps below and in `ROADMAP.md`.

---

## 3. Chronological Entries

### 2026-08-11 — Repository assessment

- **Date:** 2026-08-11
- **Phase:** Phase 0 (Discovery)
- **Task:** Full inspection of the repository as the first engineering action.
- **Decision:** Recorded the repository as greenfield: empty except for agent tooling; no code, no git, no configuration. No technology was adopted at this point; the assessment recommended Laravel + React + PostgreSQL with RLS tenancy.
- **Reason:** The project must be assessed before anything is designed or built; assumptions about existing code would be fabrication.
- **Files changed:** none (assessment was recorded in the working session only).
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** none.
- **Tests:** none.
- **Known issues:** The intended "repository" was confirmed to be the empty folder; sibling folders on the Desktop (PAHUNA, Nepluro, etc.) are separate projects and were not inspected.
- **Risks:** No ratified decisions yet; the architecture rule (single responsibility per technology) is unprotected until ADR-001 exists.
- **Next steps:** Produce the foundation documentation set; ratify the stack via ADR-001.

### 2026-08-11 — MASTER_RULES.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the engineering constitution.
- **Decision:** Mandated 40 rule areas (product scope through definition of done), 16 explicit prohibitions, process rules (naming, branches, commits, PRs, reviews, releases), and an enforcement table; amendments only via ADR.
- **Reason:** Every later document and every future PR needs a governing contract; `MASTER_RULES.md` is that contract.
- **Files changed:** created `MASTER_RULES.md`.
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** none (rules only — no implementation).
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** A constitution without ratified authority can be ignored; ratification is pending the team's acceptance.
- **Next steps:** Ratify via ADR-001; author the product requirements.

### 2026-08-11 — PRODUCT_REQUIREMENTS.md created

- **Date:** 2026-08-11
- **Phase:** Phase 0 (Discovery)
- **Task:** Author the complete product vision and functional scope.
- **Decision:** Defined 24 module groups (platform through interoperability), each with the nine-field spec; phased MVP / Phase 2 / Phase 3 / Enterprise / National; compliance posture claims nothing unverified.
- **Reason:** The product scope must exist before architecture decisions can be justified.
- **Files changed:** created `PRODUCT_REQUIREMENTS.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** Phasing is a commitment; scope creep would blur the MVP boundary defined here.
- **Next steps:** Author the architecture document.

### 2026-08-11 — ARCHITECTURE.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the system architecture.
- **Decision:** Modular monolith behind one Laravel API; React+TS single SPA; PostgreSQL + RLS; Redis; object storage; Python reserved for future AI inference; migration paths documented for each evolution.
- **Reason:** Starting as a modular monolith avoids premature microservices while preserving a documented evolution path.
- **Files changed:** created `ARCHITECTURE.md` (27 numbered sections + migration paths).
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** The monolith-to-services boundary is a discipline that future load pressure may test.
- **Next steps:** Author the database design.

### 2026-08-11 — DATABASE.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the conceptual and logical database design.
- **Decision:** Single-database + RLS as the tenancy default (FORCE RLS, dedicated app role, tenant-safe composite FKs); UUIDv7 PKs; integer minor-unit money; text+CHECK enums; 42 entity specs with the 10-aspect contract.
- **Reason:** The database is the last line of defense for isolation; the design contract must precede migrations.
- **Files changed:** created `DATABASE.md`.
- **Database changes:** none (design only — no migrations written). **API changes:** none. **Security changes:** none (design only).
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** Schema-per-tenant escalation remains unimplemented; the abstraction must preserve the path.
- **Next steps:** Author the design system; then the security design.

### 2026-08-11 — DESIGN_SYSTEM.md created

- **Date:** 2026-08-11
- **Phase:** Phase 0/1 (Discovery/Architecture)
- **Task:** Author the mobile-first design system.
- **Decision:** Quiet "clinical instrument" aesthetic (cool paper + deep teal), the Identity Spine as the signature safety element, Devanagari support as a first-class constraint, confirmation ladder L0–L3, high-risk action rules.
- **Reason:** Mobile-first must be literal for a product used at speed on phones; safety is expressed in interaction, not decoration.
- **Files changed:** created `DESIGN_SYSTEM.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** Color tokens are a working baseline pending visual QA on real devices.
- **Risks:** Token values must be contrast-validated before any implementation uses them.
- **Next steps:** Author the security design.

### 2026-08-11 — SECURITY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the security controls design.
- **Decision:** 34 control areas split into required / recommended / future (142/37/33); threat model; explicit no-compliance-claims posture.
- **Reason:** Healthcare data demands the security posture be designed before code exists; required controls are release blockers by definition.
- **Files changed:** created `SECURITY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none (design only — no controls implemented).
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** Required controls are a large surface; the security backlog must be derived before Phase 2.
- **Next steps:** Author the tenancy deep-dive.

### 2026-08-11 — TENANCY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the multi-tenancy architecture.
- **Decision:** Tenant = organization; context derived from the authenticated principal, never the client; RLS as the hard boundary; facility/branch as policy; full lifecycle (provision → suspend → offboard → purge) designed; support access per-tenant and time-boxed.
- **Reason:** Tenancy is the product's defining safety property and must be specified completely before Phase 3 implements it.
- **Files changed:** created `TENANCY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none (design only).
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** The leakage test suite is specified but does not exist yet.
- **Next steps:** Author the API contract.

### 2026-08-11 — API_CONTRACTS.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the API contract conventions and example contracts.
- **Decision:** One envelope (`data`/`meta`/`links`), one error taxonomy, path versioning with deprecation windows, tenant context via validated header + echoed context, idempotency keys mandatory on clinical/financial mutations, `If-Match` concurrency; example contracts for ten core domains.
- **Reason:** A stable contract is what makes an API-first product safe to build against.
- **Files changed:** created `API_CONTRACTS.md`.
- **Database changes:** none. **API changes:** none (contract only — no endpoints implemented). **Security changes:** none.
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** The OpenAPI generation toolchain is not yet chosen.
- **Next steps:** Author the testing strategy.

### 2026-08-11 — TESTING_STRATEGY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the testing strategy.
- **Decision:** Pyramid (unit → integration → API → E2E) with the critical-workflow suite as the red line; 17 test types; mandatory suites for the nine critical workflows; CI cadence from PR gate to annual failover.
- **Reason:** "Production-grade" must be a property CI proves, not a claim the README makes.
- **Files changed:** created `TESTING_STRATEGY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none (strategy only — no tests written).
- **Known issues:** None at this time.
- **Risks:** The mandatory suites are substantial; they must be built in Phase 3, not deferred.
- **Next steps:** Author the deployment design.

### 2026-08-11 — DEPLOYMENT.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the deployment design.
- **Decision:** Provider-agnostic patterns with named reference implementations; environment stages (local/CI/staging/prod); build-once promote-same-artifact; zero-downtime rolling deploys; forward-only migrations; rollback discipline; IaC with parameterized modules.
- **Reason:** Deployment must be designed as a pipeline property before any infrastructure exists.
- **Files changed:** created `DEPLOYMENT.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** Provider choice is deferred to an ADR; nothing is provisioned.
- **Next steps:** Author the DR strategy.

### 2026-08-11 — DISASTER_RECOVERY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the disaster recovery strategy.
- **Decision:** RPO/RTO as targets validated by drills, never claimed as achieved; PITR via continuous WAL + base backups; encrypted isolated backups with cross-region copy; scenario paths (regional, ransomware, deletion, corruption); quarterly restore drill + annual failover + tabletops.
- **Reason:** Recovery claims must be measured or not made; the honesty principle is structural to the document.
- **Files changed:** created `DISASTER_RECOVERY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** No backup pipeline or drills exist yet — nothing is claimed as achieved.
- **Risks:** Backup infrastructure is unimplemented; RPO/RTO are targets until measured.
- **Next steps:** Author the observability design.

### 2026-08-11 — OBSERVABILITY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the observability design.
- **Decision:** Structured JSON logs, RED/USE metrics, OTel traces, request/correlation ID stack, health tiers, queue/DB/tenant health signals, alert taxonomy with owned alerts, and an absolute never-log rule for PHI and secrets enforced by redactor tests.
- **Reason:** Observability is how the platform is debugged without production access; the never-log rule is a data-protection requirement.
- **Files changed:** created `OBSERVABILITY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** No logging/metrics pipeline exists yet.
- **Risks:** Redactor correctness will need dedicated CI tests when logging ships.
- **Next steps:** Author the clinical safety principles.

### 2026-08-11 — CLINICAL_SAFETY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the clinical safety principles.
- **Decision:** The clinician decides and the software assists; no autonomous-action path; record-as-truth with immutable signed documents; allergy/interaction/result/critical-value disciplines; overrides as documented decisions; safety measured like reliability; no certification claims.
- **Reason:** Clinical safety is a design property, and the product's claims must be assistive-by-design, never validated-by-assertion.
- **Files changed:** created `CLINICAL_SAFETY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** Clinical authority review is pending (the document is an engineering contract, not a clinical sign-off).
- **Risks:** No clinical validation exists; nothing here is a claim of clinical effectiveness.
- **Next steps:** Author the interoperability design.

### 2026-08-11 — INTEROPERABILITY.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the interoperability readiness design.
- **Decision:** Outbox/inbox integration architecture; FHIR/HL7/DICOM as boundary standards with fixture-tested mapping; honest readiness inventory (design/planned/future — no integration claimed to exist); idempotency, retries, registry truth, consent at the boundary.
- **Reason:** Interoperability must be designed as readiness and honesty — nothing simulated, nothing claimed green.
- **Files changed:** created `INTEROPERABILITY.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** No integration of any kind exists; the inventory states this explicitly.
- **Risks:** Planned integrations (SMS/email/payments) are scheduled with the modules that need them.
- **Next steps:** Author the AI governance rules.

### 2026-08-11 — AI_RULES.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the AI governance rules.
- **Decision:** Five-tier classification (informational → assistive → recommendation → human-approved → prohibited-without-controls); no autonomous-action path; explainability, auditability, model versioning, prompt security, calibration, fallback, escalation-as-human-event; AI registry governance.
- **Reason:** AI in healthcare must be governed by strict, enforceable rules before any AI capability is considered.
- **Files changed:** created `AI_RULES.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** No AI capability exists; the registry is empty by design.
- **Risks:** The Python inference service boundary is unimplemented.
- **Next steps:** Author the billing separation design.

### 2026-08-11 — BILLING.md created

- **Date:** 2026-08-11
- **Phase:** Phase 1 (Architecture)
- **Task:** Author the SaaS subscription billing design.
- **Decision:** SaaS billing (platform bills the organization) strictly separated from hospital patient billing (the hospital bills its patients) as two domains with a proven boundary; plans/subscriptions/trials/entitlements/upgrades/downgrades/invoices/payments/refunds/status/metering readiness/enterprise plans.
- **Reason:** The two billing systems have different actors, risks, and audit classes; merging them would be a domain error.
- **Files changed:** created `BILLING.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** No billing code or payment gateway exists; the gateway is a planned integration.
- **Risks:** The boundary must be proven by tests when billing ships.
- **Next steps:** Author the roadmap.

### 2026-08-11 — ROADMAP.md created

- **Date:** 2026-08-11
- **Phase:** Phase 0/1 (Discovery/Architecture)
- **Task:** Author the staged roadmap.
- **Decision:** 23 phases (Discovery through National Scale) each with objective/dependencies/modules/deliverables/acceptance/testing/production-readiness; MVP as a horizontal cut across phases; milestones M0–M5; MVP vs. enterprise/national explicitly tagged.
- **Reason:** The platform must not be built all at once; a sequenced, gated plan is the only realistic path.
- **Files changed:** created `ROADMAP.md`.
- **Database changes:** none. **API changes:** none. **Security changes:** none.
- **Tests:** none.
- **Known issues:** None at this time.
- **Risks:** The plan commits to sequencing; parallelization pressure must be resisted per the governing rule.
- **Next steps:** Ratify the foundation (ADR-001, repository initialization) and begin Phase 2 (Platform Foundation).

### 2026-08-11 — Phase 2: local toolchain and PostgreSQL environment

- **Date:** 2026-08-11
- **Phase:** Phase 2 (Platform Foundation)
- **Task:** Provisioned a project-local portable toolchain and a real PostgreSQL development database.
- **Decision:** No PHP, Composer, or PostgreSQL existed on this machine and Docker Desktop's engine failed to start, so the dev environment is a gitignored project-local toolchain: portable PHP 8.4 + Composer + PostgreSQL 16.4 under `.toolchain/`, a PostgreSQL cluster on `127.0.0.1:54329` with a randomly generated local password stored only inside `.toolchain/`, and databases `swasthya` (dev) and `swasthya_test` (test).
- **Reason:** The foundation's hard requirement is that tests run against real PostgreSQL, never SQLite (MASTER_RULES.md §5, TESTING_STRATEGY.md); a project-local toolchain needs no global installs and no admin rights, and keeps the repository self-contained.
- **Files changed:** created `.toolchain/` (gitignored): `php/` (PHP 8.4.24), `pgsql/pgsql/` (PostgreSQL 16.4), `composer.phar`, `cacert.pem`, `pgdata/` (cluster), `pgpw.txt` + `.dev_db_password` (random local dev password), `pg.log`.
- **Database changes:** initialized a PostgreSQL 16.4 cluster; created role `swasthya` and databases `swasthya` and `swasthya_test`; cluster listens on loopback only.
- **API changes:** none.
- **Security changes:** local dev password randomly generated (no default credentials, per MASTER_RULES.md §29), stored only in the gitignored toolchain, never in source.
- **Tests:** none at this stage.
- **Known issues:** Docker Desktop's engine failed to start (self-cleanup observed); the container topology from DEPLOYMENT.md remains the production target but is not yet exercised locally.
- **Risks:** the toolchain is Windows-specific; it must not become the only supported development path.
- **Next steps:** scaffold the Laravel backend into `backend/`.

### 2026-08-11 — Phase 2: Laravel scaffold and environment configuration

- **Date:** 2026-08-11
- **Phase:** Phase 2 (Platform Foundation)
- **Task:** Scaffolded the Laravel 12 backend and configured the environment layer.
- **Decision:** Laravel 12.65 (PHP 8.4.24) is the single backend framework (ARCHITECTURE.md §4); PostgreSQL is the only database (MASTER_RULES.md §5.1); argon2id password hashing (SECURITY.md §2); structured JSON logging everywhere (MASTER_RULES.md §18.1); environment values use `SWASTHYA_` conventions with placeholders only in `.env.example`; a root `.gitignore` protects `.toolchain/`, `.freebuff/`, and all local `.env` files.
- **Reason:** One framework owns the backend to avoid duplication (MASTER_RULES.md §3.2); secrets and environments follow MASTER_RULES.md §28–29.
- **Files changed:** created `backend/` via `composer create-project` (scaffold: `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `artisan`, `composer.json`/`lock`, `phpunit.xml`, `vite.config.js`); rewrote `backend/.env.example` (placeholders only) and created `backend/.env` (local values, gitignored); created root `.gitignore`; modified `backend/config/database.php` (PostgreSQL-only), `backend/config/logging.php` (json channel), `backend/config/hashing.php` (argon2id), `backend/config/cors.php` (strict allowlist), `backend/config/app.php`; created `backend/config/swasthya.php` (platform configuration).
- **Database changes:** none (connection only, not yet exercised by code).
- **API changes:** none.
- **Security changes:** no secrets in source; `.env.example` carries placeholders only; the local dev database password is read from the gitignored toolchain file, never hardcoded.
- **Tests:** none yet.
- **Known issues:** Redis-backed cache/queues/sessions are documented but locally still use database/array drivers (Redis not yet part of the toolchain).
- **Risks:** `.env` values are local-only; nothing may assume them in code.
- **Next steps:** build the API core — envelope, error taxonomy, middleware, health checks, validation, users migration.

### 2026-08-11 — Phase 2: API foundation

- **Date:** 2026-08-11
- **Phase:** Phase 2 (Platform Foundation)
- **Task:** Built the API foundation: versioned routing, response envelope, error taxonomy, request/correlation IDs, security headers, request logging, health checks, validation base, and the users migration.
- **Decision:** Routes live under `api/v1` (API_CONTRACTS.md §2); every response uses the envelope `{data, error, meta}` (API_CONTRACTS.md §7) with a documented error-code taxonomy; middleware order is request-ids → security headers → request logging on the global stack so every request including unmatched routes and error paths is protected and traceable; `X-Request-Id` is always server-generated and `X-Correlation-Id` is client-proposed but validated (API_CONTRACTS.md §17–18); health endpoints distinguish liveness from readiness with a real database check; `ApiRequest` rejects unknown fields by default (MASTER_RULES.md §30.1); the `users` table follows DATABASE.md §3.4 (UUID primary key, argon2id password, unique email).
- **Reason:** These are the Phase 2 foundations every later module depends on; the exact contracts were already specified in the foundation documents.
- **Files changed:** created `backend/routes/api.php` and rewrote `backend/routes/web.php`; created `backend/app/Support/Envelope.php`, `backend/app/Support/ErrorCodes.php`, `backend/app/Exceptions/ApiException.php`, `backend/app/Exceptions/ApiExceptionMapper.php`, `backend/app/Http/Middleware/AssignRequestIds.php`, `backend/app/Http/Middleware/SecurityHeaders.php`, `backend/app/Http/Middleware/LogRequest.php`, `backend/app/Http/Controllers/Api/HealthController.php`, `backend/app/Http/Requests/ApiRequest.php`, `backend/app/Models/Concerns/HasUuid.php`; rewrote `backend/app/Models/User.php`, `backend/database/migrations/0001_01_01_000000_create_users_table.php`, `backend/app/Providers/AppServiceProvider.php`; modified `backend/bootstrap/app.php` and `backend/config/app.php`.
- **Database changes:** migration `create_users_table` applied to the dev database (UUID pk, unique email, name, argon2id password, timestamps); the scaffold cache/jobs tables also migrated.
- **API changes:** `GET api/v1/health/live`, `GET api/v1/health/ready` (readiness performs a real database query), `GET api/v1/context` (echoes request/correlation IDs for verification); all failures return the documented error envelope.
- **Security changes:** full security header set on every response including error responses (SECURITY.md §23); strict CORS allowlist (SECURITY.md §24); named rate-limit buckets defined (`api`/`auth`/`writes`) per MASTER_RULES.md §6.11; request/correlation IDs on every response.
- **Tests:** none yet (suite wired next).
- **Known issues:** authentication, authorization, and tenancy middleware deliberately arrive in Phase 3 per the roadmap; the `context` endpoint exists only to prove the ID plumbing and will be removed or scoped later.
- **Risks:** none new.
- **Next steps:** wire the testing foundation.

### 2026-08-11 — Phase 2: testing foundation on real PostgreSQL

- **Date:** 2026-08-11
- **Phase:** Phase 2 (Platform Foundation)
- **Task:** Wired the testing foundation: Pest + PHPUnit configured against the real PostgreSQL test database, with foundation suites covering migrations, envelope, error mapping, validation, health, request IDs, security headers, and logging.
- **Decision:** The test suite runs against real PostgreSQL (`swasthya_test`), never SQLite (TESTING_STRATEGY.md); `phpunit.xml` uses the real `json` log channel; both Unit and Feature suites extend the application `TestCase` so container-bound code is testable; `Log::fake()` does not exist in this Laravel version, so logging tests assert on the actual JSON log file (which also proves the JSON formatter produces parseable lines).
- **Reason:** Tests that prove nothing about the real database or the real log pipeline are not foundation tests.
- **Files changed:** rewrote `backend/phpunit.xml` and `backend/tests/Pest.php`; created `backend/tests/Unit/EnvelopeTest.php`, `backend/tests/Unit/ApiExceptionMapperTest.php`, `backend/tests/Unit/ApiRequestTest.php`, `backend/tests/Feature/HealthTest.php`, `backend/tests/Feature/RequestIdsTest.php`, `backend/tests/Feature/SecurityHeadersTest.php`, `backend/tests/Feature/DatabaseFoundationTest.php`, `backend/tests/Feature/LoggingTest.php`; created `backend/.env.testing` (gitignored) pointing at `swasthya_test`.
- **Database changes:** the test database is migrated fresh per suite via `RefreshDatabase`.
- **API changes:** none.
- **Security changes:** none.
- **Tests:** full suite — 36 passed, 159 assertions — against PostgreSQL 16.4.
- **Known issues:** none.
- **Risks:** none new.
- **Next steps:** live smoke verification over HTTP (this is where the next entry's defect was found).

### 2026-08-11 — Phase 2: duplicate request-log defect found and fixed

- **Date:** 2026-08-11
- **Phase:** Phase 2 (Platform Foundation)
- **Task:** Discovered during live HTTP smoke verification that error responses produced TWO `request.completed` log lines per request; root-caused, fixed, and hardened with regression tests.
- **Decision:** Laravel 12's `Illuminate\Routing\Pipeline` renders exceptions to responses INSIDE the middleware stack, so middleware post-`next` code runs on error paths — the bootstrap exception-render path was therefore redundantly re-applying headers and logging. Fix: on the exception-render path, apply IDs, security headers, and the request log line ONLY when the request never reached the middleware at all (`request_id` attribute absent — framework-level escapes), so a failed request is still traceable but never logged twice. Added exactly-once assertions to `LoggingTest` for both success and error paths.
- **Reason:** One request must produce exactly one request line (OBSERVABILITY.md §2, MASTER_RULES.md §18.4); duplicate lines corrupt the operational log and per-request accounting.
- **Files changed:** `backend/bootstrap/app.php` (guard plus `Str` import); corrected the exception-path docblocks in `backend/app/Http/Middleware/AssignRequestIds.php`, `SecurityHeaders.php`, `LogRequest.php`; `backend/tests/Feature/LoggingTest.php` (exactly-once tests).
- **Database changes:** none.
- **API changes:** none (behavioral fix: one log line per request, IDs and headers unchanged).
- **Security changes:** none.
- **Tests:** full suite re-run — 36 passed, 159 assertions; live smoke test confirmed exactly one log line per 200 and per 404, and full IDs + security headers on error responses.
- **Known issues:** none.
- **Risks:** none.
- **Next steps:** Phase 3 (Identity and Tenancy) is the next build phase per ROADMAP.md milestone M1.

### 2026-08-11 — Phase 3: tenancy, RBAC, and audit schema

- **Date:** 2026-08-11
- **Phase:** Phase 3 (Identity and Tenancy)
- **Task:** Implemented the tenancy/RBAC/audit schema and the platform RBAC catalog.
- **Decision:** Migrations follow DATABASE.md §3.1–3.7 and §3.36 exactly: `organizations` (tenant root, no tenant_id, never soft-deleted), `facilities` (tenant-scoped, partial-unique code per tenant), `roles` + `permissions` (platform-global catalogs, `domain:action` codes) + `role_permissions`, `role_assignments` (status lifecycle, partial unique index with `NULLS NOT DISTINCT` so one active assignment per user/role/scope, platform scope has `tenant_id NULL`), `audit_events` (append-only, hash-chained, standalone by design — no FKs so history survives purges), `refresh_tokens` (hash-only storage, family rotation), and MFA readiness columns on users. Sanctum's published migration was adapted from `morphs()` (bigint) to a UUID `tokenable_id` because Swasthya primary keys are UUIDv7 (DATABASE.md §0.2). The seeder ships the full MASTER_RULES §9.1 role catalog and this phase's permission set; the scaffold's demo-user `DatabaseSeeder` was replaced with catalog-only seeding (no demo data, MASTER_RULES P.9).
- **Reason:** The schema IS the tenancy contract — tenant-safe structure, no cross-tenant references expressible, and an audit trail whose tamper-evidence is structural.
- **Files changed:** created `backend/database/migrations/2026_08_11_060000_create_organizations_table.php`, `…060100_create_facilities_table.php`, `…060200_create_roles_and_permissions_tables.php`, `…060300_create_role_assignments_table.php`, `…060400_create_audit_events_table.php`, `…060500_create_refresh_tokens_table.php`, `…060600_add_mfa_readiness_to_users_table.php`; adapted `…055603_create_personal_access_tokens_table.php` (UUID tokenable); created `backend/database/seeders/RolePermissionSeeder.php`; replaced `backend/database/seeders/DatabaseSeeder.php`; created `backend/database/factories/OrganizationFactory.php`, `FacilityFactory.php`.
- **Database changes:** new tables as above; applied to dev DB (later rebuilt with `migrate:fresh`).
- **API changes:** none yet (schema first).
- **Security changes:** MFA columns are schema readiness only — no TOTP flow yet (recorded as follow-up).
- **Tests:** none yet in this step.
- **Known issues:** none.
- **Risks:** the audit table is unpartitioned — RANGE partitioning on `occurred_at` is the documented national-scale design and is deferred to the partition-maintenance phase (deviation recorded in the migration).
- **Next steps:** identity services, context middleware, and the API surface.

### 2026-08-11 — Phase 3: identity services, context middleware, and wiring

- **Date:** 2026-08-11
- **Phase:** Phase 3 (Identity and Tenancy)
- **Task:** Implemented the identity services and the per-request context machinery.
- **Decision:** `TenantContext` is an immutable per-request value object derived from the principal's ACTIVE role assignments — never from client input (TENANCY.md §3); the client may only PROPOSE `X-Swasthya-Facility`, the server validates it against the assignments and derives the tenant from the facility's organization, else uses the most recent active assignment; platform-scope roles get platform context; a suspended/closed organization → 403 TENANT_SUSPENDED; no assignments → 403 (default deny). `can($permission)` resolves live from assignments × role permissions × facility scope (org-scoped roles cover all facilities, TENANCY.md §7; role changes take effect immediately). `AuditLogger` is the ONLY audit writer: full context (tenant, facility, actor+email, action, resource, payload, IP, correlation id) with a transaction-scoped advisory lock so the hash chain stays linear. `RefreshTokenService` implements rotation with reuse detection (a replayed token revokes the whole family — a theft signal, SECURITY.md §4). Context is echoed on every response via the envelope (`meta.context`); `EnsurePermission` is the route-level `authorize:` gate; the pgsql connection timezone is pinned to UTC so timestamp serialization is deterministic (DATABASE.md §0.3).
- **Reason:** Context derived from the principal, authorization as a live per-request decision, and a tamper-evident audit chain are the Phase 3 acceptance criteria (ROADMAP.md §4).
- **Files changed:** created `backend/app/Support/TenantContext.php`, `AccessCheck.php`, `AuditLogger.php`, `backend/app/Services/RefreshTokenService.php`, `backend/app/Http/Middleware/ResolveTenantContext.php`, `EnsurePermission.php`; updated `backend/app/Support/Envelope.php` (context echo), `backend/config/database.php` (connection timezone), `backend/config/swasthya.php` (auth section), `backend/bootstrap/app.php` (`authorize` alias), `backend/app/Models/User.php` (HasApiTokens, relationships), and created models `Organization`, `Facility`, `Role`, `Permission`, `RoleAssignment`, `AuditEvent`, `RefreshToken`.
- **Database changes:** none (services only).
- **API changes:** none yet.
- **Security changes:** per-request principal derivation from the bearer token (see defect entry below); no PHI in audit payloads.
- **Tests:** none yet in this step.
- **Known issues:** database-level RLS (FORCE + non-owner app role + GUC) remains the documented Phase 3 hardening follow-up — isolation is currently enforced at the application layer with the leakage suites green.
- **Risks:** none new.
- **Next steps:** the auth and administration API surface.

### 2026-08-11 — Phase 3: authentication and administration API surface

- **Date:** 2026-08-11
- **Phase:** Phase 3 (Identity and Tenancy)
- **Task:** Implemented the versioned API surface: authentication, organizations, facilities, users, role assignments, catalogs, and audit reads.
- **Decision:** Routes follow API_CONTRACTS.md: `POST /auth/login` (access + rotating refresh token, refresh also set as httpOnly SameSite=Strict cookie for the SPA), `POST /auth/refresh`, `POST /auth/logout` (revokes the access token and ALL refresh tokens — immediate revocation everywhere, SECURITY.md §4), `GET /auth/me`, `GET /users/me`; org/facility/user/assignment CRUD under `/organizations/{org}/…`; read-only catalogs (`/roles`, `/permissions`) and `/audit-events` gated by permission. Every endpoint sits behind `auth:sanctum` → `ResolveTenantContext` → `authorize:<permission>` — there are no trusted internal endpoints (MASTER_RULES §8.2). Scope semantics follow API_CONTRACTS §4: reads outside scope → 404 (existence never leaked), writes → 403 SCOPE_DENIED / FACILITY_DENIED / TENANT_SUSPENDED. Brute-force protection is two-layered: per-IP `throttle:auth` plus a per-account failure counter with lockout and `Retry-After` (SECURITY.md §18). Mutations return `X-Audit-Event-Id` (API_CONTRACTS §16). All auth events are audited; role grants/revokes are the highest-value authorization audit (DATABASE.md §3.7). The named `login` route was added to web.php because Laravel's Authenticate middleware redirects unauthenticated non-JSON requests there — without it the redirect itself 500s; it returns the standard 401 envelope.
- **Reason:** The contract was already specified; this phase binds it.
- **Files changed:** rewrote `backend/routes/api.php` and `backend/routes/web.php`; created controllers `AuthController`, `OrganizationController`, `FacilityController`, `UserController`, `RoleAssignmentController`, `RoleController`, `PermissionController`, `AuditController` under `backend/app/Http/Controllers/Api/`; created requests `Auth/LoginRequest`, `Auth/RefreshRequest`, `Organization/StoreOrganizationRequest`, `Facility/StoreFacilityRequest`, `User/StoreUserRequest`, `User/GrantRoleAssignmentRequest`.
- **Database changes:** none beyond schema.
- **API changes:** 20 routes registered under `/api/v1` (verified via `route:list`).
- **Security changes:** account lockout; refresh-token reuse detection; immediate revocation on logout; every mutating response carries the audit event id.
- **Tests:** none yet in this step (suite written next).
- **Known issues:** none.
- **Risks:** none new.
- **Next steps:** the mandatory test suites, then live smoke verification.

### 2026-08-11 — Phase 3: test suites, defects found and fixed, suite green

- **Date:** 2026-08-11
- **Phase:** Phase 3 (Identity and Tenancy)
- **Task:** Wrote the mandatory Phase 3 suites (TESTING_STRATEGY.md §4.1–4.3) and fixed every defect they surfaced; suite green on real PostgreSQL.
- **Decision:** Seven new suites: `AuthTest` (login/lockout/refresh rotation + reuse/logout revocation), `AuthorizationTest` (401s, 403 SCOPE_DENIED, platform provisioning gated, immediate role-change effect), `RolePermissionMatrixTest` (every seeded role × every seeded permission, data-driven), `TenantIsolationTest` (cross-tenant reads 404 / writes 403, forged tenant_id rejected, suspended tenant, audit scoping), `FacilityIsolationTest` (facility-scoped principals cannot reach sibling facilities), `AuditTest` (X-Audit-Event-Id, chain linearity, tamper-evidence, no secrets in payloads), `OrganizationFacilityUserTest` (uniqueness, membership, assignment lifecycle). Support via `tests/Support/Identity.php` + `seedIdentity()`.
- **Reason:** The red-line suites are the Phase 3 acceptance criteria; the defects below are exactly what they are for.
- **Files changed:** created `backend/tests/Support/Identity.php` and the seven Feature suites; updated `backend/tests/Pest.php` (`seedIdentity`); later a repo-wide `laravel/pint` formatting pass.
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** see defects.
- **Tests:** final suite — **102 passed, 478 assertions** against PostgreSQL 16.4; `pint` clean.
- **Known issues and defects found & fixed (each with its entry in the code):**
  1. **Sanctum morph id** — `morphs('tokenable')` emits a bigint; UUID keys require a UUID `tokenable_id` (fixed in the migration; dev DB rebuilt).
  2. **Audit chain mismatch** — (a) `chainPayload()` ran before the UUID id was assigned (fixed: id set before hashing); (b) the PostgreSQL session timezone (+05:45) differed from PHP (UTC), so `toIso8601String()` serialization differed between insert and read-back (fixed: pgsql connection timezone pinned to UTC).
  3. **Stale authenticated principal across requests** — Sanctum's guard caches its user on the shared AuthManager; in a long-running process (tests, Octane) the SECOND request with a DIFFERENT token resolved the FIRST request's user. Real correctness bug: `ResolveTenantContext` now derives the principal from the bearer token directly per request.
  4. **`org_admin` seeded with `organization:manage`** — the platform-only provisioning permission was wrongly granted to tenants (removed; provisioning is platform-scoped per TENANCY.md §12).
  5. **Missing named `login` route** — unauthenticated non-JSON requests 500'd on the redirect target (fixed with the web.php 401 route above).
  6. **Scaffold demo seeder** — replaced with catalog-only seeding (P.9).
- **Risks:** none new.
- **Next steps:** live smoke verification over HTTP; update README status; record in this log.

### 2026-08-11 — Phase 3: live smoke verification and README status update

- **Date:** 2026-08-11
- **Phase:** Phase 3 (Identity and Tenancy)
- **Task:** Verified the full identity flow over live HTTP and updated the README's honest status.
- **Decision:** End-to-end curl smoke against `artisan serve`: superadmin login → provision organization → create facility → create org admin → org-admin login → context echo (`meta.context` carries tenantId + facility timezone) → tenant user attempting platform provisioning → 403 → unauthenticated audit read → 401 → refresh rotation with replay → 401 TOKEN_REVOKED. All passed. Smoke artifacts were then removed from the dev DB, leaving the clean foundation state. README updated to reflect the real state: Phases 2–3 implemented, 102 tests green, RLS/MFA/CI/Docker/ADR-001 explicitly still open.
- **Reason:** A feature that only passes in tests is not verified; the live path is the same contract over real HTTP.
- **Files changed:** `README.md` (status header, project position, repository structure, development/environment setup, phases, testing, security, honest-status summary).
- **Database changes:** dev DB reset to clean foundation state after the smoke run (throwaway smoke rows removed).
- **API changes:** none.
- **Security changes:** none.
- **Tests:** suite re-run after README/log changes — 102 passed, 478 assertions.
- **Known issues:** none.
- **Risks:** the smoke test bootstrapped a platform superadmin directly via the ORM — the idempotent provisioning runbook (TENANCY.md §12) is the designed, audited path that still needs to be built.
- **Next steps:** Phase 3 hardening (database-level RLS + non-owner app role, MFA TOTP flow, lockout-on-password-change hooks) or Phase 4 (Hospital Administration) per ROADMAP; M0 items (ADR-001, git init) remain open.

### 2026-08-11 — Phase 4: hospital administration schema (9 migrations)

- **Date:** 2026-08-11
- **Phase:** Phase 4 (Hospital Administration)
- **Task:** Schema for departments, locations, wards, rooms, beds, staff, services, and facility configuration.
- **Decision:** All eight entities are tenant-scoped with `tenant_id NOT NULL` and tenant-safe composite FKs `(tenant_id, facility_id) → facilities(tenant_id, id)` (DATABASE.md §0.9) — a new migration adds the parent unique index; a cross-tenant reference is now structurally impossible. Beds: no soft delete, `out_of_service` is a status, `lock_version` for optimistic locking (DATABASE.md §0.7), `current_admission_id` column reserved for the IPD phase FK. Staff: never soft-deleted (departure is a status), `license_number_encrypted` column, employee_code unique per tenant, one active profile per user per tenant. Two entities extend the documented model: `services` (referenced by §3.16 `service_id` but never defined — added as §3.43) and `facility_settings` (PRODUCT_REQUIREMENTS §5.5 — added as §3.44), both recorded in DATABASE.md.
- **Reason:** Every entity follows the tenancy architecture (TENANCY.md §0–6): tenant hard boundary, facility scope at the policy layer, composite FKs as the structural guarantee.
- **Files changed:** 9 new migrations (`2026_08_11_064500` facilities composite-unique index; `070000` departments; `070100` locations; `070200` wards; `070300` rooms; `070400` beds; `070500` staff; `070600` services; `070700` facility_settings); DATABASE.md §3.43–3.44 added.
- **Database changes:** 9 tables/indexes as above; CHECK constraints on all status/type enums; partial unique indexes for active-scope code uniqueness.
- **API changes:** none yet (this entry is schema only).
- **Security changes:** at-rest encryption field (`license_number_encrypted`) lands with the app-layer cast in the next entry.
- **Tests:** migrations verified on the dev database (`migrate` clean).
- **Known issues:** none.
- **Risks:** soft deletes do not trip RESTRICT FKs — the delete guards live in the controllers (next entries) and are explicitly tested.
- **Next steps:** models, cast, state machine, access checks, API surface, tests.

### 2026-08-11 — Phase 4: models, encryption cast, bed state machine, access checks

- **Date:** 2026-08-11
- **Phase:** Phase 4 (Hospital Administration)
- **Task:** Models (Department, Location, Ward, Room, Bed, Staff, Service, FacilitySetting) + factories; EncryptedString cast; BedStatus state machine; AccessCheck::scoped + FacilityScope.
- **Decision:** License numbers are encrypted at rest with the app-layer `EncryptedString` cast (AES-256-GCM via APP_KEY, SECURITY.md §12) — the DB column holds ciphertext; the attribute reads plaintext; nothing of the value is ever logged or audited. Bed status is a state machine (DATABASE.md §0.5): Phase 4 allows available/reserved/cleaning/out_of_service transitions; `occupied` is rejected explicitly because it requires an admission, which arrives with the IPD phase — no pretended invariant. `AccessCheck::scoped()` generalizes the tenant/facility visibility rule (404 reads / 403 writes outside scope, platform bypass) to any tenant-scoped model; `FacilityScope::resolve()` derives the write facility from context for facility-scoped principals and validates the proposal for org/platform principals.
- **Reason:** Security and integrity rules from SECURITY.md/DATABASE.md must be in the foundation, not bolted on later.
- **Files changed:** 8 models + 8 factories, `app/Casts/EncryptedString.php`, `app/Support/BedStatus.php`, `app/Support/FacilityScope.php`, `AccessCheck` extended.
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** at-rest encryption for staff license numbers; strict state-machine gate on bed status.
- **Tests:** covered by the Phase 4 suites (below).
- **Known issues:** none.
- **Risks:** the cast silently clears on an unreadable value is by design; key rotation for encrypted fields is a documented future control.
- **Next steps:** API surface + permissions.

### 2026-08-11 — Phase 4: API surface, validation, authorization, audit

- **Date:** 2026-08-11
- **Phase:** Phase 4 (Hospital Administration)
- **Task:** Controllers, FormRequests, routes, and seeder permissions for the eight catalogs.
- **Decision:** REST surface under `/api/v1`: org-scoped index/create for departments, locations, wards, services, staff; nested creation for rooms (under a ward) and beds (under a room) so the parent is the tenant/facility anchor; `PATCH /beds/{bed}` requires `lockVersion` and returns 409 LOCK_CONFLICT on staleness; delete endpoints soft-delete with explicit child-existence guards (409) since RESTRICT FKs never fire on soft deletes. 16 new permissions (`domain:view/manage` for department, location, ward, room, bed, staff, service, settings) granted to org_admin and hospital_admin (manage), branch_manager (view-only); superadmin inherits via catalog; every permission is exercised by the matrix suite. Every write is audited with the resource's facility even when the actor is org-scoped (AuditLogger derives facility from the resource). No license number in any response except `show` (staff:view), never in audit payloads.
- **Reason:** API_CONTRACTS.md §3–8 (versioned envelope, strict validation, unknown-field rejection) and MASTER_RULES.md §8–9 (gate everything, default deny) are followed endpoint-by-endpoint.
- **Files changed:** 8 controllers, 16 FormRequests (+ `HasFacilityContext` trait), `routes/api.php` (+24 routes), `RolePermissionSeeder` (+16 permissions, +role grants).
- **Database changes:** none.
- **API changes:** 24 new endpoints under `/api/v1` (Phase 4 catalog CRUD + facility settings); 16 new permissions.
- **Security changes:** facility-scoped principals are bound to their context facility (a proposed facilityId in the body is rejected, 422); cross-tenant/cross-facility reads 404, writes 403.
- **Tests:** Phase 4 suites (next entry).
- **Known issues:** none.
- **Risks:** routes are the authorization surface; the matrix suite keeps every permission honest.
- **Next steps:** test suites, defects, smoke.

### 2026-08-11 — Phase 4: test suites, defects found and fixed, suite green

- **Date:** 2026-08-11
- **Phase:** Phase 4 (Hospital Administration)
- **Task:** Seven new suites (Department, Location, WardRoomBed, Staff, Service, FacilitySettings, Phase4Authorization) — CRUD, hierarchy integrity, code uniqueness, bed state machine + optimistic locking, encryption at rest, audit discipline, facility isolation, role gates.
- **Decision:** Tests assert the invariants, not just status codes: ciphertext at rest vs plaintext in memory, license never in audit payloads, `occupied` rejected until IPD, stale lock → 409, delete guards → 409, facility-scoped list scoping, cross-tenant 404/403.
- **Reason:** TESTING_STRATEGY.md §4.2 — authorization and isolation are mandatory gates, and every new permission must be exercised.
- **Files changed:** 7 new Feature suites; `phpunit.xml` (+ test-only APP_KEY for the encryption cast — .env.testing shadows .env, and a deterministic test key is not a production secret); AuditLogger facility resolution.
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** AuditLogger now guarantees `facility_id` on events for facility-scoped resources (org-scoped actors included).
- **Tests:** 138 passed, 796 assertions, real PostgreSQL. Defects found and fixed: (1) `hashed`-cast bcrypt mismatch while bootstrapping smoke users; (2) controller parameter name `$member` vs route segment `{staff}` — implicit binding silently autowires an empty model, causing a NOT-NULL insert; fixed by matching names (documented in the controller docblock); (3) `isset()` is false for a `null` map value, so the `facility_settings` audit resolution never ran — `array_key_exists`; (4) jsonb reorders payload keys — tests assert fields individually; (5) `assertJsonPath` dot-notation breaks on setting keys containing dots; (6) two audit events in one test share `occurred_at` — tie-break on uuidv7 id like the chain itself.
- **Known issues:** none.
- **Risks:** the `{staff}` binding footgun is the kind of silent failure only an isolation test catches — the matrix and isolation suites are the safety net.
- **Next steps:** live smoke verification.

### 2026-08-11 — Phase 4: live smoke verification and documentation update

- **Date:** 2026-08-11
- **Phase:** Phase 4 (Hospital Administration)
- **Task:** End-to-end HTTP smoke (login → provision org/facility → department → ward → room → bed → staff → service → settings → bed state transitions → isolation), then README + log update.
- **Decision:** Smoke verified: 201 on every creation, settings versioned, bed `reserved` with lock_version 0→1, `occupied` → 422 with the admission-workflow message, license stored as ciphertext (200-char Laravel payload, never plaintext) and decrypted only on `show`, audit events carry the facility for org-scoped actors, and a second-facility hospital_admin got 404 reads / 403 writes on the first facility's data. Smoke data was force-cleaned from the dev DB (append-only audit events were left — they are the record).
- **Reason:** Live verification is the difference between tests passing and the system working (MASTER_RULES.md §16).
- **Files changed:** README status table; DEVELOPMENT_LOG entries.
- **Database changes:** none (dev DB smoke rows removed).
- **API changes:** none.
- **Security changes:** none.
- **Tests:** final suite re-run — 138 passed, 796 assertions. Pint clean.
- **Known issues:** none.
- **Risks:** the smoke superadmin was bootstrapped via ORM again — the provisioning runbook remains an open item (TENANCY.md §12).
- **Next steps:** Phase 5 (Patient Master) per ROADMAP; still open from M0/Phase 3: git init, ADR-001, CI, DB-level RLS, MFA TOTP, provisioning runbook.

### 2026-08-11 — Phase 5: Patient Master schema, models, services

- **Date:** 2026-08-11
- **Phase:** Phase 5 (Patient Master)
- **Task:** Implement the master patient record: registration with atomic per-tenant MRN issuance, encrypted identifiers with deterministic duplicate hashing, contacts, insurance policies, consents (versioned), document metadata, patient timeline.
- **Decision:** Eight new tables (payers, patients, patient_identifiers, patient_contacts, insurance_policies, patient_documents, consents, patient_timeline_entries), all tenant-scoped with tenant-safe composite FKs. `patient_identifiers` stores ciphertext (`EncryptedString` cast, AES-256-GCM via APP_KEY) plus a sha256 dedupe hash; the hash index is deliberately NON-unique so a duplicate registration is allowed and surfaced as a merge candidate — never silently blocked (DATABASE.md §3.12). One active identifier per (patient, type) via partial unique index. Documents are honest metadata-only (`staged`, no object key) until object storage lands. The `patient_documents` self-FK must be added in a separate `Schema::table` call after the table (and its PK) exist — PostgreSQL rejects a deferred alter referencing a not-yet-existing key.
- **Reason:** Identity integrity is the platform's most safety-critical surface; duplicates must be detectable and mergeable, never auto-merged or silently rejected.
- **Files changed:** 8 migrations; 9 models; 8 factories; `MrnIssuer`, `DuplicateDetector`, `PatientTimeline`; 13 FormRequests; 7 controllers; `AccessCheck::patientChild`; seeder permissions (`patient:*`, `insurance:*`, `consent:*`, `document:*`, `payer:*`) and role grants; routes; `EncryptedString` cast reuse.
- **Database changes:** 8 new tables + indexes above.
- **API changes:** registration (duplicate candidates in `meta.duplicates`), search (`pg_trgm`), merge (transactional, audited, children reassigned), identifiers/contacts/policies/consents/documents/timeline endpoints under `/api/v1`.
- **Security changes:** identifier values encrypted at rest; audit payloads carry facts (mrn, facilityId), never phone/email/identifier values.
- **Tests:** 5 new suites (registration/MRN, identifiers+contacts, insurance/consent/document, search/merge, isolation) — 43 patient tests green.
- **Known issues:** `uq_identifiers_tenant_type_hash` was originally unique, which silently blocked duplicate registration; corrected to a non-unique detection index in the migration before release.
- **Risks:** document upload remains metadata-only by design until storage integration.
- **Next steps:** Phase 6/7 (Front Desk + OPD) and the billing/payment spine.

### 2026-08-11 — Phase 5: full suite green on real PostgreSQL

- **Date:** 2026-08-11
- **Phase:** Phase 5 (Patient Master)
- **Task:** Run the complete suite against real PostgreSQL; fix every defect.
- **Decision:** Six real defects found and fixed: (1) `DuplicateDetector` returned `Support\Collection` under an `Eloquent\Collection` return type; (2) missing `DB` import in `PatientIdentifierController`; (3) two closures captured variables missing from their `use` lists (`$context` in `PatientContactController::update`, `$patient` in `ConsentController::revoke`); (4) the merge route param `{patient}` vs method param `$source` silently autowired an EMPTY model (the same footgun class as Phase 4's `$member`); (5) `HasFacilityContext` did not validate the proposed facility belongs to the caller's tenant (422 vs 403); (6) `isset()`-on-null dropped `facility_settings` audit facility ids (`array_key_exists`).
- **Reason:** Every failure was a real correctness or isolation gap, not a flaky assertion.
- **Files changed:** `DuplicateDetector`, `PatientIdentifierController`, `PatientContactController`, `ConsentController`, `PatientController`, `HasFacilityContext`, `AuditLogger`, plus test fixes.
- **Database changes:** none.
- **API changes:** none.
- **Security changes:** the cross-tenant facility check now fails at validation (422) instead of leaking authorization state; audit facility resolution fixed.
- **Tests:** full suite — 181 passed, 1119 assertions on real PostgreSQL.
- **Known issues:** none.
- **Risks:** none new.
- **Next steps:** Phase 6/7 clinical workflow.

### 2026-08-11 — Phase 6/7: the first complete clinical workflow (schema)

- **Date:** 2026-08-11
- **Phase:** Phase 6 (Front Desk) + Phase 7 (OPD) + billing/payment spine
- **Task:** Implement the complete vertical slice: Patient → Appointment → Check-in → Queue → Doctor → Encounter → Clinical documentation → Diagnosis → Prescription → Billing → Payment → Audit.
- **Decision:** Fifteen new tables: `schedule_templates`, `schedule_exceptions` (availability is DERIVED, never stored — SlotService), `appointments` (partial unique index on live statuses = row-locked double-booking guard), `token_counters` (row-locked queue tokens, mirroring mrn_counters), `encounters` (one per appointment; signed = immutable), `diagnoses`, `clinical_notes` (self-referencing amendment chain), `medications` (formulary; prices in minor units), `prescriptions` + `prescription_lines`, `charges` (posted = immutable; void is a status), `invoices` + `invoice_lines` (frozen snapshots; a charge is invoiced at most once), `payments` (idempotent per key) + `payment_allocations` (one per payment/invoice). Two support migrations add the composite-FK unique indexes PostgreSQL requires on referenced column sets (staff, services pre-existing; appointments/encounters/medications/prescriptions/charges/invoices/payments in their own migrations).
- **Reason:** The vertical slice is the architecture proof (ROADMAP.md Phase 6 Milestone M1): every step uses real records and real APIs, no simulated success.
- **Files changed:** 17 migrations (incl. 2 support); 14 models; 13 factories; `SlotService`, `TokenIssuer`, `BillingService`; 10 FormRequests; 6 controllers (Schedule, Appointment, Encounter, Medication, Billing); routes; seeder permissions (`schedule:*`, `appointment:*`, `queue:view`, `encounter:*`, `medication:*`, `billing:*`) and role grants; `AccessCheck::staff`; `User::staff()`; `AuditLogger` facility-scoped resource map extended.
- **Database changes:** 15 new tables; composite-FK support indexes.
- **API changes:** 27 new endpoints under `/api/v1`: schedule templates/exceptions/availability, appointment book/check-in/cancel/queue, encounter start/notes/sign/diagnoses/prescriptions/sign/invoice/charges, formulary CRUD, invoice show/payments/pay.
- **Security changes:** signed encounters and notes are immutable (no UPDATE path on clinical content); only the encounter's provider can document (403 otherwise); payments idempotent per key; invoices optimistic-locked against concurrent allocation; slot booking validated against derived availability AND raced on the unique index; audit payloads carry financial facts and encounter references, never clinical content.
- **Tests:** 4 new suites (AppointmentBooking, EncounterClinical, BillingPayment, ClinicalIsolation) + the ClinicalWorkflowE2E vertical slice.
- **Known issues:** the dev roles predated the new grants — the seeder re-run refreshed them; `appointments/queue` must precede the `{appointment}` wildcard route (implicit binding would swallow it).
- **Risks:** the queue view and token issuance are per (provider, date); a full queue board across providers is a Phase 6 extension.
- **Next steps:** full suite run, live smoke, documentation.

### 2026-08-11 — Phase 6/7: tests green, live end-to-end smoke, documentation

- **Date:** 2026-08-11
- **Phase:** Phase 6/7 + billing spine
- **Task:** Run the full suite, fix every defect, then verify the complete workflow over live HTTP.
- **Decision:** Real defects found and fixed: (1) StaffFactory's nested default department created a department whose tenant_id was a facility id (broken composite FK) — rewrote the factory with the facility as anchor; (2) `token_counters` insert needed an explicit UUIDv7 id; (3) `appointments/queue` route ordering; (4) re-invoicing the same charge now returns a clean 409 (pre-check before the unique index) instead of a raw 500; (5) doctor role was missing `encounter:prescribe`; (6) AuditLogger lacked the Phase 6/7 resource types, so org-scoped actors' audit events lost facility ids. Live HTTP smoke walked the whole chain: register (MRN-000001) → book → check-in (token 1) → queue → start encounter → note → diagnosis (J06.9) → prescription (Paracetamol 15×) → note sign → encounter sign → invoice (NPR 80,000 = 50,000 consultation + 30,000 medication) → payment (paid) — every step audited in order. Smoke data force-cleaned from the dev DB.
- **Reason:** Tests passing is not the system working; the HTTP walk proves the full slice against the real server and database.
- **Files changed:** StaffFactory, TokenIssuer, BillingService, EncounterController, routes, seeder, AuditLogger, test fixes.
- **Database changes:** none (smoke rows removed).
- **API changes:** none.
- **Security changes:** re-invoice pre-check; audit facility resolution for clinical resources.
- **Tests:** full suite — 201 passed, 1475 assertions on real PostgreSQL. Pint clean.
- **Known issues:** none.
- **Risks:** the smoke bootstrap still uses the ORM (provisioning runbook remains open); DB-level RLS still open.
- **Next steps:** per ROADMAP Phase 7 completion items (follow-up, investigations, amendments), then Phase 8 (IPD); still open from M0/Phase 3: git init, ADR-001, CI, DB-level RLS, MFA TOTP, provisioning runbook.

---

### 2026-08-11 — Core OPD workflow hardening: failure-path validation

**Phase:** 6/7 — Hardening and validation of the first complete clinical workflow (no new business modules).

**Task:** Systematically verify every step of the workflow (patient → appointment → check-in → queue → encounter → documentation → diagnosis → prescription → billing → payment → audit) against the design contract, prove the failure paths, and produce the validation report.

**Decision:** Do not trust the green suite or the UI claims — trace each step through the actual request chain (frontend → API → auth → tenant context → validation → service → DB → response) and add the missing failure-path tests the user explicitly required.

**Reason:** The workflow was feature-complete but its negative space (expired sessions, malformed requests, invalid transitions, wrong roles, cross-tenant writes, concurrent races) was only partially proven. Healthcare software is judged by what it refuses, not only by what it does.

**Files changed:**
- `backend/app/Http/Controllers/Api/AppointmentController.php` — booking now returns 404 NOT_FOUND for an unknown patient id instead of a 500 (the find() could return null and AccessCheck::scoped() requires a model).
- `backend/tests/Feature/WorkflowFailurePathTest.php` — new suite (10 tests, 57 assertions).

**Tests added (all green):**
- Expired access token → 401 INVALID_TOKEN on workflow endpoints.
- Malformed booking requests → 422 VALIDATION_ERROR with structured per-field details (endsAt ≤ startsAt, non-UUID patientId, empty payload).
- Booking with an unknown patient → 404; cross-tenant patient → 403 SCOPE_DENIED.
- Unknown and out-of-tenant appointments → 404 on read, 403 on write (check-in/cancel).
- Invalid status transitions → 409: encounter from a booked (not checked-in) appointment, double check-in, cancel/check-in of a completed appointment, re-signing a signed encounter, clinical content on a signed encounter.
- Wrong-role gates → 403: nurse cannot prescribe, doctor cannot collect payment, nurse cannot sign.
- Missing clinical content → 422: prescription with zero lines, diagnosis without description, note with empty content, prescription referencing an inactive (non-formulary) medication.
- Cross-tenant payment → 403; zero-amount payment → 422; missing idempotency key → 422.
- Concurrent double-booking race → 409 CONFLICT via the partial unique index; exactly one row survives.
- Token issuance: 25 rapid sequential tokens are unique and contiguous; per-day reset works.

**Defects found and fixed:**
1. `AppointmentController::store()` — an unknown `patientId` crashed with a 500 (TypeError: `AccessCheck::scoped()` got null). Now returns 404 NOT_FOUND. This was invisible to the existing suite because every test booked an existing patient.

**Verification performed (not assumed):**
- Full code walk of the request chain: routes → `auth:sanctum` → `ResolveTenantContext` → `authorize:` gate → FormRequest → service → DB → Envelope response.
- Confirmed every endpoint is permission-gated (no unauthenticated or un-authorized path), every query is tenant-scoped, reads out of scope return 404, writes 403.
- AuditLogger covers all 17 workflow actions with facts only (no PHI, no clinical content) and facility-scoped resource resolution; hash chain serialized via advisory lock.
- Token issuance is row-locked (`SELECT … FOR UPDATE` on `token_counters`); the double-booking arbiter is the partial unique index `uq_appointments_tenant_provider_start`.
- **Frontend:** no frontend exists in this repository — the React SPA is designed-not-built (README §stack table). There is nothing to fake: the workflow is API-complete and was verified over live HTTP with real requests and real database rows.
- Live HTTP verification (dev server + real PostgreSQL): expired token → 401; malformed booking → 422 with details; unknown patient → 404 (after fix); full success chain walked end-to-end (register → book via derived availability → check-in token 1 → queue → encounter → note → diagnosis → prescription → sign → invoice NPR 800 = 500 consult + 300 medication → payment → paid invoice); wrong-role nurse prescription denied earlier in the run; every step wrote real rows and audit events.
- Smoke data was removed from the dev database after verification (org, facility, staff, patient, appointments, encounters, invoices, audit events, counters — deleted in FK order).

**API changes:** none (contract unchanged; one bug fix to an error response shape: 500 → 404).

**Database changes:** none.

**Security changes:** the 404-for-unknown-patient fix closes a minor information/robustness gap (a crash instead of a controlled 404).

**Known issues:** the audit events for the smoke run were deleted with the smoke tenant (correct — dev only); a handful of fixture users remain in the dev DB from provisioning (harmless, no org assignments). True parallel-request load testing (two processes hammering the same slot) remains a load-test item per `TESTING_STRATEGY.md` — the race is proven deterministically via the unique-index arbiter.

**Risks:** no new ones; the workflow's negative space is now under test, which reduces the risk of regression in the clinical spine.

**Next steps:** the validation report (`CORE OPD WORKFLOW VALIDATION REPORT` in this session's findings) is the M1 acceptance input; the M2 (pilot) cut — including the first frontend slice — is the next roadmap milestone. Remaining open (unchanged): no git repository, no ADR-001, no CI/CD, no Docker, no deployment; DB-level RLS, MFA TOTP, provisioning runbook.

### 2026-08-11 — Fixing the issues identified in the CORE OPD WORKFLOW VALIDATION REPORT

**Phase:** 6/7 — Issue remediation from the validation report (no new modules).

**Task:** Work through every issue the validation report identified: reproduce → root cause → fix → regression test → affected tests → full suite → isolation → authorization → audit.

**Issue 1 — booking an unknown patient id crashed with 500 (REPORT §15.1/§16.1).**
- *Reproduced:* temporarily reverted the guard in `AppointmentController::store()`; the regression test failed exactly as reported — `Expected response status code [404] but received 500`, `TypeError: AccessCheck::scoped(): Argument #1 ($model) must be of type Illuminate\Database\Eloquent\Model, null given`.
- *Root cause:* `Patient::query()->find($patientId)` returns `null` for an unknown id; `AccessCheck::scoped(null, …)` hits the `Model` type hint — a crash instead of the contract's 404-for-unknown-resource semantics. The original suite never booked an unknown patient, so the defect was invisible.
- *Fix:* explicit `null` guard before the scope check — `404 NOT_FOUND / Patient not found.` (already present from the validation pass; verified by revert-and-restore).
- *Regression test:* `WorkflowFailurePathTest` → "refuses booking with an unknown or cross-tenant patient" (unknown → 404, cross-tenant → 403).
- *Affected tests:* the test now fails with the reverted code and passes with the fix (proven both ways).
- *Isolation/authorization/audit:* cross-tenant patient at booking → 403 SCOPE_DENIED (same test); unknown resource never leaks existence (404 for reads/unknowns, 403 only for in-scope-but-unauthorized writes); no audit event is written for a rejected booking (correct — nothing changed state).

**Report §17 gap — true parallel-request race verification (not yet run).**
- *Slot double-booking race:* started the API server and fired **10 genuinely parallel bookings** for the same slot from separate processes → **exactly one 201, nine 409 CONFLICT**; the database held **exactly one appointment row and exactly one `appointment.booked` audit event**. The partial unique index `uq_appointments_tenant_provider_start` is the arbiter, exactly as designed.
- *Queue token race:* checked in three appointments **in parallel** → tokens 1, 2, 3 issued, **all unique** (row-locked `token_counters` counter, `SELECT … FOR UPDATE`).
- *Why not an in-suite parallel test:* a second DB connection inside `RefreshDatabase` cannot see the test transaction's uncommitted FK parents, so a two-connection race would be testing fixtures, not the workflow. The deterministic unique-index race test remains in the suite (`WorkflowFailurePathTest` → "wins the concurrent double-booking race via the unique index") and the live parallel run above proves the real-world behavior. This is recorded as load-verified, and a true multi-process load test remains a CI/load-testing item per `TESTING_STRATEGY.md`.

**Report §17 gap — fixture users left in the dev database from smoke provisioning.**
- Six orphaned users (0 role assignments, staff links gone with their deleted smoke orgs) plus 21 orphaned personal-access tokens and 12 orphaned refresh-token rows were removed. Dev DB now holds **0 users / 0 orgs / 0 patients / 0 appointments / 0 orphan auth rows**.

**Files changed:** none in application code this pass (the Issue-1 fix already landed with the validation pass; this pass proved it by revert-and-restore). `DEVELOPMENT_LOG.md` updated. Smoke/race data removed from the dev DB in FK order.

**Tests:** full suite **211 tests / 1532 assertions green** against real PostgreSQL; Pint clean (274 files).

---

### 2026-08-11 — Tenancy V2: Branch, PostgreSQL RLS, database roles, platform access control

**Phase:** Platform Foundation hardening (TENANCY V2). No new HMS business module.

**Task:** Make the multi-tenant security model production-grade: introduce the Branch level of the tenancy hierarchy, implement PostgreSQL row-level security as a database-layer isolation guarantee, move the runtime onto a non-owner least-privilege role, replace blanket platform-admin data access with an explicit audited support-session mechanism, and prove the whole OPD workflow still runs under RLS.

**Decision — canonical terminology (TENANCY.md §0/V2 §4):** `organization → facility → branch → operational resources`. `facility` is the tenant's physical site; `branch` is the operational sub-unit under a facility (existing `locations` already carried a reserved `branch_id`). No duplicate concepts (`hospital`, `site`) were introduced.

**Branch hierarchy implemented:**
- `branches` table (tenant + facility scoped, composite FK), `Branch` model + factory, `BranchController` + `Store/UpdateBranchRequest` with `HasBranchContext` facility/branch scope validation, CRUD routes under `authorize:branch:*`, audit events, `X-Swasthya-Branch` proposal header (403 `BRANCH_DENIED` when outside scope, `TENANCY.md` §3 rule 5).
- `branch_id` added where the domain requires it: `departments`, `locations`, `wards`, `rooms`, `beds` (branch-scoped catalogs). Not added to clinical/financial records — branch is a grouping, not a hard data boundary (`TENANCY.md` V2 §5).
- `role_assignments` already carried `branch_id`; the `branch_manager` role scope was corrected to facility in the seeder.

**PostgreSQL RLS implemented (`2026_08_11_100100_enable_row_level_security.php`):**
- RLS enabled with per-operation policies on **all 36 tenant-owned tables** (tenants-only, tenant+facility, tenant+facility+branch categories), plus `audit_events` (append-only: SELECT dual platform/tenant policy, INSERT only, no UPDATE/DELETE policy — the app role cannot edit or erase history), `role_assignments` (principal reads its own rows via `app.user_id`; platform rows vs tenant rows), `support_sessions` (owner or platform only).
- Policies read the request-scoped GUCs `app.tenant_id`, `app.facility_id`, `app.branch_id`, `app.user_id`, `app.is_platform` — set by the tenant-context middleware with `set_config(..., is_local=true)` inside **one transaction per request**, so a reused/pooled connection can never leak context (TENANCY.md V2 §7). UPDATE `WITH CHECK` pins rows to the current tenant AND facility — a context can neither move a row into another tenant nor another facility.
- `facilities` carries an authorization join (`EXISTS` on `role_assignments` where `user_id = app.user_id`): the login payload and facility picker resolve the principal's own facilities before any tenant GUC exists (the client proposes `X-Swasthya-Facility`; it never asserts it).
- `users`, `roles`, `permissions`, `role_permissions`, `personal_access_tokens`, `refresh_tokens`, `organizations` are intentionally NOT RLS-scoped: identity and the tenant boundary must resolve before a tenant context exists (auth, login, context resolution); their authorization stays in the application layer (documented matrix in `DATABASE.md` §1.5).

**Database roles (`database/security/roles.sql`):**
- `swasthya_app` — the runtime application role: LOGIN, NOSUPERUSER, NOBYPASSRLS, NOINHERIT, DML grants on every table/sequence (with default privileges so future migrations need no grant bumps). Documented role matrix: migration/owner (`swasthya`) vs application (`swasthya_app`) vs future read/reporting role — the runtime role can neither own tables nor bypass RLS (SECURITY.md §14).

**Tenant context (TENANCY.md V2 §7) — `ResolveTenantContext` rewritten:**
- Context is derived server-side from the authenticated principal's active role assignments; `X-Swasthya-Facility` / `X-Swasthya-Branch` are validated proposals. Platform assignment without a support session → platform context with **empty tenant GUC** (the database refuses tenant rows); with an active session → support context (session's org/facility + read-only `support_agent` role).
- Database projection: every request runs in one transaction; GUCs are LOCAL so they die with the transaction; explicit `resetAll()` belt-and-braces reset. Login/refresh (public routes) resolve the principal's own assignments and facilities in a user-scoped transaction (`app.user_id` set) — the RLS policy reveals exactly the principal's own rows.
- **Middleware-order bug found and fixed:** Laravel 11's `SubstituteBindings` (implicit route model binding) lives in the framework `api` group and priority-sorted BEFORE the route's `ResolveTenantContext`, so every `/{model}` route queried its bound model with empty GUCs and 404'd under the app role. Fixed in `bootstrap/app.php` by raising `ResolveTenantContext` above `SubstituteBindings` in the middleware priority. Feature tests (schema owner, RLS bypassed) cannot see this failure — added a structural regression test asserting the priority ordering.

**Platform administration (TENANCY.md §9/V2 §8):**
- Platform admins manage the SaaS platform; they get **no automatic access to tenant data**. Platform-scope permissions are restricted to the `platform/*` route namespace (provisioning, platform role assignment, support-session management).
- Controlled support access (`PlatformSupportController`): open a support session against an explicit target organization/facility with a required reason, time-limited expiry, and full audit; `support_agent` role is read-only in the tenant. PlatformAssignmentController grants `superadmin` via an explicit audited endpoint. No "bypass everything" permission exists.

**Tests (Tenancy V2 suites):**
- `BranchTest` — branch CRUD, facility/branch scope, authorization, audit.
- `PlatformAccessControlTest` — platform scope vs tenant scope, support sessions (open/expired/denied), no tenant data access without a session.
- `DatabaseRowLevelSecurityTest` — connects as `swasthya_app` and proves the ENGINE isolates: cross-tenant read/update/delete denied, tenant/facility/branch escape via UPDATE WITH CHECK rejected, no-context safe failure, audit append-only + platform/tenant split, role-assignment self-resolution, facilities authorization join, two independent concurrent connections with different tenants stay isolated, support-session visibility, and the middleware-priority ordering regression.
- `TenantContextDatabaseTest` — GUC lifecycle (set/reset, no leakage after rollback).
- RLS is verified against the real test database on a dedicated `pgsql_rls` connection (`.env.testing` `RLS_DB_*`); the `swasthya_app` role is created cluster-wide by `database/security/roles.sql`.

**Live verification as the least-privilege role:** started the API server with `DB_USERNAME=swasthya_app` (RLS active) and walked the **complete OPD workflow over real HTTP**: login (assignments payload with facility name — the RLS login fix) → patient registration (MRN issued) → doctor availability → booking → check-in (token) → encounter → clinical note (signed) → diagnosis → prescription → encounter sign → invoice (5000 consultation + 3000 medication = 8000 minor) → payment (captured, invoice paid) → audit trail showing every step with actor + facility. Cross-facility header proposal → 403 `FACILITY_DENIED`; cross-branch → 403 `BRANCH_DENIED`; no token → 401. All live probes passed. Brute-force lockout and per-IP auth throttle also fired during probing — as designed.

**Bugs found and fixed this pass:**
1. Login returned `assignments: []` under RLS (public route queried `role_assignments` without `app.user_id`) — fixed with a user-scoped transaction in `AuthController`.
2. Login payload facility names were null under RLS (tenant-scoped `facilities` invisible pre-context) — fixed with the facilities authorization-join policy.
3. **Every `/{model}` route 404'd under RLS** — `SubstituteBindings` ran before the tenant-context middleware (middleware-priority fix in `bootstrap/app.php`).

**Files changed:** `database/migrations/2026_08_11_100000_tenancy_v2_schema.php` (permission scope, branches, support sessions, audit linkage), `database/migrations/2026_08_11_100100_enable_row_level_security.php`, `database/security/roles.sql`, `app/Models/{Branch,SupportSession}.php`, `app/Support/{TenantContext,DatabaseTenantContext,AuditLogger,ErrorCodes,Envelope,AccessCheck(FacilityScope)}.php`, `app/Http/Middleware/ResolveTenantContext.php`, `app/Http/Controllers/Api/{BranchController,PlatformSupportController,PlatformAssignmentController,OrganizationController,UserController,RoleAssignmentController,AuthController,DepartmentController,LocationController,WardController,RoomController,BedController}.php`, catalog requests + `HasBranchContext`, `database/seeders/RolePermissionSeeder.php`, `routes/api.php`, `bootstrap/app.php`, `config/database.php` (`pgsql_rls`), `.env`/`.env.testing` (`RLS_DB_*`), `tests/Feature/{BranchTest,PlatformAccessControlTest,DatabaseRowLevelSecurityTest,TenantContextDatabaseTest,RolePermissionMatrixTest}.php`, `README.md`, `TENANCY.md`/`DATABASE.md`/`SECURITY.md`/`ARCHITECTURE.md` (already speced V2; README corrected from "RLS pending" to implemented).

**Tests:** full suite **241 tests / 1742 assertions green** against real PostgreSQL (incl. the new Branch, platform-access, RLS, tenant-context, and middleware-ordering suites); Pint clean (293 files).

## 4. Known Issues, Risks, and Next Steps (as of 2026-08-11)

**Known issues (unresolved, recorded honestly):**

1. No git repository exists — the implementation is unversioned files in a folder (every commit/PR/branch rule in `MASTER_RULES.md` §16 is therefore not yet enforceable).
2. ADR-001 (stack ratification) has not been written; the technology decisions exist only as document recommendations.
3. No CI/CD pipeline, Docker/container deployment, or managed PostgreSQL deployment exists — everything runs against a local PostgreSQL 16 dev/test instance with the toolchain in `.toolchain/`.
4. Legal counsel engagement (privacy law 2075 and health-sector obligations) has not begun.
5. MFA TOTP flow, breach-list checking, and a secrets store (`SECURITY.md` §3, §13) are designed but not implemented.
6. Design-system color tokens are unvalidated against WCAG AA contrast on real devices; clinical authority review of `CLINICAL_SAFETY.md` is pending.
7. The database migration/owner role is a local dev superuser; the documented production split (migration role vs `swasthya_app` runtime role) is implemented and tested locally but not deployed.
8. A true multi-process load test and the RLS leakage suite as a CI gate remain pending (`TESTING_STRATEGY.md`).

**Risks:**

1. Without ADR-001, the single-responsibility-per-technology rule is unprotected — framework sprawl is the top architectural risk.
2. Compliance claims (privacy, clinical, billing) remain unverified; nothing may claim compliance until assessed.
3. RPO/RTO are targets, not achievements; the backup pipeline and drills do not exist.
4. The frontend (React SPA) is designed but not built; every workflow verified so far is API-level.

**Next steps (per ROADMAP.md):**

1. Write and ratify **ADR-001** (the stack decision the documents reference).
2. Initialize the repository: `git init`, `.gitignore`, commit the implementation and documentation.
3. Engage legal counsel on compliance context (Phase 0 remaining item).
4. Implement MFA TOTP and the remaining `SECURITY.md` required controls.
5. Build the CI pipeline (lint → unit → integration → RLS leakage suite as gate) per `DEPLOYMENT.md`.
6. Keep this log — every future entry appends to Section 3.

**Updated after the Phase 2 foundation work (same day, append-only):** the items above were recorded when this log opened and are not rewritten; the Section 3 entries now correct the record going forward. State changes: application code and configuration now exist under `backend/` (Phase 2 scope only — no business modules); a real PostgreSQL 16.4 dev/test environment exists via the gitignored `.toolchain/`; a 36-test foundation suite runs green against it. Unchanged: no git repository, no ADR-001, no CI/CD, no Docker, no deployment — these remain the open M0 items and are recorded as such above.

**Updated after the Phase 3 work (same day, append-only):** Phase 3 (Identity and Tenancy) is now implemented: organizations, facilities, users, token auth with refresh rotation, roles/permissions/assignments, tenant + facility context, authorization gates, and the append-only hash-chained audit — **102 tests, 478 assertions green against real PostgreSQL**, with the six defects found and fixed recorded in the Section 3 entries above. Still open (unchanged): no git repository, no ADR-001, no CI/CD, no Docker, no deployment; and now explicitly recorded as next steps — database-level RLS (FORCE + non-owner app role + GUC), the MFA TOTP flow, and the idempotent tenant-provisioning runbook.

**Updated after the Phase 4 work (same day, append-only):** Phase 4 (Hospital Administration) is now implemented: departments, locations, wards → rooms → beds, staff profiles (license encrypted at rest), the hospital services catalog, and versioned facility configuration — all tenant-scoped with tenant-safe composite FKs, all endpoints permission-gated and facility-scoped, every write audited. The bed state machine and optimistic locking are enforced and tested; the logical model gained `services` (§3.43) and `facility_settings` (§3.44). **138 tests, 796 assertions green against real PostgreSQL**, live HTTP smoke verified (including cross-facility 404/403 isolation), Pint clean. Still open (unchanged): no git repository, no ADR-001, no CI/CD, no Docker, no deployment; DB-level RLS, MFA TOTP, provisioning runbook, and now the tenant-provisioning runbook dependency for smoke bootstrapping.

**Updated after the Phase 5 work (same day, append-only):** Phase 5 (Patient Master) is now implemented: registration with atomic per-tenant MRN issuance, encrypted identifiers with deterministic duplicate detection (duplicates surface as candidates, never auto-merge), contacts, insurance policies, versioned consents, document metadata, and the patient timeline — all tenant-scoped, all audited. **181 tests, 1119 assertions green against real PostgreSQL**, Pint clean.

**Updated after the Phase 6/7 work (same day, append-only):** The first complete clinical workflow is implemented and verified end to end: register → book → check-in (token) → queue → encounter → note → diagnosis → prescription → sign → invoice → payment → audit. Fifteen new tables; availability is derived, slot booking is double-booking-proof under concurrency, tokens are row-locked, signed encounters/notes are immutable, payments are idempotent per key, invoices are optimistic-locked. **201 tests, 1475 assertions green against real PostgreSQL**; the full slice was walked over live HTTP and every step audited. Still open (unchanged): no git repository, no ADR-001, no CI/CD, no Docker, no deployment; DB-level RLS, MFA TOTP, provisioning runbook.

**Updated after the hardening/validation pass (same day, append-only):** The workflow's failure paths were systematically proven with a new 10-test suite (expired sessions, malformed requests, unknown/cross-tenant resources, every invalid status transition, wrong-role gates, missing clinical content, cross-tenant payment, the concurrent double-booking race, and token uniqueness under rapid issuance). The pass caught and fixed one real defect — an unknown patient id at booking crashed with a 500 instead of a controlled 404. Live HTTP verification re-walked the full success chain (register → book → check-in → queue → encounter → note → diagnosis → prescription → sign → invoice NPR 800 → payment) and the failure paths; smoke data was removed from the dev database afterward. **211 tests, 1532 assertions green against real PostgreSQL**, Pint clean. The frontend (React SPA) remains designed-not-built — the workflow is API-complete and API-verified.

### 2026-08-11 — Staging readiness validation: CI, load test, backup/restore drill, staging spec

**Task:** validate the Tenancy V2 verdict (READY FOR STAGING) with staging-readiness work only — no new business modules.

**Live OPD workflow re-run (real HTTP, `swasthya_app` under RLS):** the complete chain was walked again against the running API: health (live + ready) → login (assignments payload with facility) → patient registration (MRN issued) → availability → booking → check-in (token) → doctor queue view → encounter → clinical note → diagnosis → prescription → sign note → sign encounter → invoice (total 32000 minor = 5000 consultation + 3000×9 medication qty) → payment (captured; idempotent replay returned `payment.replayed` without a second row) → audit trail (every step present with actor + facility) → patient timeline → isolation probes (no-token 401 INVALID_TOKEN, foreign-facility proposal 422 VALIDATION_ERROR). Every major step was verified in the database as `swasthya_app` with tenant GUCs. Desktop and mobile: **no frontend SPA exists** (designed-not-built per README/ARCHITECTURE.md), so viewport verification is not possible yet — the workflow is verified at the real-API/real-DB level; this is recorded, not glossed.

**CI/CD pipeline:** repository has no CI provider and no git repo. Added `.github/workflows/ci.yml` (PHP 8.2/8.3 matrix, disposable `postgres:16` service container, Pint, `roles.sql` (NOBYPASSRLS), `migrate:fresh`, RLS policy/role verification, full Pest suite incl. the RLS connection as `swasthya_app`, build artifact) and `backend/ci/run-local-ci.sh` — the same pipeline against a disposable local `swasthya_ci` database. The local pipeline was executed and PASSED end-to-end (241 tests / 1,742 assertions green; disposable DB dropped after).

**RLS load test (synthetic, disposable `swasthya_load`):** seeded 10k → 100k → 1M patients (+ appointments/encounters/diagnoses/prescriptions/invoices/payments ≈ 2.9M rows, DB 1.2 GB; 20 orgs × 2 facilities). Benchmark harness `ci/load-benchmark.sh` runs each query under RLS (`swasthya_app` + GUCs) and a controlled baseline (owner, RLS disabled, re-enabled after). Results at 1M patients: point lookups (patient-by-id, appointment-by-id, provider-day, encounters-by-patient) sub-millisecond to ~4 ms with RLS predicates folded into index conditions; inserts 0.6–2.7 ms; update ~27 ms; delete <1 ms. The one hot spot: patient name ILIKE search ~57 ms under RLS vs ~1 ms baseline — the facility-scope RLS predicate (`facility_id = GUC OR GUC IS NULL`) prevents the trgm index from being used with a facility prefix (tested composite GIN indexes with btree_gin; planner still ignores the facility component because of the OR-NULL clause). Decision: **no new index** — the search is paginated and sub-100 ms; the finding is documented for a future facility-required-context refinement. Concurrent two-connection probe: tenants 7 and 11 each saw exactly their own 25,000 rows — no leakage.

**Backup/restore drill (`ci/backup-restore-drill.sh`):** real `pg_dump -Fc` of the dev DB (292,744 bytes, 1 s) → restore into a clean `swasthya_restore` (1 s) → verified: 50 tables, 47 migrations, data (patients/appointments/144 audit events), 144 RLS policies (source = restored), RLS enabled, `swasthya_app` NOBYPASSRLS, and the full OPD chain intact (completed → signed → paid → captured). Isolation re-verified on the restored DB: with context → 1, without context → 0, wrong tenant → 0. **Real finding fixed:** pg_dump does not preserve roles or ALTER DEFAULT PRIVILEGES, and a `--no-privileges` restore silently left `swasthya_app` with no access despite intact policies. Added `database/security/grants.sql` (idempotent post-restore grants fixup mirroring the RLS migration) and the drill now runs roles.sql + grants.sql after restore. RPO (dev): on-demand backup, no WAL archiving — stated, not claimed. RTO (measured here): ~1 s restore + role re-creation.

**Security regression:** full suite re-run green — **241 tests / 1,742 assertions**; Pint clean (293 files). Targeted suites included: authentication, RBAC, org/facility/branch isolation, RLS (database-level, as `swasthya_app`), privileged support access, audit, concurrency, workflow failure paths.

**Observability / health:** verified live — `/api/v1/health/live` (liveness, no downstream) and `/api/v1/health/ready` (real DB check, 503 with failing checks); structured JSON logs carry `request_id` + `correlation_id` per request; auth lockouts and authorization denials are logged. Never-log check: one dev-only artifact (a tinker SQL error during earlier password-reset debugging logged a bound value) — flagged as a finding; production should ensure DB exception binding logging is off (documented in the staging report, not fixed in code because it was a dev tool invocation, not application behavior).

**Staging environment:** none exists (verified: no `.env.staging`, Dockerfile, compose, or IaC). Produced `STAGING.md` — the concrete build spec (services, env vars, DB roles/bootstrap, storage, secrets, networking, TLS, health checks, logging, monitoring, deployment steps, acceptance checklist) and `DEPLOYMENT.md` §4 remains the design.

**Files added:** `.github/workflows/ci.yml`, `backend/ci/run-local-ci.sh`, `backend/ci/load-seed.sql`, `backend/ci/load-benchmark.sh`, `backend/ci/load-benchmark.sql`, `backend/ci/backup-restore-drill.sh`, `backend/database/security/grants.sql`, `STAGING.md`, `STAGING_READINESS_REPORT.md`.

**Known issues / remaining risks:** (1) no git repository and no CI provider — the GitHub Actions workflow is written but has never run on a real runner; (2) no staging environment exists — STAGING.md is a spec; (3) patient-name search is the RLS hot spot (~57 ms at 1M rows) — acceptable, documented; (4) the frontend SPA remains designed-not-built, so mobile/desktop viewport verification is still impossible; (5) dev DB logs hold one dev-debugging artifact with a bound hash value (flagged above); (6) MFA, secrets store, compliance assessment remain open per previous entries.

**Next steps (unchanged, per ROADMAP):** ADR-001, `git init` + first commit, CI runner wiring, staging environment build per STAGING.md, MFA TOTP, legal counsel.

---

### 2026-08-11 — Frontend foundation + OPD web application (React SPA)

**Task:** build the real Swasthya web application and connect it to the existing backend — the frontend half of the verified OPD workflow. No new business modules.

**Framework decision (documented in `FRONTEND_FOUNDATION_REPORT.md` §3):** one primary frontend — **React + TypeScript + Vite** (`frontend/`), per `ARCHITECTURE.md` (single SPA, mobile-first). Angular and any second SPA are explicitly not used; the backend remains the sole authority for authorization and tenant context.

**What was built:**
- Design system per `DESIGN_SYSTEM.md`: token-based CSS (`src/styles/tokens.css`, `base.css`) and a reusable component library (`ui.tsx`/`ui.css` — buttons, inputs, selects, dialogs, cards, tables, empty/error/loading states, status badges, money/date formatters).
- Auth against the real backend: login/logout/refresh with rotating refresh tokens, session restore, 401/403/422/429 handling; secrets never stored beyond the session-storage access token + local-storage refresh token used by the API client.
- Server-authoritative tenant context (`TenantContext`): facility derived only from the login/refresh `assignments` payload; auto-select for one facility, explicit chooser for several, and a tenant `ready` gate so no tenant-scoped page mounts (or fetches) before context resolves.
- App shell: desktop sidebar + header with facility context + mobile bottom navigation (5 items, More sheet), RBAC-filtered destinations, breadcrumb-free minimal header per DESIGN_SYSTEM.
- Dashboard with only real backend data (today's appointments, queue, completed) and honest empty states; patients (list/search/register/profile with timeline); appointments (availability-driven booking from provider schedules); queue (check-in with race-safe tokens, doctor queue view); doctor workspace (encounter → clinical note draft/sign → diagnosis → prescription → sign encounter); billing (invoice from the signed encounter, charges, idempotent payment capture with server-confirmed states); authorized audit view (read-only).

**Real bugs found and fixed during E2E hardening (each with a regression test or structural guard where applicable):**
1. **Backend:** `POST /auth/refresh` omitted `assignments` while login included them — a page reload crashed the SPA (`assignments is not iterable`). Fixed in `AuthController` (contract parity with login, user-scoped RLS transaction) + test asserting the refresh payload shape.
2. **Frontend:** pages fired requests with an empty organization id (`/organizations//patients` → 500) before the facility auto-select effect ran. Fixed with a `TenantGate` (facility chooser / resolving state) + `orgUrl()` guard that fails fast instead of issuing tenant-less URLs.
3. **Timeline:** the backend returns structured `summary` metadata; the profile page rendered it as a React child and crashed. Fixed with a `timelineSummary()` formatter + unit tests.
4. **Booking:** the SPA sent `facilityId` in the body; `BookAppointmentRequest` forbids it (header-only tenant proposal) → 422. Fixed in the API client (header only).
5. **useFetch races:** the hook had no stale-response protection, and `refresh()` (`setTick` + `run`) started two requests where the first result was discarded — after check-in the queue could freeze on stale data. Fixed with a request-generation guard and a single-fetch refresh.
6. **Queue UX:** a full-page spinner on refresh unmounted the check-in panel and lost its confirmation notice; the page now keeps stale data visible during refresh (stale-while-revalidate).
7. **Start consultation:** showed a notice instead of entering the workspace; now navigates straight into the encounter.
8. **Queue contract:** `GET /appointments/queue` returns `appointmentId` (not `id`); the SPA posted to `/appointments/undefined/start-encounter` (500). Added a `QueueEntry` type, and the queue endpoint now also returns `encounterId` (eager-loaded) so the in-consultation reopen link works.
9. **E2E design:** the two Playwright projects raced on the same slot (backend double-booking guard correctly rejected one); projects serialized, and each spec now cancels leftover holding appointments for the fixture date through the real cancel API before running (repeatable against a persistent dev DB).
10. **Test env:** Node 26 ships experimental `localStorage` globals that shadowed jsdom's; guarded Storage shim in the test setup.
11. **A11y:** duplicate labels (`Phone` ×2, `Date` vs `Appointment date`) disambiguated (`Emergency phone`, `Consultation date`).
12. **E2E billing:** booking with the empty default service produced no consultation charge; the specs now select the real `OPD Consultation` service, exercising the full charge path (5,000 + 3,000 = 8,000 minor).

**Tests:** frontend — 21 unit/component tests (Vitest + Testing Library; typecheck clean) and 2 Playwright E2E specs against the REAL backend/database (RLS active, `swasthya_app`): the complete desktop OPD workflow (patient → appointment → check-in → queue → encounter → note → diagnosis → prescription → sign → invoice → payment → audit, 58.5 s) and the mobile receptionist flow at the iPhone 13 viewport with the bottom navigation (24.8 s) — both green. Backend suite re-run green after the controller changes: **241 tests / 1,748 assertions**. Dev DB returned to its clean fixture state afterwards (E2E rows removed, 474 append-only audit events retained).

**Environment notes:** the E2E dev server runs with `SWASTHYA_RATE_LIMIT_AUTH=200` (the 5/min per-IP production control would throttle automated runs); the preview run doc (`.freebuff/run.md`) now documents the SPA dev-server procedure.

**Files added:** `frontend/` (Vite + React + TS app: `src/api`, `src/auth`, `src/context`, `src/components`, `src/layout`, `src/pages`, `src/hooks`, `src/styles`, unit tests, `e2e/` Playwright specs, `playwright.config.ts`, `vite.config.ts`), `FRONTEND_FOUNDATION_REPORT.md`. **Files changed:** `backend/app/Http/Controllers/Api/AuthController.php` (refresh assignments parity), `backend/app/Http/Controllers/Api/AppointmentController.php` (queue `encounterId`), `backend/tests/Feature/AuthTest.php` (refresh contract assertion), `README.md`, `DEVELOPMENT_LOG.md`, `.freebuff/run.md`.

**Known issues / remaining risks:** (1) no CI provider and no git repo — the GitHub Actions workflow exists but has never run on a real runner; (2) no staging environment — `STAGING.md` remains a spec; (3) the mobile E2E covers the receptionist flow only — the doctor workspace at mobile viewport is not yet E2E-covered (the desktop spec exercises it); (4) MFA, secrets store, compliance assessment remain open per previous entries.

### 2026-08-12 — Staging status verification pass + verdict correction

**Task:** re-verify exactly how far the staging milestone progressed, applying the strict rule that localhost is never staging, and correct the documentation to match.

**Decision:** classify every staging requirement against the real `STAGING.md` environment standard (host, domain, TLS, managed PostgreSQL, secrets store, real-runner CI), not against the local mirror. Reclassify the staging verdict from READY FOR STAGING to **NOT READY FOR STAGING**.

**Reason:** the earlier `STAGING_DEPLOYMENT_REPORT.md` draft verdict treated the local staging mirror as satisfying the staging requirement. The local mirror proves the procedures work; it is not a deployment. The evidence gathered: git repo with 2 commits and **0 remotes** (CI has never run on a real runner); no `deployment/` or `docker/` directory, no application Dockerfile; no domain/TLS/secrets store/monitoring anywhere; `APP_URL=http://127.0.0.1:58998` is a loopback; the CI frontend job is still uncommitted in the working tree.

**Files changed:** `STAGING_STATUS_REPORT.md` (new — full VERIFIED/PARTIAL/MISSING/FAILED/N-A classification with evidence, 26 sections, verdict NOT READY FOR STAGING), `STAGING_DEPLOYMENT_REPORT.md` (verdict corrected, draft retained for the record), `DEVELOPMENT_LOG.md`.

**Tests:** none run in this pass (inspection/classification only); all prior evidence (241 backend / 1,748 assertions; 4 E2E+a11y tests green; backup/restore drill) remains valid as *local* evidence.

**Known issues / remaining risks:** uncommitted milestone work (20 files); no remote repository; no provider decision. These are now documented as the exact missing requirements and blockers in `STAGING_STATUS_REPORT.md` §23–§24.

**Next steps:** commit the working-tree changes, push to GitHub, run CI on a real runner, then create the real staging host per STAGING.md §2–§9 before re-issuing the status report.

### 2026-08-12 — Staging deployment & production engineering: git, staging mirror, CI/CD, backup/restore drill

**Task:** turn the READY-FOR-STAGING application into a real deployable staging system — repository foundation, a provisioned staging environment, a real-runner CI pipeline, backup/restore drill, observability/performance/accessibility verification. No new business modules.

**Decision:** (1) initialize Git with a clean, secret-free baseline commit; (2) provision a **local staging mirror** (dedicated `swasthya_staging` DB + least-privilege `swasthya_app_staging` role) because no cloud provider is selected yet — the environment is real and verifiable, and STAGING.md remains the provider-agnostic build spec; (3) extend CI with a `frontend` job that runs the full E2E on a disposable PostgreSQL; (4) run a real backup/restore drill against staging.

**Reason:** STAGING.md §2–§9 and DEPLOYMENT.md §8 require a production-mirror staging env and a CI pipeline that fails on any security/tenancy failure; MASTER_RULES.md §21–§25 (git, code review, releases) and §2 (no secrets in source).

**What was built / verified:**
- **Git foundation:** `.gitignore` hardened (`backend/.env*` kept out except `.env.example`; `frontend/.gitignore` added; tsbuildinfo untracked); baseline commit `fd7d77f` + `82f6b51` with **no secrets, no vendor/node_modules/dist/logs** — staged-content pattern scan confirmed.
- **Staging environment (local mirror):** `backend/.env.staging` (+ committed `.env.staging.example`), `swasthya_staging` DB (50 tables, 144 RLS policies, 37 RLS-enabled tables, 47 migrations), `database/security/staging-role.sql` (`swasthya_app_staging` LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT), grants applied, backend on port 58998 as the least-privilege role with `APP_ENV=staging`. Health/liveness + readiness verified; both fixture tenants authenticate.
- **`StagingFixtureSeeder`** (reproducible, refuses production): two synthetic tenants (smoke-group / apex-care) with the full OPD shape — org → facility → department → users → staff (doctor linked to the doctor login, DOC-001) → service → Tuesday schedule → **formulary** (`para-500` Paracetamol, 3000 minor). The formulary was the one piece the dev fixture had that the seeder originally omitted — without it the E2E's medication `selectOption({ index: 1 })` could not resolve, and the desktop staging E2E stalled at the prescription tab (bug found by the staging run, fixed in the seeder, then green).
- **Tenant isolation in staging:** tenant A created a patient; tenant B could not read/search it (API-level); SQL-level RLS probe as `swasthya_app_staging`: no context → 0 rows, wrong tenant → 0, owner → correct count.
- **Staging E2E:** `frontend/playwright.staging.config.ts` (Vite on 5174 → 58998; IPv4 binding fix — `localhost`→`::1` mismatch broke the API helper) — desktop OPD workflow, mobile receptionist flow, and new `accessibility.spec.ts` (axe) all pass against the real staging backend/DB/RLS.
- **Accessibility fixes (real AA defects):** `.muted` text `#64748b` was 4.38:1 on the mist background — darkened to `#627188` (4.96/4.56:1); inline links had no underline and 1.53:1 against parent text — global `a` now underlines by default (WCAG 1.4.1) with nav chrome (`side-nav__item`, `bottom-nav__item`, `more-sheet__item`) opting out. Both a11y specs pass (zero serious/critical).
- **Backup/restore drill (real):** `pg_dump -Fc` → 304,440 bytes (~1 s); restore into disposable `swasthya_staging_restore` (~1 s, exit 0); verified 50 tables / 47 migrations / 144 policies / 37 RLS tables / both tenants / 6 users / 2 meds / 123 audit events; RLS probes hold on the restored copy; app-role grants re-applied post-restore (pg_dump does not carry roles/grants — documented fixup). RPO/RTO explicitly NOT claimed from a local drill.
- **CI/CD:** `.github/workflows/ci.yml` gained a `frontend` job (Node 20, npm ci, typecheck, 20 unit tests, `npm run build`, E2E on disposable postgres:16 via `playwright.ci.config.ts` which also starts the backend as `swasthya_app`). Whole pipeline executed locally as a twin: `backend/ci/run-local-ci.sh` (241 tests / 1,748 assertions) + the CI E2E config against `swasthya_ci` (4 tests green). **Not yet run on a real GitHub-hosted runner** — requires pushing the repository.
- **Observability/perf (staging):** structured logs carry `request_id`/`correlation_id`/tenant/facility/user/duration; no secrets/PII in logs (the single regex hit was a stale dev SQL error naming the `password` *column*). Perf micro-benchmarks on the local staging stack: login ~0.9–1.1 s, tenant-scoped reads (patient search / appointments / queue / me) ~0.35–0.65 s.

**Files added:** `backend/.env.staging` (untracked), `backend/.env.staging.example`, `backend/database/security/staging-role.sql`, `backend/database/seeders/StagingFixtureSeeder.php`, `frontend/playwright.staging.config.ts`, `frontend/playwright.ci.config.ts`, `frontend/e2e/accessibility.spec.ts`, `STAGING_DEPLOYMENT_REPORT.md`, `frontend/.gitignore`, `.github/workflows/ci.yml` (frontend job). **Files changed:** `backend/database/seeders/StagingFixtureSeeder.php` (doctor-staff link fix + formulary), `frontend/src/styles/tokens.css` (muted AA contrast), `frontend/src/styles/base.css` (link underline + bottom-nav), `frontend/src/layout/shell.css` (nav underline opt-out), `frontend/e2e/helpers.ts` (env-driven base URL), `.gitignore`, `STAGING.md`, `DEPLOYMENT.md`, `DISASTER_RECOVERY.md`, `TESTING_STRATEGY.md`, `README.md`, `DEVELOPMENT_LOG.md`. Git: baseline commits `fd7d77f`, `82f6b51`.

**Database changes:** new `swasthya_staging` database + `swasthya_ci` disposable test databases (dropped after drills); `swasthya_app_staging` role; fixture data only (synthetic tenants, never real patient data). No schema migrations — the schema is unchanged from Tenancy V2.

**API changes:** none (contract unchanged; E2E exercised existing endpoints).

**Security changes:** git secret hygiene; staging least-privilege role; WCAG 1.4.1 link distinguishability; never-log verified; backup encryption not yet applied (local drill — the documented production step remains).

**Tests:** backend full suite green on disposable DB (241 tests / 1,748 assertions, local CI twin); frontend 20 unit/component + typecheck clean + production build; staging E2E 4/4 (desktop 1.2 m, mobile 15 s, a11y ×2); CI E2E config 4/4 on `swasthya_ci`; backup/restore drill verified end-to-end.

**Known issues / remaining risks:** (1) CI has never run on a real GitHub-hosted runner (repo not pushed) — this is the top remaining validation; (2) no cloud staging host: TLS, load balancer, secrets store, provider choice remain (STAGING.md §15–16); (3) MFA, secrets store, compliance assessment still open; (4) RPO/RTO unmeasured at production scale — local drill evidence only; (5) staging app-role password is in the local `.env.staging` (untracked) — real staging must use a secrets store.

**Next steps:** push the repository and run CI on a real runner; then deploy the artifact to a real staging host per STAGING.md §15; await architectural review before any next HMS module.

### 2026-08-12 — Real staging infrastructure: repository cleanup audit, halted at external-input boundary

**Task:** begin the REAL STAGING INFRASTRUCTURE IMPLEMENTATION milestone (per the independent NOT READY FOR STAGING verdict) — Phase 1 repository cleanup first, then the git remote / CI / staging-host phases.

**Decision:** complete Phase 1 (working-tree audit + secret hygiene), then **halt at the Phase 2 boundary** and report `GITHUB_REPOSITORY_REQUIRED` rather than fabricate a remote URL, credentials, domain, or provider. Phases 3–16 (real-runner CI, staging host, TLS, secrets store, deploy, staging E2E, backup, monitoring) all depend on inputs only the user can supply.

**Reason:** the milestone's own rules — never invent a GitHub repository URL, never invent domains/IPs/credentials/TLS/secret values, never claim "staging deployed" / "CI passed" without the real artifact. The local mirror and local CI twin remain local evidence only.

**Working-tree audit result (18 files, all KEEP/COMMIT — none REMOVE):**
- *Modified (9):* `.github/workflows/ci.yml` (frontend CI job — must be committed for a real runner to execute it), `frontend/e2e/helpers.ts` (env-driven base URL), `frontend/package.json` + `package-lock.json` (`@axe-core/playwright`), `frontend/src/styles/tokens.css` + `base.css` + `layout/shell.css` (WCAG 1.4.1 fixes), `STAGING.md`, `DEPLOYMENT.md`, `DISASTER_RECOVERY.md`, `TESTING_STRATEGY.md`, `README.md`, `DEVELOPMENT_LOG.md`.
- *Untracked (9):* `STAGING_DEPLOYMENT_REPORT.md`, `STAGING_STATUS_REPORT.md`, `backend/.env.staging.example` (template, no values), `backend/database/security/staging-role.sql` (uses `<staging-secret>` placeholder), `backend/database/seeders/StagingFixtureSeeder.php` (synthetic tenants, refuses production), `frontend/e2e/accessibility.spec.ts`, `frontend/playwright.ci.config.ts`, `frontend/playwright.staging.config.ts`.

**Secret hygiene verified (git check-ignore + pattern scans):** `backend/.env`, `backend/.env.staging`, `backend/.env.testing`, `.env.testing.bak` all ignored; `.env.example` / `.env.staging.example` are un-ignored value-free templates; `ci-owner-password-2026` / `ci-app-role-password-2026` in ci.yml are disposable CI defaults with a `secrets.APP_ROLE_PASSWORD` fallback (documented pattern); `SmokePass-2026!` is the synthetic E2E fixture password (already in the committed baseline); `vendor`, `node_modules`, `dist`, `.toolchain`, `.freebuff`, `*.tsbuildinfo` all ignored.

**Files changed:** `DEVELOPMENT_LOG.md` only at the time of the audit; subsequently (2026-08-12, milestone continuation) the 21 classified files were committed as `9566dd8` (see entry below). **No application code, no clinical workflow, no modules touched.**

**Known issues / remaining risks:** the entire remaining milestone is blocked on external inputs: GitHub repository URL (remote + real-runner CI), staging provider/account, staging domain + DNS + TLS, secrets store, deployment credentials, managed PostgreSQL with WAL archiving, monitoring/alerting platform. Exact requirements are enumerated in `STAGING.md` §15–16 and `STAGING_STATUS_REPORT.md` §23–25.

**Next steps:** on user supply of the GitHub URL → configure the remote, push `main` (commit `9566dd8`), and run the real CI pipeline; then provision the staging host per STAGING.md §2–§9; then re-issue the staging report.

### 2026-08-12 — Real staging infrastructure: push-ready commit + Docker availability check

**Task:** continue the staging milestone from the verified local state: inspect git/remotes/deployment requirements, complete the staging deployment prerequisites that require no external input, and stop at the boundary.

**Decision:** (1) commit the 21 classified files as `9566dd8` (CI `frontend` job, staging mirror config, E2E/a11y coverage, milestone reports) so a real CI runner can execute the pipeline once the repository is pushed; (2) check whether container images can be built locally; (3) stop before configuring a remote / inventing provider or secrets.

**Reason:** the CI frontend job cannot run on a real runner while uncommitted; committing is the one genuine staging prerequisite fully within reach. Docker Desktop has been **uninstalled** from this machine (only `tmp-delete` remnants and install logs remain), so the container image build/verify step cannot be performed here — that remains external work, not fabricated. Staging verdict unchanged: **NOT READY FOR STAGING**.

**Verified before commit:** `ci.yml` parses as valid YAML; every path it references exists (`roles.sql`, `grants.sql`, `StagingFixtureSeeder.php`, `playwright.ci.config.ts`, `run-local-ci.sh`, both lockfiles); `npm ci --dry-run` syncs; staged-content secret scan clean (the only `.env*` in the payload is the value-free `.env.staging.example` template; `backend/.env`, `.env.staging`, `.env.testing` all ignored). Working tree clean after commit.

**Files changed:** `DEVELOPMENT_LOG.md` (this entry). Git: commit `9566dd8` (21 files, +1,579/−27).

**Tests:** none run in this pass (inspection + commit only); all prior local evidence unchanged and still valid only as *local* evidence.

**Known issues / remaining risks:** identical to the boundary above — no remote, no real-runner CI, no staging host, no TLS/secrets/monitoring. Docker unavailable locally for image builds.

**Next steps:** user supplies the GitHub repository URL → add remote, push, real-runner CI; then provider + domain + secrets for the staging host per STAGING.md §2–§9.

### 2026-08-12 — Render staging engineering: remote, push, real CI kickoff, blueprint + images

**Task:** the user supplied the GitHub repository (`https://github.com/b4snet/swasthya-nepal`) and the staging provider (**Render**), with no custom domain yet. Configure the deployment architecture for Render per the documented requirements; do not claim staging readiness; stop at any external-authorization/billing boundary.

**Decision:** (1) adopt the GitHub repo: add `origin`, push `main` with `--force-with-lease` over the auto-init placeholder commit (remote `main` was a single GitHub "Initial commit" containing only a one-line README, authored by the same user — overwritten deliberately and documented here, not silently); (2) verify Render's managed Postgres against STAGING.md requirements from official docs; (3) author the full Render deployment configuration in the repo (blueprint + Docker images + entrypoint); (4) stop at the external boundary: billing (paid Postgres for PITR), Render OAuth + secret entry, and enabling GitHub Actions.

**Verified (Render official docs, 2026-08-12):** Postgres major versions **13–18** available (16 ✓). **Backups/PITR exist only on PAID instances** — Hobby workspace = 3-day recovery window, Pro+ = 7 days; Free instance type has NO backups, NO PITR, NO logical exports → a paid plan is a hard requirement, not an option. AES-256 at rest incl. backups ✓; Render-managed TLS on external connections (TLS 1.2+ + cipher suite requirements) ✓; internal private-network URL for same-region services + external URL with CIDR IP allowlist (external can be disabled entirely) ✓; provisioned user is NOT superuser but can `CREATE DATABASE` (the `swasthya_app` bootstrap is fail-closed if `CREATE ROLE` is ever denied); slow queries > 2 s are logged; automatic HTTPS on `*.onrender.com` (no custom domain needed).

**What was built (all in the repo, none deployed):**
- **Git:** `origin` → `https://github.com/b4snet/swasthya-nepal.git`; `main` pushed (forced update `986e03b…d65f3f6`); remote HEAD confirmed = local HEAD. GitHub Actions workflow `backend-ci` is registered `active` on the remote; a push-triggered run had **not** appeared within ~15 min — flagged as a boundary item (enable Actions / verify on the Actions tab), not a passed CI claim.
- **`render.yaml`** (Blueprint, env `staging`): Postgres 16 (paid `starter` placeholder — billing decision), `swasthya-api` Docker web service, `swasthya-frontend` static site. Wiring: DB via `fromDatabase`; `APP_KEY` + `DB_PASSWORD` via `generateValue`; `APP_URL` / `SWASTHYA_CORS_ALLOWED_ORIGINS` / `VITE_API_BASE_URL` via `sync: false` (entered in Dashboard). **Single generated `DB_PASSWORD`** is used both to `CREATE ROLE swasthya_app` (roles.sql) and at runtime — two generated secrets would silently break authentication (caught in review). `preDeployCommand` runs the bootstrap as the owner; runtime connects as `swasthya_app`; health check `/api/v1/health/ready`.
- **`backend/Dockerfile`** (composer → php:8.3-fpm-alpine + nginx, www-data non-root, postgresql-client for psql), `backend/docker/{nginx.conf,php-fpm.conf,opcache.ini,entrypoint.sh}` (bootstrap mode = owner roles→migrate→grants with `set -e` + `PGSSLMODE=require`, fail-closed; app mode = fpm+nginx; `clear_env=no` so platform env reaches PHP), `backend/.dockerignore` (no secrets/vendor/tests into the image), `frontend/.env.example` + `frontend/src/api/client.ts` `VITE_API_BASE_URL` (same-origin default preserved).
- **`RENDER_STAGING.md`** — runbook with the verified-platform-facts table, architecture/wiring, and the exact external actions the user must take (billing, GitHub link, secret entry, region, DB-allowlist tightening), plus the post-deploy verification checklist (explicitly unclaimed).

**Files changed/added:** `render.yaml` (new), `RENDER_STAGING.md` (new), `backend/Dockerfile` (new), `backend/docker/*` (new, 4 files), `backend/.dockerignore` (new), `frontend/.env.example` (new), `frontend/src/api/client.ts` (VITE_API_BASE_URL), `STAGING.md` (status header), `DEVELOPMENT_LOG.md` (this entry). The Render work is uncommitted in the working tree; `d65f3f6` (already pushed) contains only the earlier staging-mirror/CI milestone work. `render.yaml` validated as YAML; `entrypoint.sh` passes `sh -n`. Docker unavailable locally (no build possible — the image build/verify step belongs to real-runner CI / Render).

**Tests:** none run in this pass (configuration authoring only; `sh -n` + YAML parse + wiring cross-checks). All prior local evidence unchanged.

**Known issues / remaining risks:** (1) GitHub Actions run not observed — user must enable/verify Actions on the repo; (2) Render provisioning is blocked on: paid-Postgres billing decision, Render OAuth link to GitHub, Dashboard secret entry (`APP_URL`, CORS origin, `VITE_API_BASE_URL`), and Postgres allowlist tightening post-create; (3) Docker image not yet built (no local Docker; CI/Render build is the verification); (4) no custom domain — HTTPS is via `*.onrender.com` for initial verification, as instructed.

**Next steps:** user confirms billing + links Render to GitHub + enters secrets (per `RENDER_STAGING.md` §3) → blueprint provisions → post-deploy verification checklist (§4) → real OPD + RLS + mobile + a11y + backup/restore evidence against the actual Render environment → re-issue `STAGING_DEPLOYMENT_REPORT.md` verdict.

### 2026-08-12 — Real-runner CI failures: composer platform pin, psql client, RLS verify SQL

**Task:** after the push to GitHub, the real-runner pipeline (`backend-ci`) failed on three distinct problems that the local twin could not expose. Fix each, verify locally, push, and let the real runner confirm.

**Finding 1 — lockfile resolved against PHP 8.4 (both matrix jobs fail `composer install`).** The lockfile pinned `symfony/*` v8.1 components requiring `php >= 8.4.1`, but `composer.json` declares `"php": "^8.2"` and CI runs 8.2/8.3. The lock was generated on the local PHP 8.4 toolchain, so the local twin (warm vendor) passed while fresh CI installs failed the platform check. Fix: `composer config platform.php 8.2` and regenerate the lockfile — Symfony components downgraded to 8.2-compatible versions, `composer audit` clean (no advisories), and the full suite re-verified locally: **241 passed / 1,748 assertions** with the resolved lock. Commit `3511969`.

**Finding 2 — `ubuntu-latest` runners have no `postgresql-client`.** The role/grants/verify steps call `psql`, which the runner lacks (the local twin used the bundled toolchain psql). Fix: `apt-get install postgresql-client` step in both jobs. Commit `0523e4a`.

**Finding 3 — the RLS-verify step SQL referenced a non-existent view column.** `pg_policies` does not expose `polrelid` (the join column is renamed inside the view definition; the exposed column is `policyname`, not `polname`), so `select ... from pg_policies p join pg_class c on c.oid = p.polrelid` failed with `column p.polrelid does not exist` — both jobs failed at the "Verify RLS policies and role configuration" step while migrations had passed. Reproduced locally against the dev schema and confirmed the corrected queries return the four `p_rls_patients_*` policies and `swasthya_app|f|f` (NOBYPASSRLS, no superuser). Fix: rewrite the step against `pg_policies.policyname/tablename` and make it **fail-closed** — `set -euo pipefail`, capture each result once, and assert the select/delete policies exist and the role row is exactly `swasthya_app|f|f`, so a missing policy can no longer pass silently. Local run of the exact step body: `VERIFY-STEP-OK`.

**Files changed:** `backend/composer.json` + `backend/composer.lock` (platform pin; commit `3511969`), `.github/workflows/ci.yml` (psql client step; commit `0523e4a`, and the verify-step SQL fix in this entry), `DEVELOPMENT_LOG.md` (this entry).

**Tests:** local full backend suite 241 passed / 1,748 assertions (post-lock-regeneration); corrected verify-step body run locally to exit 0 with policies present. No secrets in any diff.

**Known issues / remaining risks:** real-runner CI remains RED until this verify-step fix is pushed and confirmed by a green run; the frontend job (`needs: backend`) has not executed yet; Render provisioning still blocked on the external boundary (billing, OAuth link, secret entry).

**Next steps:** push the verify-step fix → confirm both backend matrix jobs green on the real runner → frontend job (build + E2E + a11y on disposable Postgres) → then the Render boundary items per `RENDER_STAGING.md`.

### 2026-08-12 (follow-up) — Frontend CI job: missing DB_HOST/DB_PORT in seed + E2E backend env

**Task:** with the backend matrix green, the frontend job failed at step 11 ("Grant application privileges + seed staging fixture"). Reproduce, fix, push.

**Root cause:** the seed step set `APP_ENV`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` but **not `DB_HOST`/`DB_PORT`**. The runner has no `.env`, so Laravel's pgsql config falls back to defaults `127.0.0.1:5432` — but the disposable Postgres service container is exposed on **54329** → `Connection refused`. The migrate step in the same job set both vars (which is why it passed); the seed step and the Playwright webServer env both omitted them. Reproduced exactly by removing the local `.env` and running the seed against a fresh disposable DB: `SQLSTATE[08006] ... port 5432 failed`. With `DB_HOST`/`DB_PORT` added, the seeder passes with no `.env` (no APP_KEY required on this path — verified by booting `artisan serve` as `swasthya_app` with the CI env: health 200, login 200 with tokens, authorized appointments 200 through real tenant/facility context + RLS).

**Fix:** add `DB_HOST=127.0.0.1 DB_PORT=54329` to (a) the frontend job's `db:seed` step in `.github/workflows/ci.yml` and (b) the Laravel webServer env in `frontend/playwright.ci.config.ts`. All remaining artisan invocations in the workflow already set both.

**Files changed:** `.github/workflows/ci.yml`, `frontend/playwright.ci.config.ts`, `DEVELOPMENT_LOG.md` (this entry).

**Tests:** faithful local simulation of the CI env (no `.env`): seed exit 0 with host/port set; `artisan serve` as `swasthya_app` → `/health/ready` 200, login 200, appointments 200. No secrets in the diff.

**Next steps:** push → frontend job (static checks, build, Playwright E2E desktop+mobile+a11y) should now reach the E2E step → confirm green → then the Render boundary items per `RENDER_STAGING.md`.

### 2026-08-12 (follow-up 2) — REAL-RUNNER CI GREEN: full pipeline passes on GitHub Actions

**Task:** get the real-runner pipeline fully green (the earlier entries cover the composer-platform, psql-client, RLS-verify-SQL, and DB_HOST/DB_PORT fixes; this entry covers the last two gaps):

1. **`d005899` — frontend-only commits silently skipped CI.** The workflow's push `paths` filter matched only `backend/**` and the workflow file, so the `frontend/tsconfig.json` fix never triggered a run. Fix: add `frontend/**` to push + pull_request paths.
2. **`73cf5d5` — E2E diagnostics.** The frontend E2E step failed in ~24s on the runner while the identical CI config passed locally (fresh disposable DB, app running as `swasthya_app`, RLS active); raw runner logs and artifact downloads both require GitHub auth, so the failure was not directly readable. Added `tee`-captured Playwright output plus an `if: failure()` step that emits the notable lines as `::error::` annotations (visible via the checks API), and widened the failure artifact to include the HTML report.

**RESULT — run `31590541836` on `73cf5d5` is GREEN end-to-end on a real GitHub runner:** backend (PHP 8.2) success — source check, composer install, Pint, roles.sql (NOBYPASSRLS), `migrate:fresh` on disposable `postgres:16-alpine`, RLS policy/role verification (fail-closed), full Pest suite (61s, 241 tests / 1,748 assertions incl. RLS + tenant isolation), build artifact; backend (PHP 8.3) success (same); frontend success — backend deps, `npm ci`, role + migrate + grants + `StagingFixtureSeeder`, typecheck, 20 unit tests, production build, Playwright chromium, then **E2E 28s green (desktop OPD workflow + mobile receptionist flow + 2 axe accessibility scans)**.

**Flakiness note:** the E2E failed at 24s on `d005899` under identical test code and passed at 28s on `73cf5d5` — classified as a flaky failure, not a deterministic defect; the annotation/artifact diagnostics are now permanent so the next flake is readable.

**Files changed:** `.github/workflows/ci.yml` (paths + diagnostics), `DEVELOPMENT_LOG.md` (this entry). Working tree clean; no secrets in any diff.

**Next steps (external boundary):** Render provisioning per `RENDER_STAGING.md` §3 — user must (1) link the Render account to GitHub and authorize the repo, (2) choose the paid Postgres plan (PITR/backups exist only on paid), (3) enter the Dashboard secrets (`APP_KEY`, `DB_PASSWORD` auto-generated; `APP_URL`, `SWASTHYA_CORS_ALLOWED_ORIGINS`, `VITE_API_BASE_URL`), (4) pick a region. The blueprint (`render.yaml`), Docker images, runbook, and CI are all in the repo and verified; nothing is deployed yet.

**Follow-up (same day):** the frontend job then failed at step 12 "Frontend static checks". Root cause: commit `28cfcc4`'s `import.meta.env.VITE_API_BASE_URL` in `frontend/src/api/client.ts` had no `vite/client` types wired, so `tsc -b --noEmit` failed with `Property 'env' does not exist on type 'ImportMeta'` — reproduced locally, a regression introduced by the Render env wiring. Fix: add `"vite/client"` to `types` in `frontend/tsconfig.json`. Verified locally: typecheck exit 0 and `npm test` 20/20 green. Commit `295b041` (with the DB_HOST/DB_PORT fix) plus this tsconfig fix — push and re-run.

### 2026-08-12 — Supabase PostgreSQL as the staging database (provider wiring, no deploy)

**Task:** use the user's Supabase project (`bgfqwsivvhqmuwullkye`) as the PostgreSQL database for the Laravel + React application on Render. Keep Laravel/Eloquent, the schema, migrations, RLS, tenant isolation, and the `swasthya_app` least-privilege role intact; do NOT use the Supabase JS client, Supabase Auth, or PostgREST; do NOT deploy; do NOT invent credentials.

**Decision:** (1) keep the database architecture untouched — the app continues to use plain PostgreSQL through Eloquent with the existing migrations, RLS policies, and roles; (2) connect through the **Supabase shared pooler in SESSION mode** (`aws-<region>.pooler.supabase.com:5432`, IPv4, TLS) — the one hard constraint: transaction mode (6543) does not support prepared statements and does not persist per-connection `SET` (Laravel's `search_path`/timezone), which would break Eloquent; direct connection is IPv6-only without the paid IPv4 add-on; (3) remove the Render-managed Postgres service from `render.yaml` (the user's rule: no second PostgreSQL on Render); (4) keep the bootstrap split — `roles.sql` → `migrate --force` → `grants.sql` as the Supabase `postgres` owner via the pooler (fail-closed predeploy), runtime always `swasthya_app` NOBYPASSRLS; (5) all DB credentials are `sync: false` secrets entered in the Render Dashboard — nothing in git; (6) remove a leftover per-request `// TEMPORARY DEBUG` log block in `ResolveTenantContext` (committed in the baseline; marked "remove before finishing"; logged tenant/facility/user/platform GUIDs on every authenticated request).

**Compatibility verdict (verified against Supabase docs, 2026-08-12):** custom roles (`create role … with login password`), `GRANT`/`ALTER DEFAULT PRIVILEGES`, RLS `CREATE POLICY`, session-level custom GUCs (`app.*` via `set_config(..., is_local)`), and `pg_trgm` all work on Supabase managed Postgres; `postgres` is the admin owner (bypasses RLS during DDL/seeds exactly like the local owner). Runtime traffic never uses `postgres`. Supabase's `auth`/`storage` schemas and `anon`/`authenticated`/`service_role` roles are unrelated and untouched. Backups/PITR require a **paid** Supabase plan (free has none) — an explicit billing decision for the user. CI keeps proving RLS/tenant isolation against a disposable `postgres:16` on every run.

**Files changed:** `render.yaml` (removed the `databases:` block — no Render Postgres; `DB_*`/`BOOTSTRAP_DB_*` now `sync: false` secrets; `DB_PORT=5432`, `DB_DATABASE=postgres`, `DB_SSLMODE=require`), `backend/.env.staging.example` (Supabase session-pooler contract + `BOOTSTRAP_DB_*` + `RLS_DB_*` placeholders), `SUPABASE_STAGING.md` (new runbook: compatibility matrix, session-pooler constraint, env contract, first-time initialization, optional manual role SQL, backup/restore, troubleshooting), `RENDER_STAGING.md` (provider section, architecture diagram, role-separation table, provisioning steps), `STAGING.md` (status header + env block), `backend/app/Http/Middleware/ResolveTenantContext.php` (removed the `ctx-debug` TEMPORARY DEBUG block and its now-unused `Log` import), `DEVELOPMENT_LOG.md` (this entry).

**Tests:** to be run — full backend suite (241 tests / 1,748 assertions incl. RLS + tenant isolation), frontend typecheck + 20 unit tests + production build (see final report in the task reply). The Supabase wiring itself is configuration-only and is exercised by CI against disposable PostgreSQL; no Supabase connection was attempted (no credentials available — by design).

**Known issues / remaining risks:** (1) nothing deployed — Render provisioning still needs the user's Render OAuth link, Supabase billing decision (paid for backups/PITR), and Dashboard secret entry; (2) the pooler username format is `<role>.<project-ref>` (e.g. `swasthya_app.bgfqwsivvhqmuwullkye`) — must match the dashboard's session-pooler string; (3) the `DB_PASSWORD` single-secret invariant: `roles.sql` creates `swasthya_app` with exactly the value stored in Render's `DB_PASSWORD`;

**Next steps:** user enters the secrets and links Render → blueprint provisions → `migrate --force` against Supabase (fail-closed) → post-deploy verification (OPD workflow, Tenant A vs B at the RLS level, mobile E2E, a11y, backup/restore, performance) → re-issue `STAGING_DEPLOYMENT_REPORT.md`.

### 2026-08-12 — Supabase live-connect attempt: pooler verified, halted at owner-password boundary

**Task:** connect the Laravel backend to the real Supabase database (session pooler `aws-0-ap-northeast-2.pooler.supabase.com:5432`, project `bgfqwsivvhqmuwullkye`), create `swasthya_app` via `roles.sql`, run `migrate --force`, verify RLS / NOBYPASSRLS / non-superuser, then run the RLS and backend suites. No deploy to Render.

**Verified against the live pooler (no credentials needed):** TCP reachable on 5432; `PGSSLMODE=require` TLS negotiation succeeds (the probe failure is at the authentication layer, not transport); the pooler username format `<role>.<project-ref>` is accepted — the server error is `password authentication failed for user "postgres"`, not `role does not exist`. Host, port, SSL, and username-format are therefore confirmed correct.

**Boundary:** the message supplied only placeholders for the two secrets (`BOOTSTRAP_DB_PASSWORD` and `DB_PASSWORD`), and no real value exists in the environment (no `SUPABASE_*` env vars, no `.env.supabase`, no Supabase CLI/access token, no credential-store entry, nothing in git history or the repo tree). Per the task rules — never invent Supabase credentials, never claim success without a real connection — the live bootstrap (roles.sql → migrate --force → grants.sql) and the Supabase-side verification **cannot start until the owner password is provided**. Nothing was executed against the database; no connection beyond the auth probe was attempted.

**Planned (ready to run the moment the password exists, via a gitignored `backend/.env.supabase`):** source the values → `roles.sql` as `postgres` (creates `swasthya_app` NOBYPASSRLS with a freshly generated runtime password) → `php artisan migrate --force` (never `migrate:fresh`/DROP) → `grants.sql` → non-destructive verification (RLS enabled on tenant tables, `swasthya_app` `rolbypassrls=false`, `rolsuper=false`) → RLS/tenant-isolation suite. Note: the full Pest suite uses `RefreshDatabase` (which would `migrate:fresh`) so it cannot run against the shared Supabase database — it runs against the disposable local/CI PostgreSQL 16 with the identical schema+RLS, while Supabase itself gets only the non-destructive checks.

**Files changed:** `DEVELOPMENT_LOG.md` (this entry) only; the earlier Supabase wiring (render.yaml, env templates, SUPABASE_STAGING.md, docs, debug-log removal) remains uncommitted in the working tree.

### 2026-08-12 — Live Supabase bootstrap: connected, two migration fixes, RLS verified live

**Task:** connect the Laravel backend to the real Supabase database (session pooler, project `bgfqwsivvhqmuwullkye`), create `swasthya_app` via `roles.sql`, run `migrate --force`, verify RLS / NOBYPASSRLS / non-superuser, run the RLS and backend suites. Never run destructive commands against Supabase; never put the password in git.

**Credentials:** the owner password was provided in-chat; it now lives only in the gitignored `backend/.env.supabase` (verified with `git check-ignore`). The `swasthya_app` runtime password was **generated** (48 hex chars) and stored in the same file — it is the value to enter in Render's `DB_PASSWORD`.

**Live facts:** the project actually runs **PostgreSQL 17.6** (not 16 — migrations verified on both 16 and 17.6). The `public` schema was empty and `swasthya_app` did not exist at first connect. `roles.sql` created `swasthya_app` — `rolsuper=f, rolbypassrls=f, rolcanlogin=t, rolinherit=f, rolcreaterole=f, rolcreatedb=f, rolreplication=f`.

**Finding 1 — owner-role resolution (real-runner-class defect).** The RLS migration's `grantApplicationPrivileges()` derived the owner from `DB::connection()->getConfig('username')` — through the pooler that is the alias `postgres.bgfqwsivvhqmuwullkye`, which is not a server-side role, so `ALTER DEFAULT PRIVILEGES FOR ROLE "postgres.bg…"` failed (`role does not exist`) and the migration rolled back cleanly (migrations table intact; no partial state). Fix: resolve the owner from `select current_user` — correct on every host. Local/CI could never catch this because the local owner *is* the configured username.

**Finding 2 — Supabase "Enable RLS on new tables" default.** The project's setting (Dashboard → Authentication → Policies) is on, so every public table the migrations created — including the deliberately NON-scoped identity/root/framework tables (organizations, users, roles, permissions, role_permissions, personal_access_tokens, refresh_tokens, cache, cache_locks, failed_jobs, job_batches, jobs, migrations) — came up RLS-enabled with **zero policies**, which silently blocks the application role (login reads `users`, Sanctum reads `personal_access_tokens`, the queue/cache read framework tables). The migration's own documentation says these tables are intentionally not RLS-scoped. Fix: the migration now **enforces the documented matrix on any host** — after granting, it disables RLS on every public table outside the scoped set (idempotent), then enables exactly the scoped tenant tables; `down()` also gained the previously-missing `facilities` entry. The corrected migration was re-applied to the live DB via `migrate:rollback --step=1` (drops policies/RLS only — zero data) + `migrate --force`, so the live schema equals a fresh run.

**Live verification (all non-destructive):** 47/47 migrations applied; **144 policies**; RLS on for all tenant tables and off for the non-scoped set; `swasthya_app` connected through the session pooler (runtime path, `current_user = swasthya_app`); **live cross-tenant probe as `swasthya_app`** (rolled-back transaction): Tenant A insert+read = 1 row, Tenant B read = 0, Tenant B update = 0 rows, after rollback = 0 rows persisted.

**Tests:** full backend suite on disposable PostgreSQL 16 — **241 passed / 1,748 assertions** (RefreshDatabase ran `migrate:fresh`, exercising the corrected migration end-to-end). RLS/tenant-isolation suites (DatabaseRowLevelSecurity, TenantIsolation, ClinicalIsolation, PatientIsolation, FacilityIsolation) — **47 passed / 133 assertions**. Note: the Pest Feature suite uses `RefreshDatabase` (→ `migrate:fresh`), so it must never run against the shared Supabase database; it runs against disposable local/CI PostgreSQL 16 (item 8/9 of the task satisfied that way, with the live RLS proof done via the psql probe above).

**Files changed:** `backend/database/migrations/2026_08_11_100100_enable_row_level_security.php` (owner via `current_user`; enforce-RLS-matrix step; `down()` gains `facilities`), `backend/.env.supabase` (NEW, gitignored — the two Supabase secrets), `SUPABASE_STAGING.md` (§9 live results; Postgres 17.6 note), `DEVELOPMENT_LOG.md` (this entry). The earlier Supabase wiring (render.yaml, env templates, docs) plus the RLS-migration fix are uncommitted in the working tree; no secrets appear in any tracked file.

**Known issues / remaining risks:** (1) nothing deployed to Render — provisioning still needs the user's Render OAuth link + Supabase paid-plan billing decision + Dashboard secret entry (including the generated `swasthya_app` password from `backend/.env.supabase`); (2) Supabase's "Enable RLS on new tables" stays on — the migration now forces the correct matrix regardless, and future table-creating migrations must keep non-scoped tables out of the scoped const lists (or rely on the matrix step); (3) the Laravel dev log (`storage/logs/laravel.log`) grew large enough to exhaust the 128 MB PHP test memory (LoggingTest reads it in full) — cleared locally; CI is unaffected (fresh checkout).

**Next steps:** user links Render + confirms billing + enters secrets → Render predeploy bootstrap (roles.sql idempotent → migrate --force → grants.sql) against Supabase, fail-closed → post-deploy verification (OPD workflow, Tenant A vs B at RLS level, mobile E2E, a11y, backup/restore, performance) → re-issue `STAGING_DEPLOYMENT_REPORT.md`.

### 2026-08-12 — Post-audit commit: Supabase wiring + RLS-migration fixes to main

**Task:** after an independent state audit (repository identity, working tree, dangerous-pattern scan, secret hygiene, "pahuna" scan, pooler-assumption scan), commit the previously-uncommitted Supabase provider wiring and the two live-found RLS-migration fixes, then let the real GitHub runner re-prove the corrected migration on a fresh disposable PostgreSQL 16.

**Verified before commit (no changes required):** `origin` = `b4snet/swasthya-nepal.git`; HEAD == origin/main (`4000f90`), no divergence; `migrate:fresh`/`db:wipe`/`DROP DATABASE` appear only in disposable-CI/dev contexts (entrypoint forbids them in deployment); the two "pahuna" references are legitimate Phase-0 notes that sibling folders are separate projects; all env files (`backend/.env.supabase`, `.env.testing`, `.env.testing.bak`, `.env.staging`, `frontend/.env`) gitignored; tracked env templates are placeholder-only.

**Test results (this commit):** backend Pest suite **241 passed / 1,748 assertions** (117.8 s); RLS/isolation suites (**DatabaseRowLevelSecurity, TenantIsolation, ClinicalIsolation, PatientIsolation, FacilityIsolation, BranchIsolation**) **47 passed / 133 assertions**; frontend typecheck clean, **20 unit tests passed**, production build succeeded. Read-only live Supabase verification: **47/47 migrations** (last batch applies the corrected RLS migration), `swasthya_app` → `rolsuper=f, rolbypassrls=f, rolcanlogin=t, rolinherit=f`, **144 policies**, RLS enabled on exactly the **37 scoped tenant tables** and disabled on the non-scoped identity/root/framework set (50 public tables total — 37 on / 13 off).

**Files changed:** the previously-uncommitted working tree — `render.yaml`, `backend/.env.staging.example`, `SUPABASE_STAGING.md` (new), `RENDER_STAGING.md`, `STAGING.md`, `backend/database/migrations/2026_08_11_100100_enable_row_level_security.php` (owner via `current_user`; enforce-RLS-matrix step; `down()` gains `facilities`), `backend/app/Http/Middleware/ResolveTenantContext.php` (removed TEMPORARY DEBUG block), `backend/docker/php-fpm.conf` (stale `SWASTHYA_APP_PASSWORD` comment corrected to the `DB_PASSWORD` single-secret contract), `DEVELOPMENT_LOG.md` (this entry). No secrets in any tracked file.

**Known issues / remaining risks:** unchanged from the preceding entries — nothing deployed; Render provisioning still needs the user's Render OAuth link, Supabase paid-plan billing decision, and Dashboard secret entry (including the generated `swasthya_app` password from `backend/.env.supabase`).

---

*This log opens with the truth: a greenfield folder, seventeen design documents, and no code. Every entry from here on records what is actually done — and this document will be the permanent witness to whether Swasthya is built the way it was designed.*

### 2026-08-15 — Full-repository audit + PROJECT_STATUS.md

- **Date:** 2026-08-15
- **Phase:** Audit (pre-Phase 1 of the production-readiness program)
- **Task:** Inspect the entire repository (docs, migrations, RLS, auth/RBAC/audit, routes/controllers, frontend, CI, deployment, backup, monitoring) and produce an honest `PROJECT_STATUS.md` with the 15 required sections.
- **Decision:** Adopt `PROJECT_STATUS.md` as the standing production-readiness status; record measured gates; identify the untracked Supabase migration layer as the top preservation risk.
- **Reason:** The customer program requires evidence-based status before any further implementation (MASTER_RULES: no fabricated anything, no claims without measurement).
- **Files changed:** `PROJECT_STATUS.md` (new), `DEVELOPMENT_LOG.md` (this entry). `backend/storage/logs/laravel.log` deleted locally (16 MB — the documented LoggingTest memory remedy; not tracked).
- **Database changes:** none. The disposable local PostgreSQL cluster was started for gate measurement only (`pg_ctl` on 127.0.0.1:54329); no schema/data changes.
- **API changes:** none.
- **Security changes:** none (read-only audit; secret scan of tracked files found zero matches).
- **Tests:** measured — Node harness **855 passed / 0 failed**; edge-function pipeline **49 passed / 1,030 assertions**; full backend Pest **358 passed / 2,957 assertions** (after clearing the oversized local log); frontend vitest **26 passed / 6 files**; frontend + harness `tsc` clean; Pint PASS (308 files); `git diff --check` CLEAN.
- **Known issues:** `storage/logs/laravel.log` regrows past the 128 MB PHP test-memory limit until cleared (recurring local footgun); `DEVELOPMENT_LOG.md` had no entries for the Supabase edge-function phase program (Phases 2–45) between 2026-08-12 and this entry.
- **Risks:** the **entire `supabase/` directory** (41 adapters, 49 shared TS modules, 13,759-line harness) plus 5 backend support classes, 3 migrations, and 6 test files are **untracked in git** — the largest single preservation risk; no deployed environment; no MFA; no object storage/Redis/queues/notifications; IPD/pharmacy/lab unimplemented.
- **Next steps:** per the customer program — Phase 1 (core data & multi-tenancy verification), then security audit, workflows, storage, queues, DB performance, load test, production infra, DR, monitoring, tenant management, onboarding, external services, secrets, go-live checklist. First: commit/preserve the untracked working tree, then the highest-severity items in `PROJECT_STATUS.md` §13.

### 2026-08-15 — Program Phase 1: core data & multi-tenancy verification (SECURITY_AUDIT.md)

- **Date:** 2026-08-15
- **Phase:** 1 (of the production-readiness program) — tenant isolation verification, API + DB layers
- **Task:** Preserve the untracked implementation; verify Hospital A ↔ Hospital B isolation at the application/API level and the database/RLS level with deliberate adversarial tests; generate the real RLS inventory; trace the tenant-context flow; write `SECURITY_AUDIT.md` with only verified findings.
- **Decision:** A focused preservation commit (`5c08531`, 106 files, 41,879 insertions — the Supabase edge-function layer + 15 backend support/test files) so no existing implementation can be lost; then adversarial verification rather than doc-trusting.
- **Files changed:**
  - New: `SECURITY_AUDIT.md`, `backend/tests/Feature/CrossTenantApiAttackTest.php` (7 tests), `backend/tests/Feature/TenancyDatabaseInventoryTest.php` (4 tests).
  - Fixed: `backend/app/Http/Controllers/Api/PatientContactController.php` — HIGH finding: the nested `PATCH patients/{patient}/contacts/{contact}` route 500'd on every request (controller signature omitted the `{patient}` route parameter → TypeError). Fixed by declaring `Patient $patient` and adding a parent-linkage guard (mismatched parent → 404 "Resource not found.").
  - Support (earlier in this session, coherent Phase-1 infra): `tests/Pest.php` (RLS helpers: rlsConn/rlsSet/claimsSet/rlsTx/claimsTenants/edgePipelineToken), `app/Support/DatabaseTenantContext.php` (claims-GUC bridge), `app/Models/User.php` (auth_subject_id fillable), `config/swasthya.php` (JWT claims config), `tests/Feature/DatabaseRowLevelSecurityTest.php` (updated to the claims path), `DEVELOPMENT_LOG.md` (this entry).
- **Database changes:** NONE. No migrations, no RLS changes. Verified live: 50 public tables, 37 RLS-enabled / 13 off, 144 policies, all tables owned by `swasthya` (app role owns zero), `swasthya_app` non-superuser + NOBYPASSRLS. Finding: RLS enabled but **not FORCED** on all tables (doc deviation, bounded — app role cannot bypass; FORCE deferred to the deployment phase, recorded in SECURITY_AUDIT.md Finding 2).
- **API changes:** one bug fix (contact update), described above.
- **Security changes:** `SECURITY_AUDIT.md` written with verified findings only; secret scan of tracked files: 0 matches.
- **Tests:** full backend Pest **369 passed / 3,724 assertions** (baseline 358/2,957 → +11 tests / +767 assertions: the two new tenancy suites); edge-function pipeline unchanged **49 passed / 1,030 assertions**; Node harness unchanged **855 passed / 0 failed**; frontend + harness `tsc` clean; Pint PASS; `git diff --check` CLEAN.
- **Cross-tenant attacks:** 23-endpoint IDOR/BOLA read sweep (all 404); 14 child-create attempts (all 403/404/422, victim graph intact); 9 UPDATE + 6 DELETE attempts (all safe denials, zero mutations); forged tenant/facility payloads (422, no rows); cross-tenant booking (403); facility-header proposal (403 FACILITY_DENIED); two-sided SELECT/UPDATE/DELETE probes on all 37 RLS tables under `swasthya_app` (zero rows affected, both directions).
- **Known issues:** `storage/logs/laravel.log` regrows past the 128 MB PHP test-memory limit until cleared (recurring local footgun); no deployed environment; IPD/pharmacy/lab unimplemented (unchanged from PROJECT_STATUS).
- **Risks:** FORCE RLS not applied (MEDIUM, deployment-phase item); INSERT isolation is app-layer by design (LOW, verified 422 rejection + composite-FK backstop); `organizations` unscoped at RLS (INFO, app-layer verified); org-scoped patient list ignores URL selector (LOW, no leak).
- **Next steps:** Program Phase 2 — security audit of authentication (MFA, tokens), file uploads, rate limiting, XSS/CSRF/SSRF, injection; then hospital workflows, storage, queues, DB performance, load test, production infra, DR, monitoring, tenant management, onboarding, external services, secrets, go-live checklist. **Isolation between Hospital A and Hospital B is PROVEN at the API and DB layers for the runtime connection path.**

### 2026-08-15 — Program Phase 1 close-out: FORCE RLS + org-scoped patient-list contract

- **Date:** 2026-08-15
- **Phase:** 1 close-out (tenant-isolation hardening items from SECURITY_AUDIT.md)
- **Task:** Close the two remaining Phase-1 findings — (1) FORCE ROW LEVEL SECURITY on the 37 tenant-scoped tables; (2) make `organizations/{org}/patients` honor the org selector like every sibling catalog read.
- **Files changed:**
  - New migration `backend/database/migrations/2026_08_15_100000_force_row_level_security.php` — `ALTER TABLE ... FORCE ROW LEVEL SECURITY` on the 37 scoped tables (idempotent up/down; flag only, no tables/columns/policies).
  - `backend/app/Http/Controllers/Api/PatientController.php` — `index()` now takes `Organization $organization`, gates it with `AccessCheck::organization(write: false)`, and scopes `tenant_id` to the selector (sibling contract).
  - Tests: `TenancyDatabaseInventoryTest` — FORCE assertion flipped to `true` + new FORCE owner-binding proof (non-superuser table owner via temp role: empty claims → 0 rows, tenant claims → scoped rows); `CrossTenantApiAttackTest` — patients added to the out-of-scope selector sweep + new org-scoped patient-list contract test (same-org 200 + exact projection, forged org 404 `Resource not found.`, out-of-scope org 404 `Resource not found.`, no tenant switch, victim row intact).
  - Docs: `SECURITY_AUDIT.md` (both findings marked RESOLVED + verified nuances), `PROJECT_STATUS.md` (FORCE now actually applied; supabase layer tracking correction — preserved under commit `5c08531`), `DEVELOPMENT_LOG.md` (this entry).
- **Database changes:** ONE migration (FORCE flag only). Verified after migrate:fresh: `relforcerowsecurity=true` on exactly 37 tables, `rowsecurity=true` on 37, policies still 144, `swasthya_app` non-superuser + NOBYPASSRLS. `down()` reverts the flag.
- **Key verified discovery:** the local `swasthya` role and the production Supabase `postgres` owner are SUPERUSERS, which bypass RLS regardless of FORCE; the flag's real value is binding non-superuser owners (proven mechanically with a temp non-superuser owner). The runtime role (`swasthya_app`) was and remains the primary boundary — unchanged and proven.
- **Tests:** full backend Pest **371 passed / 3,743 assertions** (baseline 369/3,724 → +2 tests / +19 assertions: FORCE proof + patient-list contract); edge-function pipeline **49 / 1,030** (unchanged); Node harness **855/855** (unchanged); TypeScript (harness + frontend) PASS; Pint PASS; `git diff --check` CLEAN; no temp artifacts.
- **Known issues / remaining risks:** unchanged from SECURITY_AUDIT §12 — INSERT isolation is app-layer by design; `organizations` unscoped at RLS (deployment-phase hardening); local owner is a superuser (app-layer test path doesn't exercise RLS — RLS suites do; demote locally for full fidelity, deferred); broader Phase 2 security surface not yet audited.
- **Next steps:** Program Phase 2 — security audit (authentication incl. MFA/tokens, file uploads, rate limiting, XSS/CSRF/SSRF, injection, secret handling), then workflows, storage, queues, DB performance, load test, production infra, DR, monitoring, tenant management, onboarding, external services, secrets, go-live checklist.

### 2026-08-15 — Program Phase 2: production security audit

- **Date:** 2026-08-15
- **Phase:** 2 (security audit: authentication, MFA, RBAC, IDOR, input, CORS, rate limiting, secrets, logging, dependencies)
- **Task:** Find and fix real security vulnerabilities before adding more clinical functionality; verify every claim with an executed test or direct inspection; mark anything unverified `NOT PROVEN`.
- **Files changed:**
  - **MFA (implemented, was absent):** `app/Services/Totp.php` (RFC 6238 TOTP, verified against all three official test vectors), `app/Services/MfaService.php`, `app/Models/MfaChallenge.php`, `app/Http/Controllers/Api/MfaController.php`, requests `Auth/MfaEnrollRequest|MfaCodeRequest|MfaChallengeRequest|MfaDisableRequest`, migration `2026_08_15_110000_create_mfa_challenges_table.php` (challenge hash only, SHA-256 at rest, 5-min expiry, one-shot), `User` model helpers + `AuthController` login challenge hook, routes (`POST auth/mfa/*`), `tests/Feature/MfaTest.php` (7 tests / 92 assertions).
  - **Password reset (implemented, was absent):** `app/Services/PasswordResetService.php` (hash-at-rest token, 15-min expiry, single-use consumed_at), `app/Models/PasswordResetToken.php`, migration `2026_08_15_120000_create_password_reset_tokens_table.php`, `app/Http/Controllers/Api/PasswordResetController.php`, requests `Auth/ForgotPasswordRequest|ResetPasswordRequest`, `app/Mail/ResetPasswordMail.php` + blade view, routes `POST auth/password/forgot|reset` behind `throttle:auth`, `tests/Feature/PasswordResetTest.php` (5 tests / 31 assertions: enumeration-safe, revokes all refresh tokens on reset, audits).
  - **Rate limiting (HIGH finding fixed):** `bootstrap/app.php` — explicit full `priority()` override (framework's `prependToPriorityList` silently no-ops because `ThrottleRequests` is already in the default list) so `ThrottleRequests` runs BEFORE `AuthenticatesRequests` and `ResolveTenantContext`; `routes/api.php` — `throttle:api` (300/min/IP) on the whole API group before auth. Verified empirically: unauthenticated requests now consume the budget (429 on the 3rd hit). Regression: `SecurityRateLimitAndInputTest` (7 tests / 28 assertions).
  - **XSS hardening (LOW):** `app/Support/Envelope.php` — JSON encoding options now hex-escape HTML-significant chars (`JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`); verified raw bytes `<script>` → `\u003Cscript\u003E`.
  - **RBAC matrix:** `tests/Feature/SecurityRbacTest.php` (3 tests / 29 assertions) — doctor/nurse/receptionist/billing/pharmacist/org_admin/platform_ops escalation probes against real resources; safe-denial contract (403 gate / 404 binding / 405 no-such-operation).
  - **IDOR/BOLA sweep:** `tests/Feature/SecurityIdorAndSurfaceTest.php` (4 tests / 22 assertions) — nested parent mismatches, cross-tenant revoke/listing, forged org/facility IDs.
  - **CORS verified:** `HandleCors` in global middleware; single-origin allowlist behavior confirmed (evil origin sees non-matching ACAO — browser blocks); no `*`; test asserts real contract.
  - **Frontend deps:** vite 5→7, vitest 2→3.2.7, @vitejs/plugin-react pinned — cleared high/critical npm advisories (dev-only tooling); react-router-dom 6.30.4 moderate advisory documented (fix requires v7 framework migration).
  - **Docs:** `SECURITY_AUDIT.md` rewritten to the 22-section structure with verified findings only; `DEVELOPMENT_LOG.md` (this entry).
- **Database changes:** TWO migrations (MFA challenges, password reset tokens) — both deliberately NOT RLS-scoped (public pre-tenant flows, hash-only payloads, same pattern as refresh_tokens); both `disable row level security`. RLS matrix updated in `ClaimsBasedRlsTest`: 52 tables = 37 scoped on / 15 off (the two new tables land in the off set). No FORCE/policy/tenant changes.
- **Verified findings:** LogRequest logs path only (no body/headers/query — no PHI, no tokens); AuditLogger clean; no SSRF surface (no user-controlled URL fetching exists — documented NOT PRESENT); document uploads are metadata-only (`staged`, no download path) — object storage is a documented REQUIRED production capability, not a live vulnerability; tracked-secret scan clean (0 matches); composer audit clean.
- **Tests:** full backend Pest **397 passed / 3,945 assertions** (Phase 1 close 371/3,743 → +26/+202); edge-function pipeline **49 / 1,030** (unchanged — edge layer untouched); Node harness **855/855**; frontend **26 vitest** + tsc clean; harness tsc PASS; Pint PASS (332 files); `git diff --check` CLEAN; secret scan clean; no probe/temp artifacts left.
- **Known issues / remaining risks:** distributed rate limiting requires Redis in production (per-IP proven locally with array cache); live Supabase/GoTrue behavior NOT PROVEN (contract-tested only); object storage not implemented; client-side XSS verified by inspection only (no browser E2E in this session); secrets rotation/secret-manager is a deployment-phase item; deployment-phase controls (HTTPS termination, WAF, monitoring runbooks) not yet exercised.
- **Verdict:** **SECURITY PARTIALLY VERIFIED** — not production-secure. Two REQUIRED controls (MFA, password reset) implemented and tested; every claim in SECURITY_AUDIT.md is backed by an executed test or live query.
- **Next steps:** Program Phase 3 — hospital workflows (registration → MRN → appointment → check-in → consultation → prescription → lab/radiology → billing → payment → discharge), IPD, pharmacy, lab, billing; then storage, queues, DB performance, load test, production infra, DR, monitoring, tenant management, onboarding, external services, secrets, go-live checklist.

### 2026-08-15 — Program Phase 3 slice 1: patient lifecycle verification (registration → encounter)

- **Date:** 2026-08-15
- **Phase:** 3 slice 1 (hospital workflow — foundational patient lifecycle; registration → MRN → appointment → check-in → queue → consultation/encounter)
- **Task:** Phase 3 mandates the OPD workflow in controlled slices. Slice 1 is the foundational lifecycle: patient registration, MRN, appointment creation + permission rules, check-in, queue, encounter creation. The architecture already contained the full surface (models, controllers, routes, `authorize:` gates); this slice is a forensic verification of every contract dimension plus the remaining-gates sweep — NOT a greenfield build.
- **Verified (from source + executed tests):**
  - Registration: atomic per-tenant MRN issuance (`mrn_counters`), demographics/contacts/identifiers/emergency contact in one transaction, duplicate candidates surfaced server-side (never auto-merge), underage/invalid-sex validation, cross-tenant facility rejected at validator, optimistic locking (409 `LOCK_CONFLICT` on stale `lockVersion`), timeline entry, audit with facts only (no phone/email in payload).
  - Booking: derived availability from schedule templates minus exceptions/bookings, slot outside availability → 409, double-booking → 409 via unique index `uq_appointments_tenant_provider_start`, cross-tenant patient → 403 `SCOPE_DENIED`, unknown patient → 404 (no existence leak), cross-tenant provider rejected at booking, malformed payloads → 422 structured validation.
  - Check-in/queue: sequential per-provider-per-day tokens (`TokenIssuer`, queue-date part of key, day reset proven), queue ordered by token, queue + appointment list facility-scoped, cancelled appointment cannot be checked in, double check-in → 409.
  - Consultation: encounter starts only from a checked-in appointment (else 409), one encounter per appointment (partial unique `uq_encounters_tenant_appointment`), sign requires a signed note, signed encounter immutable (no late notes/diagnoses), appointment completes with the encounter, only the encounter provider can document (403 for another doctor).
  - Role gates across the workflow: receptionist cannot start an encounter (`encounter:create`), billing clerk cannot check in (`appointment:checkin`), nurse cannot prescribe (`encounter:prescribe`), doctor cannot collect payment (`billing:collect`), nurse cannot sign (`encounter:sign`).
  - Isolation: another tenant's appointments/encounters/invoices are 404 on read and 403 on write; cross-tenant payment → 403; audit events carry `facility_id` and never clinical content (PHI-safe).
  - Concurrency: double-booking race proven via unique index (loser → 409, exactly one row); token issuance race-safe under 25 rapid sequential issues.
  - Billing (dependency of the lifecycle slice): invoice derived from encounter charges (consultation + prescription lines), payment with idempotency key, zero/negative/missing idempotency → 422, cross-tenant invoice invisible.
- **Files changed:** none (verification slice — no production code modified). This entry documents the verification result and the verified baseline.
- **Tests:** first-slice verification run (10 workflow test files: PatientRegistration, PatientIdentifierContact, PatientSearchMerge, PatientIsolation, AppointmentBooking, EncounterClinical, BillingPayment, ClinicalWorkflowE2E, WorkflowFailurePath, ClinicalIsolation): **65 passed / 365 assertions**; full backend Pest **397 passed / 3,945 assertions** (unchanged vs Phase 2 close); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (332 files); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining Phase 3 scope (NOT implemented, next slices):**
  - **Lab/radiology orders → results** — no lab/order/result routes, controllers, models, or schema exist. This is the next Phase 3 slice.
  - **Pharmacy dispensing** — prescriptions exist; dispensing, stock deduction, and inventory are absent.
  - **Discharge / follow-up** — IPD admission/discharge surface absent (no admission or discharge routes).
  - **Billing refunds/adjustments** — payment exists; refund/adjustment flow absent.
- **Next steps:** Phase 3 slice 2 — lab/radiology order lifecycle (order → sample → result entry → verification → report → doctor/patient access), then pharmacy dispensing, then discharge/follow-up; each with success/failure/RBAC/isolation/audit/transaction coverage per the phase rules.

### 2026-08-15 — Program Phase 3 slice 2: laboratory & radiology order lifecycle

- **Date:** 2026-08-15
- **Phase:** 3 slice 2 (lab/radiology order lifecycle: order → sample → processing → result entry → verification → final report → authorized access)
- **Task:** Implement the next Phase 3 slice from scratch (no lab surface existed). Doctor orders catalog tests from an open encounter; the order advances through a five-step state machine with entry ≠ verification enforced; the final report is immutable. Full RBAC, tenant/facility isolation, audit, and concurrency coverage.
- **Files created:**
  - `database/migrations/2026_08_15_130000_create_lab_tables.php` — `lab_tests` (catalog, soft-deletable, category incl. radiology), `lab_orders` (state machine + priority, composite FKs, CAS lock_version), `lab_order_items` (result value/unit + reference-range snapshot, entry/verification actors). Status/priority CHECKs, unique (tenant,facility,code) partial, one-item-per-order-test unique, composite-FK unique indexes, query indexes.
  - `database/migrations/2026_08_15_130100_enable_lab_row_level_security.php` — RLS on + FORCED on all three tables with claim-based TENANT_FACILITY policies (12 policies added: 144 → 156); down() fully reverts.
  - Models `LabTest`, `LabOrder`, `LabOrderItem` (+ factories); requests `Lab/StoreLabOrderRequest`, `Lab/EnterLabResultsRequest`, `Lab/StoreLabTestRequest`; controllers `LabTestController` (catalog index/store) and `LabOrderController` (store, forEncounter, forPatient, show, collect, process, enterResults, verify, report).
  - `tests/Feature/LabWorkflowTest.php` — 19 tests / 157 assertions.
- **Files modified:**
  - `routes/api.php` — lab routes (11): catalog read/write, order create, encounter/patient views, order detail, and the five transitions; all behind `authorize:lab:*` gates.
  - `database/seeders/RolePermissionSeeder.php` — 8 new permissions (`lab:view/order/specimen/process/result_entry/verify/report/manage`); grants: doctor (+lab:order, lab:view), nurse (+lab:view), lab_technician (+specimen/process/result_entry), NEW `lab_supervisor` role (lab:verify, lab:report), org_admin/hospital_admin (+lab:manage, lab:view).
  - `app/Support/AuditLogger.php` — `lab_order`/`lab_test` added to facility-scoped resources (facility-scoped auditors see the events).
  - `app/Models/Encounter.php` — `labOrders()` relationship.
  - `tests/Feature/ClaimsBasedRlsTest.php` — policy count 144→156; matrix 37→40 on (55 tables / 15 off); new claims-based lab isolation proof (tenant + facility + mutation immunity).
  - `tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 37→40 (3 lab tables + mfa/password-reset tables completed in the unscoped list); full two-tenant seed chain + update probes extended to the lab tables.
  - `DATABASE.md` — §3.28 rewritten to the implemented schema; §3.29 marked planned (radiology shares the order surface for now).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.8 + existing patterns):** order status machine ordered→collected→processing→results_entered→verified→reported; every transition is a compare-and-swap on (status, lock_version) so concurrent writers can never double-advance (loser → 409 CONFLICT); entry ≠ verification enforced by BOTH distinct permissions (lab:result_entry vs lab:verify) AND a different-staff guard (403); verification requires every item to have a result; `reported` is immutable (corrections are new audited versions — later phase); patient surface exposes only verified/reported orders (released results), scoped to the bound patient (no cross-patient leakage); reference range is snapshotted at order time; audit payloads carry facts (testCount, staff ids, facility) — never result values.
- **Tests:** LabWorkflow **19 / 157** (happy path, malformed payloads, signed-encounter refusal, non-provider/nurse denial, unknown/inactive/cross-tenant tests, cross-tenant 404/403, invalid transitions for all five steps, double-collect/process, partial/foreign result entry, entry≠verification both guards, wrong-state + double verification, report immutability, care-team view, patient released-results view, unauthorized + cross-patient access, facility scoping, concurrent-verify CAS race, audit PHI-safety, catalog org scoping + cross-tenant write rejection); full backend Pest **418 passed / 4,328 assertions** (slice-1 baseline 397/3,945 → +21/+383); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (346 files); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining slice 3 scope (NOT implemented):** specimen accession chain-of-custody + critical/panic-value escalation with acknowledgment (documented later-phase plan); radiology modality scheduling/studies/preliminary-final reports (orders run on the shared surface); pharmacy dispensing; discharge/follow-up; billing refunds/adjustments.
- **Next steps:** Phase 3 slice 3 — pharmacy dispensing (prescription → stock check → dispense → inventory deduction → billing), then discharge/follow-up; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-15 — Phase 3 slice 3: pharmacy dispensing & inventory

- **Phase:** 3 slice 3 (pharmacy dispensing: prescription → verification → stock check → dispense → inventory deduction → billing)
- **Task:** Implement the pharmacy dispensing workflow from scratch (no inventory/dispensing surface existed). The pharmacist verifies a drafted prescription (drafted → active), then dispenses it (active → dispensed) as ONE atomic transaction: per ordered line, stock CAS deduction + ledger movement + line stamp + posted charge; any shortfall rolls back everything (no partial dispensing, no partial deduction). Verification is a required step before dispensing.
- **Files created:**
  - `database/migrations/2026_08_15_140000_create_pharmacy_tables.php` — `inventory_items` (one stock row per tenant/facility/medication, quantity ≥ 0 CHECK, reorder level, CAS lock_version, composite FKs), `inventory_movements` (append-only ledger: receipt/adjustment/dispense, signed non-zero delta, mandatory reason for adjustments, dispense↔prescription_line composite FK), dispensing/verification actor stamps on `prescription_lines`/`prescriptions`, composite-FK unique index for prescription_lines.
  - `database/migrations/2026_08_15_140100_enable_pharmacy_row_level_security.php` — RLS on + FORCED on both tables with claim-based TENANT_FACILITY policies (8 policies added: 156 → 164); down() fully reverts.
  - Models `InventoryItem`, `InventoryMovement` (+ factories); requests `Pharmacy/StoreInventoryRequest`, `Pharmacy/AdjustInventoryRequest`; controllers `InventoryController` (org-scoped list with facility filter, atomic upsert receipt, CAS adjustment) and `PharmacyController` (prescription view with available quantity, verify, dispense).
  - `app/Support/AccessCheck.php::prescription()` — scope helper for prescriptions (no facility column; effective scope is the encounter's facility).
  - `tests/Feature/PharmacyDispensingTest.php` — 14 tests / 115 assertions.
- **Files modified:**
  - `routes/api.php` — 6 pharmacy routes behind `authorize:pharmacy:*` (inventory list/receipt, adjust, prescription view/verify/dispense).
  - `database/seeders/RolePermissionSeeder.php` — 3 new permissions (`pharmacy:view`, `pharmacy:dispense`, `pharmacy:stock`); grants: pharmacist (view/dispense/stock — the dispensing role), doctor/nurse (+view), org_admin/hospital_admin (+view/stock), support_agent (+view).
  - `app/Support/AuditLogger.php` — `inventory_item`/`inventory_movement` added to facility-scoped resources.
  - `app/Models/Prescription.php` / `PrescriptionLine.php` — new fillable/casts/relations for the stamps.
  - `tests/Feature/ClaimsBasedRlsTest.php` — policy count 156→164; matrix 40→42 on (57 tables / 15 off); new claims-based inventory isolation proof (tenant + facility + mutation immunity + ledger intact).
  - `tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 40→42; full two-tenant seed chain + update probes extended to the pharmacy tables.
  - `DATABASE.md` — §3.30/§3.31 rewritten to the implemented schema (full stores/batches design marked later-phase).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.9 + existing patterns):** verify-before-dispense (drafted → active → dispensed; a drafted prescription cannot be dispensed, 409); the dispense is one transaction — stock CAS per line (WHERE quantity ≥ requested AND lock_version matches; 0 rows → 409), dispense ledger movement linked to the line, line → dispensed, posted charge per line (price × max(1, quantity) in minor units — the same money math as billing); concurrent dispenses of the same shelf can never double-deduct or drive stock negative; receipts are a single atomic INSERT … ON CONFLICT DO UPDATE (no duplicate stock rows); adjustments require a reason and can never go negative; org-scoped inventory lists apply the explicit facility filter for facility-scoped principals (the established catalog-list pattern — RLS is the production backstop, the app filter keeps test behavior consistent); audit payloads carry facts (ids, lineCount, totalAmountMinor) — never patient names, medication names, or clinical content.
- **Tests:** PharmacyDispensingTest **14 / 115** (receipt + upsert + facility-scoped list + org-scoped write denial, verify happy path + audit + non-pharmacist denial + unauthenticated 401, full dispense (stock 100→98/99, ledger, charges 2×500/1×1200, audit total 2200), unverified dispense 409, insufficient-stock full rollback (nothing dispensed/deducted/charged/audited), no-stock-configured 409, invalid transitions (double verify/dispense, verify-after-dispense), adjustment + negative-block + missing-reason 422, cross-tenant isolation (read 404 / writes 403, data untouched), cross-facility isolation, prescription-header CAS race (winner commits, stale loser 0 rows, HTTP loser 409), stock-level CAS race across two prescriptions, audit PHI-safety, pharmacy:view gate); RLS suites **19 / 808** (incl. new inventory claims proof); full backend Pest **433 passed / 4,526 assertions** (slice-2 baseline 418/4,328 → +15/+198); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (357 files); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** batch/expiry tracking (`stock_batches`), returns/reversals with reason codes, partial-line dispensing (a line is dispensed whole or the transaction rolls back), pharmacist-vs-prescriber conflict rules, purchase/procurement flows — all later-phase plans; discharge/follow-up and billing refunds/adjustments remain from the Phase 3 slate.
- **Next steps:** Phase 3 slice 4 — discharge/follow-up (discharge summary, status transitions, follow-up scheduling), then billing refunds/adjustments; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-15 — Phase 3 slice 4: discharge & follow-up

- **Phase:** 3 slice 4 (discharge: clinical close of the signed visit + follow-up: planned return visits linked to the encounter)
- **Task:** Implement the discharge and follow-up workflow from scratch (no discharge/follow-up surface existed). A signed encounter is discharged by its provider (signed → closed, with disposition + discharge summary, CAS on status/lock_version); follow-ups are planned on the open encounter (planned → booked → completed / cancelled), each transition CAS-guarded.
- **Files created:**
  - `database/migrations/2026_08_15_150000_create_follow_ups_table.php` — `follow_ups` (type return_visit/teleconsult CHECK, status planned/booked/completed/cancelled CHECK, future `planned_at`, optional `booked_appointment_id` composite FK to the same-patient appointment, composite FKs to facilities/patients/encounters/staff, CAS lock_version, query indexes) + discharge stamps on `encounters` (`disposition` CHECK, `discharge_summary`, `discharged_by`, `discharged_at`).
  - `database/migrations/2026_08_15_150100_enable_follow_ups_row_level_security.php` — RLS on + FORCED with claim-based TENANT_FACILITY policies (4 policies added: 164 → 168); down() fully reverts.
  - Model `FollowUp` (+ factory); requests `FollowUp/StoreFollowUpRequest`, `FollowUp/BookFollowUpRequest`, `FollowUp/CancelFollowUpRequest`, `Encounter/DischargeEncounterRequest`; controller `FollowUpController` (create, forEncounter, forPatient, book, cancel, complete).
  - `tests/Feature/DischargeFollowUpTest.php` — 12 tests / 67 assertions.
- **Files modified:**
  - `routes/api.php` — 7 routes (discharge behind `authorize:encounter:sign`; follow-up create/list/book/cancel/complete behind `authorize:followup:*`).
  - `database/seeders/RolePermissionSeeder.php` — 2 new permissions (`followup:view`, `followup:manage`); grants: doctor (view+manage), nurse (+view), org_admin/hospital_admin (+view+manage), support_agent (+view).
  - `app/Http/Controllers/Api/EncounterController.php` — `discharge()` (CAS signed → closed, provider-only, audit) + discharge fields in present().
  - `app/Models/Encounter.php` — discharge fillable/casts/constants + `followUps()` relation.
  - `app/Support/AuditLogger.php` — `follow_up` added to facility-scoped resources.
  - `tests/Feature/ClaimsBasedRlsTest.php` — policy count 164→168; matrix 42→43 on (58 tables / 15 off); new follow-ups claims isolation proof (tenant + facility + mutation immunity).
  - `tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 42→43; full two-tenant seed chain + update probes extended to follow_ups.
  - `DATABASE.md` — §3.17 discharge lifecycle/fields; new §3.17a follow_ups (implemented schema).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.7 + existing patterns):** discharge requires a SIGNED encounter (the record is final — open → 409, closed → 409) and only the encounter provider (gate encounter:sign + provider guard); the discharge summary is captured on the encounter (IPD's structured sections — diagnoses/procedures/medications — is a later-phase plan); follow-ups are planned only on OPEN encounters (after signing the record is final — 409); planned_at must be in the future (422); book validates the appointment is the same patient in the same facility (422 otherwise) and only from planned; cancel requires a reason (422 without) and only from planned/booked; complete only from booked; every transition is a compare-and-swap on (status, lock_version) — concurrent discharge/cancel can never double-advance (loser 409); the patient surface exposes only upcoming (planned/booked) plans; audit payloads carry facts (ids, type, plannedAt, disposition) — never summary/reason text or patient names.
- **Tests:** DischargeFollowUpTest **12 / 67** (discharge happy path + stamps + audit, discharge from open/closed 409, nurse + non-provider doctor denial, follow-up plan happy path + future-date/type validation, plan-on-signed 409 + nurse 403, per-encounter + per-patient (upcoming only) lists, book with matching appointment + foreign-patient 422 + cancelled 409, cancel (reason required, double-cancel 409) + complete (planned 409), cross-tenant read 404 / writes 403 with data untouched, cross-facility 404/403, discharge CAS race (winner commits, stale loser 0 rows, HTTP loser 409), audit PHI-safety, unauthenticated 401); RLS suites **20 / 832** (incl. new follow-ups claims proof); full backend Pest **446 passed / 4,643 assertions** (slice-3 baseline 433/4,526 → +13/+117); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (367 files); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** IPD admission/discharge with structured discharge summary sections and bed release (Phase 2 IPD); appointment auto-creation from a follow-up plan (book currently links an EXISTING appointment); follow-up reminders/notifications; encounter amendment; billing refunds/adjustments remain from the Phase 3 slate.
- **Next steps:** Phase 3 slice 5 — billing refunds/adjustments (reversing entries with approver workflow, audit trail), then the remaining slate; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-15 — Phase 3 slice 5: billing refunds & adjustments

- **Phase:** 3 slice 5 (billing refunds/adjustments: posted charge → refund/adjustment request → authorized approval → immutable reversing entry)
- **Task:** Implement the refund/adjustment surface from scratch (no refund surface existed — only charges/invoices/payments). The approved request IS the reversing entry; the original posted charge is never mutated.
- **Files created:**
  - `backend/database/migrations/2026_08_15_160000_create_refund_requests_table.php` — `refund_requests` (amount_minor > 0 CHECK, structured reason_code CHECK, status requested/approved/rejected CHECK, reason_note free text NULL, CAS lock_version, composite FKs to facilities/patients/charges, query indexes).
  - `backend/database/migrations/2026_08_15_160100_enable_refund_requests_row_level_security.php` — RLS on + FORCED with claim-based TENANT_FACILITY policies (4 policies added: 168 → 172); down() fully reverts.
  - Model `RefundRequest` (+ factory); requests `Billing/StoreRefundRequest`, `Billing/RejectRefundRequest`; controller `RefundController` (index, store, approve, reject).
  - `backend/tests/Feature/RefundAdjustmentTest.php` — 12 tests / 79 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 4 routes (list/create behind `authorize:billing:view` / `authorize:billing:refund`; approve/reject behind `authorize:billing:refund-approve`).
  - `backend/database/seeders/RolePermissionSeeder.php` — 2 new permissions (`billing:refund`, `billing:refund-approve`); grants: org_admin/hospital_admin (request + approve), billing_clerk (request only — segregation of duties), no clinical role.
  - `backend/app/Services/BillingService.php` — `requestRefund`, `approveRefund`, `rejectRefund`, `approvedTotal`.
  - `backend/app/Models/Charge.php` — `refunds()` relation.
  - `backend/app/Support/AuditLogger.php` — `refund_request` added to facility-scoped resources.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — policy count 168→172; matrix 43→44 on (59 tables / 15 off); new refund-requests claims isolation proof (tenant + facility + mutation immunity).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 43→44; full two-tenant seed chain + update probes extended to refund_requests.
  - `DATABASE.md` — §3.33 implemented refund-request schema/lifecycle.
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.13, DATABASE.md §3.33, BILLING.md §10 + existing patterns):** the refundable amount is `amount_minor − Σ(approved)`; creation AND approval re-check it — creation refuses a request beyond it (422), approval re-checks under a `lockForUpdate` on the charge row so concurrent approvals of different requests can never over-refund; approval/rejection is a compare-and-swap on (status, lock_version) so duplicate approval of the same request affects zero rows (HTTP loser 409); only a POSTED charge is refundable (voided → 409, missing → 404 via binding); the approver must differ from the requester (403, segregation of duties — the requester never holds approval authority anyway); rejection requires a reason (422 without); `reason_note` is free text that may contain PHI and therefore NEVER appears in audit payloads — audit carries facts (chargeId, amountMinor, reasonCode); the approved request IS the immutable reversing entry, the charge stays posted (immutability proven); a designed `completed` disbursement state is documented as later-phase (needs a payment/disbursement surface).
- **Tests:** RefundAdjustmentTest **12 / 79** (request→approve happy path + charge immutability + audit, reject with mandatory reason + reject-then-approve 409, over-refund at creation 422 + two-request over-refund blocked at approval (totals consistent), duplicate-approval CAS race (winner commits, stale loser 0 rows, HTTP loser 409, one approved row), self-approval 403, voided/missing charge 409/404, RBAC (doctor/nurse 403, clerk requests but cannot approve 403), unauthenticated 401, cross-tenant isolation (read 404 / writes 403, data untouched), cross-facility isolation, audit PHI-safety (no patient name / reason text), list oldest-first + receptionist gate 403); RLS suites **21 / 856** (incl. new refund-requests claims proof); full backend Pest **459 passed / 4,772 assertions** (slice-4 baseline 446/4,643 → +13/+129); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (375 files); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** refund `completed` disbursement state (money actually returned — needs a payment/disbursement surface), payment/deposit-level refunds (the designed `refunds` table referencing payments/deposits), invoice rebalancing when a refund touches an invoiced charge, insurance claim involvement, gateway integration — all later-phase plans.
- **Next steps:** remaining Phase 3 slate (IPD admission/discharge with bed release, lab critical-value escalation, pharmacy returns/reversals, appointment auto-creation from follow-up, follow-up reminders) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-15 — Phase 3 slice 6: IPD admission/discharge with bed release

- **Phase:** 3 slice 6 (IPD: admit from an open encounter onto a live available bed, then discharge with a structured summary that releases the bed).
- **Task:** Implement the inpatient admission/discharge surface from scratch (no admission surface existed; `beds` already carried `current_admission_id` since Phase 4 exactly to receive its FK here). The bed claim is a compare-and-swap; the discharge writes a signed discharge-summary clinical note and releases the bed to cleaning.
- **Files created:**
  - `backend/database/migrations/2026_08_15_170000_create_admissions_table.php` — `admissions` (admission_type CHECK emergency/planned/transfer_in, status CHECK admitted/in_ward/transferred/discharged/cancelled, discharge_type CHECK, CAS lock_version, composite FKs to facilities/patients/encounters, partial uniques one-open-per-patient and one-open-per-encounter, `(tenant_id, id)` composite-FK unique, query indexes) + adds `fk_beds_tenant_current_admission` (tenant-safe composite FK beds → admissions).
  - `backend/database/migrations/2026_08_15_170100_enable_admissions_row_level_security.php` — RLS on + FORCED with claim-based TENANT_FACILITY policies (4 policies added: 172 → 176); down() fully reverts (drops the beds FK first).
  - Model `Admission` (+ factory); requests `Admission/StoreAdmissionRequest`, `Admission/DischargeAdmissionRequest`; service `AdmissionService` (admit/discharge — row-locked bed claim + CAS transitions); controller `AdmissionController` (store, show, discharge).
  - `backend/tests/Feature/AdmissionDischargeTest.php` — 9 tests / 72 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 3 routes (create behind `authorize:admission:create`; show behind `authorize:admission:view`; discharge behind `authorize:admission:discharge`).
  - `backend/database/seeders/RolePermissionSeeder.php` — 3 new permissions (`admission:view/create/discharge`); grants: doctor/org_admin/hospital_admin (view + create + discharge), nurse/branch_manager/billing_clerk/support_agent (view only — discharge settlement needs billing visibility), no front-desk/other clinical role beyond view.
  - `backend/app/Models/ClinicalNote.php` — `TYPE_DISCHARGE` constant.
  - `backend/app/Support/AuditLogger.php` — `admission` added to facility-scoped resources.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — policy count 172→176; matrix 44→45 on (60 tables / 15 off); new admissions claims isolation proof (tenant + facility + mutation immunity).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 44→45; full two-tenant seed chain + update probes extended to admissions (chain also claims the bed).
  - `DATABASE.md` — §3.23 admissions marked implemented (lifecycle, partial uniques, beds FK); §3.26 beds FK note.
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23 + existing patterns):** admission requires an OPEN encounter (signed → 409) and no existing open admission for the patient (409); the bed must be live AVAILABLE in the same tenant+facility (occupied → 409, foreign/missing → 404 — existence hidden); the bed claim is a CAS on (status = available, current_admission_id IS NULL, lock_version) under a row lock, with the partial unique `uq_beds_tenant_current_admission` as the DB backstop — two concurrent admitters can never book the same bed (winner commits, stale loser 0 rows, HTTP loser 409); discharge requires status admitted/in_ward (else 409) and writes a SIGNED discharge-summary clinical note (type `discharge`) whose structured content is clinical PHI and never reaches audit payloads; the admission advances via CAS (status, lock_version — duplicate discharge affects zero rows, 409); the bed is released occupied → cleaning with occupancy cleared (never immediately reassignable before turnover); discharge requires `dischargeType` (422 without); audit carries facts (patientId, encounterId, bedId, admissionType, admissionNumber) — never the admitting diagnosis, summary content, or patient names.
- **Tests:** AdmissionDischargeTest **9 / 72** (admit happy path + bed occupied + audit event, signed-encounter 409 + already-open patient 409, occupied/foreign/missing bed 409/404/404, discharge happy path + structured summary note + bed released to cleaning + audit, missing dischargeType 422 + double-discharge 409 + unknown admission 404, bed-claim CAS race (winner 1 row / stale loser 0 rows / HTTP loser 409 CONFLICT, one admission total), RBAC (non-provider doctor 403, receptionist 403, unauthenticated 401), cross-tenant + cross-facility isolation (read 404 / writes 403, data untouched), audit PHI-safety (no patient name / diagnosis / summary content in any payload, facts present)); RLS suites **22 / 880** (incl. new admissions claims proof, 45-table sweep); full backend Pest **469 passed / 4,907 assertions** (slice-5 baseline 459/4,772 → +10/+135); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (384 files, 2 style fixes); `git diff --check` CLEAN; artifact sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** transfers (in_ward/transferred statuses are accepted values but no transfer workflow), bed-day charging for admission stays (billing surface), IPD nursing workflow (nursing notes, MAR, vitals), structured discharge summary sections beyond the signed note (the note's content shape is free-form jsonb for now), cancelled-admission workflow (status exists, no endpoint), ward/room/bed admission-funnel UX — all later-phase plans.
- **Next steps:** remaining Phase 3 slate (lab critical-value escalation, pharmacy returns/reversals, appointment auto-creation from follow-up, follow-up reminders, refund completed disbursement state) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-15 — Phase 3 slice 7: laboratory critical-value escalation

- **Phase:** 3 slice 7 (critical/panic lab value escalation with acknowledgment — PRODUCT_REQUIREMENTS §6.8 workflow 6, CLINICAL_SAFETY §7, MASTER_RULES §11.3).
- **Task:** Implement the critical-value escalation surface from scratch (no escalation record existed; DATABASE.md §3.28 documented it as the later-phase plan). The result enterer flags a critical value at entry; the event targets the ordering clinician, who must acknowledge it (who/when) — if it stays unacknowledged a supervisor escalates it (fail loudly, never silently).
- **Files created:**
  - `backend/database/migrations/2026_08_15_180000_create_critical_value_events_table.php` — `critical_value_events` (status CHECK triggered/escalated/acknowledged, CAS lock_version, composite FKs to facilities/lab_order_items/patients/encounters/staff×2, partial unique one-OPEN-event-per-item on (tenant_id, lab_order_item_id) WHERE status IN (triggered, escalated), queue + target query indexes). The event references the flagged item but stores NO result value.
  - `backend/database/migrations/2026_08_15_180100_enable_critical_value_events_row_level_security.php` — RLS on + FORCED with claim-based TENANT_FACILITY policies (4 policies added: 176 → 180); down() fully reverts.
  - Model `CriticalValueEvent` (+ factory); controller `CriticalValueEventController` (index, acknowledge, escalate).
  - `backend/tests/Feature/CriticalValueEscalationTest.php` — 11 tests / 139 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 3 routes (list behind `authorize:lab:view`; acknowledge behind `authorize:lab:acknowledge`; escalate behind `authorize:lab:escalate`).
  - `backend/app/Http/Requests/Lab/EnterLabResultsRequest.php` — `results.*.isCritical` (nullable boolean) — the trigger flag.
  - `backend/app/Http/Controllers/Api/LabOrderController.php` — `enterResults` creates the triggered event in the SAME transaction as the entry (a critical value can never be entered without its escalation record) and audits `critical_value.triggered`.
  - `backend/database/seeders/RolePermissionSeeder.php` — 2 new permissions (`lab:acknowledge`, `lab:escalate`); grants: doctor (acknowledge — the ordering clinician), lab_supervisor (escalate), org_admin/hospital_admin (both).
  - `backend/app/Support/AuditLogger.php` — `critical_value_event` added to facility-scoped resources.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — policy count 176→180; matrix 45→46 on (61 tables / 15 off); new critical-value-events claims isolation proof (tenant + facility + mutation immunity, full lab chain).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 45→46; full two-tenant seed chain + update probes extended to critical_value_events.
  - `DATABASE.md` — §3.28 later-phase plan updated; critical-value escalation marked implemented.
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.8, CLINICAL_SAFETY §7, DATABASE.md §3.28 + existing patterns):** the flag lives at ENTRY (workflow 3 — "enter results → flag out-of-range and critical/panic values"), not verification; the event targets the ORDERING clinician (`lab_orders.ordered_by_staff_id`); acknowledgment is the TARGET's act only (different doctor 403 — a critical value is never silently acknowledged by someone else) and is terminal; escalation is a supervisor's act (lab:escalate), never the target's (they must acknowledge, not self-escalate); an escalated event is still acknowledged by the target (escalation stays loud until a human closes it); both transitions are compare-and-swap on (status, lock_version) — a concurrent actor affects 0 rows and gets 409 CONFLICT; one OPEN event per item is the partial-unique backstop (repeated trigger while open is a DB-level no-op; after acknowledgment a correction re-runs escalation per CLINICAL_SAFETY §7); the event stores no result value and audit carries facts only (encounterId, itemId, target/actor staff ids) — never the value, test name, or patient name; timing-based AUTO-escalation and SMS/email notification need the notifications module + scheduler (DATABASE.md §3.37) — documented as later phase, the manual escalate action is the implemented loud path.
- **Tests:** CriticalValueEscalationTest **11 / 139** (trigger on flag at entry + queue visibility + audit, acknowledge happy path (who/when) + queue terminal, non-target doctor 403 + technician 403 with data untouched, escalate by supervisor + target-as-escalator 403 + double-escalate 409, acknowledge after escalation closes the loop, double-acknowledge 409 + partial-unique refuses a second open event (savepoint pattern), CAS race (winner 1 row / stale loser 0 rows / HTTP loser 409 CONFLICT), RBAC (unauthenticated 401, technician view-only 403, nurse 403), cross-tenant + cross-facility isolation (read invisible / write 403, data untouched), audit PHI-safety (no result value / patient name / test name, facts present), queue ordering oldest-first); RLS suites **23 / 904** (incl. new critical-value claims proof, 46-table sweep); full backend Pest **481 passed / 5,096 assertions** (slice-6 baseline 469/4,907 → +12/+189); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (384 files, 2 style fixes); `git diff --check` CLEAN; artifact sweep CLEAN (incl. temporary debug probes removed); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** timing-based auto-escalation (unacknowledged-after-N-minutes) needs the scheduler/notifications module; actual SMS/email/in-app notification delivery; flag-at-verification (entry is the documented trigger point); critical threshold configuration on the catalog (flagging is explicit at entry — reference ranges are free text); escalation history beyond the single escalate action; critical-findings escalation for radiology reports (radiology reports are a later-phase surface) — all later-phase plans.
- **Next steps:** remaining Phase 3 slate (pharmacy returns/reversals, appointment auto-creation from follow-up, follow-up reminders, refund completed disbursement state) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 8: pharmacy returns & reversals

- **Phase:** 3 slice 8 (returns/reversals of dispensed prescription lines — PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.30/§3.23).
- **Task:** Implement the returns/reversals surface from scratch (no reversal record existed; §6.7 documents patient returns with reason, stock restoration, refund path, and dispensing reversal with reason and audit). A pharmacist reverses a dispensed line in one atomic transaction: reason captured, stock restored through the append-only ledger, line marked reversed, and the refund path opened against the linked posted charge via the EXISTING billing mechanism (refund_requests requested → approved by billing) — the charge itself is never mutated.
- **Files created:**
  - `backend/database/migrations/2026_08_16_160000_create_pharmacy_returns_table.php` — `pharmacy_returns` (quantity_minor CHECK > 0, reason_code CHECK, reason_note NULL — free text that may contain PHI and never reaches audit, returned_by/returned_at, composite FKs to facilities/prescription_lines/prescriptions/charges, unique one-return-per-line on (tenant_id, prescription_line_id), charge/prescription query indexes).
  - `backend/database/migrations/2026_08_16_160100_enable_pharmacy_returns_row_level_security.php` — RLS on + FORCED with claim-based TENANT_FACILITY policies (4 policies added: 180 → 184); down() fully reverts.
  - `backend/database/migrations/2026_08_16_160200_extend_pharmacy_for_returns.php` — extends existing tables (no duplicate concepts): `inventory_movements.movement_type` CHECK gains `return`; `prescription_lines.status` CHECK gains `reversed`; `charges` gains nullable `prescription_line_id` (composite FK) — the line linkage dispensing now stamps and returns trace back to.
  - Model `PharmacyReturn` (+ factory); service `PharmacyReturnService` (atomic reversal); controller `PharmacyReturnController` (POST return); request `ReturnPrescriptionLineRequest` (reasonCode required/in-list, reasonNote nullable).
  - `backend/tests/Feature/PharmacyReturnReversalTest.php` — 11 tests / 122 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 1 route: `POST prescription-lines/{prescriptionLine}/return` behind `authorize:pharmacy:return`.
  - `backend/app/Http/Controllers/Api/PharmacyController.php` — dispense now stamps `prescription_line_id` on the per-line charge (the return's financial trace).
  - `backend/app/Models/{InventoryMovement,PrescriptionLine}.php` — `TYPE_RETURN`, `STATUS_REVERSED`; `Charge.php` — fillable `prescription_line_id`.
  - `backend/database/seeders/RolePermissionSeeder.php` — 1 new permission `pharmacy:return`; granted to the pharmacist role only (like `pharmacy:dispense`, the return is the pharmacist's clinical act — no broad grants).
  - `backend/app/Support/AuditLogger.php` — `pharmacy_return` added to facility-scoped resources.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — policy count 180→184; matrix 46→47 on (62 tables / 15 off); new pharmacy-returns claims isolation proof (tenant + facility + mutation immunity, full pharmacy chain).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 46→47; full two-tenant seed chain + update probes extended to pharmacy_returns.
  - `DATABASE.md` — §3.30/§3.31 updated; returns/reversals marked implemented.
- **Database changes:** THREE migrations (schema + RLS/FORCE + extension of existing pharmacy tables). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.23/§3.30/§3.33 + existing patterns):** the LINE is the unit of return (it is the unit of dispensing); a return is a FULL reversal of the dispensed line (the charge is one price × quantity per line, so partial returns cannot be expressed without splitting the charge — narrowest correct behavior); the reversal row IS the immutable record (like an approved refund request) and the line status flips dispensed → reversed (explicit state, no boolean flags); stock restoration is a CAS on (quantity_on_hand, lock_version) with a positive `return` ledger movement — the exact mirror of the negative `dispense`; the linked posted charge is NEVER mutated (immutable financial rows) — the refund path opens a refund_requests row (requested, reason `patient_request`, amount = charge amount) that the billing approver approves via the slice-5 gate (segregation of duties preserved; the pharmacist never self-approves); one return per line is a unique index backstop and the line-row lock + status CAS serialize concurrent returns (stale actor → 409 CONFLICT); the header stays `dispensed` (it WAS dispensed; returns are per-line corrections recorded in the ledger + returns); re-issue of returned medication is a NEW prescription (a reversed line is never re-dispensed by the dispense flow); refund `completed` disbursement state remains a later-phase item (needs a payment surface) — not implemented here.
- **Tests:** PharmacyReturnReversalTest **11 / 122** (happy full return: stock restored, line reversed, positive return ledger movement, reversal record, refund request opened requested + charge immutable + audit; reason code required/structured; never-dispensed + already-reversed 409 with exactly-one reversal/refund/restoration; CAS race (winner 1 row / stale loser 0 rows / HTTP loser 409 CONFLICT); voided/missing linked charge 409 with nothing mutated + no audit; refund-path integration (org_admin approves the opened request, charge still posted); RBAC (doctor/nurse/receptionist 403, pharmacist-only); unauthenticated 401; cross-tenant isolation (read 404 / write 403, data untouched); cross-facility isolation (read 404 / write 403); audit PHI-safety (no patient name / medication name / reason-note text in any payload, facts present)); RLS suites **24 / 928** (incl. new pharmacy-returns claims proof, 47-table sweep); full backend Pest **493 passed / 5,255 assertions** (slice-7 baseline 481/5,096 → +12/+159); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS (399 files); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** batch/lot tracking (stock restores to the lean per-medication item — `stock_batches` is a later-phase plan); partial-quantity returns (full-line reversal only — splitting charges needs the charge-line model); standalone `dispensings` table; the money refund's `completed` disbursement state (needs a payment surface); automatic notification to billing on return — all later-phase plans.
- **Next steps:** remaining Phase 3 slate (appointment auto-creation from follow-up, follow-up reminders, refund completed disbursement state) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 9: appointment auto-creation from follow-up

- **Phase:** 3 slice 9 (appointment auto-creation from a follow-up plan — PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.15/§3.17a).
- **Task:** Close the documented slice-4 gap ("appointment auto-creation from a follow-up plan — `book` currently links an EXISTING appointment"). Add an `auto-book` action: the plan BECOMES the booking — the appointment is created from the follow-up plan (patient, provider, facility, `planned_at`, type `follow_up`/`teleconsult`) and linked to it in one atomic transaction; no separately-booked appointment needed.
- **Files created:**
  - `backend/database/migrations/2026_08_16_170000_add_follow_up_appointment_source.php` — appointments `source` CHECK gains `follow_up` (an auto-created follow-up appointment is neither counter, portal, nor walk-in; it originates from the clinical follow-up workflow); `down()` fully reverts.
  - `backend/tests/Feature/FollowUpAutoBookTest.php` — 8 tests / 53 assertions.
- **Files modified:**
  - `backend/app/Http/Controllers/Api/FollowUpController.php` — `autoBook`: row-locks the plan (concurrent auto-books of the SAME follow-up serialize — loser reads status booked → 409), CAS transition planned → booked with `booked_appointment_id`, creates the appointment (`source='follow_up'`, 15-minute canonical window — the schedule slot default; the plan carries no duration), audits `appointment.booked` (facts only: patientId, providerStaffId, startsAt, source) + `follow_up.booked`; the provider-start unique index makes two plans for the same provider and start mutually exclusive (409, data untouched).
  - `backend/app/Models/Appointment.php` — `SOURCE_FOLLOW_UP` constant (+ explicit `TYPE_FOLLOW_UP`/`TYPE_TELECONSULT` constants matching the follow-up types).
  - `backend/routes/api.php` — 1 route: `POST follow-ups/{followUp}/auto-book` behind `authorize:followup:manage`.
  - `DATABASE.md` — §3.15 `source` enum + §3.17a auto-book note.
- **Database changes:** ONE migration (constraint-only — no new table). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.15/§3.17a + existing patterns):** the plan is the single source of truth for the booking (patient/provider/facility/planned time all come from the plan, never from the request body — nothing client-supplied can redirect the booking); the appointment type follows the plan type (return_visit → follow_up, teleconsult → teleconsult); `booked_appointment_id` linkage + status CAS are reused from the existing `book` transition; the row-lock serializes same-plan races while the unique index backstops same-provider/same-start collisions; no new permission needed — auto-booking is the same care-team act as booking (`followup:manage`); no RLS change — no new scoped table (184 policies / 47 scoped tables unchanged).
- **Tests:** FollowUpAutoBookTest **8 / 53** (auto-book happy path: appointment created with correct fields/source/type, plan → booked with linkage, audit events; `followup:manage` holder succeeds; role without `followup:manage` 403; unauthenticated 401; plan already booked/completed 409 with nothing created; same provider + same planned time 409 via the unique index with data untouched; cross-tenant 404 (existence hidden); cross-facility 404); RLS suites unchanged **24 / 928**; full backend Pest **501 passed / 5,308 assertions** (slice-8 baseline 493/5,255 → +8/+53); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; Pint PASS; `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** follow-up reminders (needs the notifications module); auto-book conflict resolution beyond the 409 (no automatic re-scheduling); refund `completed` disbursement state (needs a payment surface) — all later-phase plans.
- **Next steps:** remaining Phase 3 slate (follow-up reminders, refund completed disbursement state) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 10: follow-up reminders (in-app)

- **Phase:** 3 slice 10 (follow-up reminders — PRODUCT_REQUIREMENTS §5.4, DATABASE.md §3.37/§3.17a, CLINICAL_SAFETY §0.8/§11).
- **Task:** Implement the documented reminder workflow using the designed `notifications` entity instead of inventing a duplicate. The slice delivers the in-app channel: a planned follow-up carries ONE `appointment_reminder` notification for its patient, created atomically with the plan (no silent automation — the reminder surfaces to the care team via GET); email/SMS/push, templates, delivery attempts, and preferences remain the documented later-phase surface (nothing is sent outside the app; no fake channels).
- **Files created:**
  - `backend/database/migrations/2026_08_16_180000_create_notifications_table.php` — `notifications` per §3.37: `tenant_id NOT NULL` (TENANT tier — no facility_id), nullable `user_id` (global identity, no FK per §1.3), nullable `patient_id` + `follow_up_id` (typed composite FKs), `type`/`channel`/`status` CHECKs exactly per §3.37, `payload jsonb`, `sensitive boolean`, documented indexes `(tenant_id, user_id, status)` + `(tenant_id, created_at)`; **partial unique `(tenant_id, follow_up_id) WHERE follow_up_id IS NOT NULL`** — one reminder per plan (retry/concurrency backstop).
  - `backend/database/migrations/2026_08_16_180100_enable_notifications_row_level_security.php` — RLS on + FORCED, **TENANT tier** (tenant-only policy shape — no facility clause), claim-based via `swasthya_rls_tenant_id()`, zero GUC references (4 policies: 184 → **188**; scoped matrix 47 → **48** tables, still 15 off); `down()` fully reverts.
  - Model `Notification` (+ factory) — constants for the §3.37 enums, `followUp()`/`patient()` relations.
  - `backend/tests/Feature/FollowUpReminderTest.php` — 10 tests / 65 assertions.
- **Files modified:**
  - `backend/app/Http/Controllers/Api/FollowUpController.php` — `create()` now creates the plan AND its in-app reminder in one transaction (the invariant: a planned follow-up always carries its reminder) and audits `follow_up.reminder_created` (facts only) only when a reminder is actually created; new `remind()` (idempotent trigger — replay returns the existing reminder, never duplicates or re-audits) and `reminder()` (care-team read) actions; private `createReminder()` helper — race-safe via the partial unique (concurrent triggers: loser catches the unique violation and returns the winner's row).
  - `backend/routes/api.php` — 2 routes: `POST follow-ups/{followUp}/remind` (`authorize:followup:manage`), `GET follow-ups/{followUp}/reminder` (`authorize:followup:view`).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — policy count 184→188; matrix 47→48 on (63 tables / 15 off); new notifications claims proof proving the **TENANT tier**: cross-tenant invisible + mutation immunity, AND same-tenant-other-facility visible (facility-agnostic — the deliberate difference from TENANT_FACILITY tables).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 47→48; full two-tenant seed chain + update probes extended to notifications.
  - `DATABASE.md` — §3.17a reminder note; §3.37 marked partially implemented (in-app only).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §5.4, DATABASE.md §3.37/§3.17a, CLINICAL_SAFETY §0.8/§11):** the reminder lives in the documented `notifications` entity (no duplicate concept); type `appointment_reminder` is the §3.37 type for a planned-visit reminder; channel `in_app` is the only channel with an implementation (synchronous → status `sent`; email/SMS/push + `delivery_attempts` need provider adapters — documented later phase, MASTER_RULES 2.4/3.3); NO new permission — triggering is the follow-up module owner's act (`followup:manage`), reading is `followup:view` (§5.4 "module owners trigger domain notifications"); the partial unique makes re-triggers and concurrent triggers DB-level no-ops and audit fires only on actual creation (an idempotent replay never fabricates an audit event); TENANT tier per §3.37 (tenant_id NOT NULL, no facility_id) — verified by the claims proof that another facility in the same tenant can see the reminder; payload/audit carry facts only (followUpId, patientId, plannedAt, channel) — never patient names, reason text, or clinical content.
- **Tests:** FollowUpReminderTest **10 / 65** (atomic creation at plan time + facts-only audit; care-team read; on-demand trigger + idempotent replay (one row, one audit); DB-level duplicate prevention via the partial unique (savepoint pattern, index existence proven); completed plan 409 with nothing created/audited; RBAC — nurse reads but cannot trigger (403), pharmacist (no followup permission) 403; unauthenticated 401 (header flush); cross-tenant 404 read / 403 write, data untouched; cross-facility 404/403; audit + notification PHI-safety (no patient name / reason text anywhere, facts present)); RLS suites **25 / 952** (incl. the new TENANT-tier notifications claims proof, 48-table sweep); full backend Pest **512 passed / 5,400 assertions** (slice-9 baseline 501/5,308 → +11/+92); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS (406 files); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** email/SMS/push channels, `notification_templates`, `delivery_attempts`, `notification_preference`, template-driven dispatch, provider integrations (PRODUCT_REQUIREMENTS §5.4 "Future integrations") — the in-app channel is the implemented, honest surface; no automatic reminder timing/scheduler job (dispatch is at plan creation + explicit idempotent trigger; a scheduled "remind N days before planned_at" job needs the scheduler/queue infrastructure and an ADR per MASTER_RULES 2.4); RANGE partitioning of `notifications` is the documented design-time default for scale, deferred like `audit_events`.
- **Next steps:** remaining Phase 3 slate (refund completed disbursement state — needs a payment surface) — or the next program phase per the governing plan; each with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 11: refund completed/disbursement state

- **Phase:** 3 slice 11 (refund completed/disbursement state — PRODUCT_REQUIREMENTS §6.13, DATABASE.md §3.33, MASTER_RULES §37.4).
- **Task:** Close the last documented Phase 3 refund item. DATABASE.md §3.33 explicitly designed the state: "A designed `completed` state (actual disbursement of money back to the patient) is a later-phase addition when a payment/disbursement surface exists." No payment provider exists or is invented: an APPROVED refund request transitions to `completed` when the finance officer actually hands the money back (`completed_by`/`completed_at`) — the same CAS-guarded, segregation-preserving pattern as approval.
- **Files created:**
  - `backend/database/migrations/2026_08_16_190000_add_refund_completed_state.php` — `refund_requests.status` CHECK gains `completed`; adds nullable `completed_by`/`completed_at` (after `rejected_at`); `down()` fully reverts both. No new table, no RLS change (refund_requests stays TENANT_FACILITY + FORCED — 188 policies / 48 scoped tables unchanged).
  - `backend/tests/Feature/RefundDisbursementTest.php` — 9 tests / 74 assertions.
- **Files modified:**
  - `backend/app/Models/RefundRequest.php` — `STATUS_COMPLETED` constant + fillable/casts.
  - `backend/app/Services/BillingService.php` — new `completeRefund()` (approved-only, completer ≠ requester, CAS on (status, lock_version) — a stale or duplicate completion affects zero rows and returns 409, so a refund can be disbursed exactly once); **`approvedTotal()` now counts APPROVED + COMPLETED** — the completion state must NOT free the reserved money, otherwise a completed refund could be refunded again (this is the financial-integrity fix the new test caught).
  - `backend/app/Http/Controllers/Api/RefundController.php` — `complete()` action + `completedBy`/`completedAt` in `present()`; audits `refund.completed` (facts only).
  - `backend/routes/api.php` — `POST refund-requests/{refundRequest}/complete` behind `authorize:billing:refund-approve` (the same financial gate as approval).
  - `DATABASE.md` — §3.33 lifecycle + fields updated; completed state marked implemented.
- **Database changes:** ONE migration (constraint + two nullable columns — no new table). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: PRODUCT_REQUIREMENTS §6.13, DATABASE.md §3.33, MASTER_RULES §37.3–37.4):** completion is a STATE on the existing immutable reversing entry, not a new record — the approved request remains the reversal and the charge is never mutated; NO payment provider or fake disbursement integration (completed_by/completed_at record the actual hand-over; gateway disbursement remains the documented future integration); the completer must hold `billing:refund-approve` AND differ from the requester (the documented two-person rule; approver == completer is permitted — one finance officer may approve and disburse, the requester never touches money); the refundable accounting is unchanged by completion (the amount was reserved at approval) — `approvedTotal` counts both states so over-refund stays impossible; CAS (status + lock_version) makes duplicate disbursement a database-level no-op; audit `refund.completed` carries facts only (chargeId, amountMinor, reasonCode — never reason_note text or patient names).
- **Tests:** RefundDisbursementTest **9 / 74** (completion happy path: status/`completedBy`/`completedAt`/lock_version 2 + charge immutable + facts-only audit; pending and rejected requests 409 with zero side effects; requester-as-completer 403 (segregation of duties — org_admin requester passes the route gate, the service refuses); duplicate completion 409 — one completed row, one audit; CAS race — stale actor affects zero rows, exactly one winner; **financial integrity — after completion a second request beyond the reserved amount is still refused (the fix `approvedTotal` counts APPROVED+COMPLETED)**; billing_clerk (no refund-approve) 403 + unauthenticated 401; cross-tenant 403 write / cross-facility 403 with data untouched; audit PHI-safety — no patient name or reason text in any payload); RefundAdjustmentTest regression **all green**; RLS suites unchanged **25 / 952** (188 policies / 48 tables — no new scoped table); full backend Pest **521 passed / 5,474 assertions** (slice-10 baseline 512/5,400 → +9/+74); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS (408 files); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** actual payment-gateway disbursement (PRODUCT_REQUIREMENTS §6.13 "Future integrations" — the completed state records the in-house hand-over; a gateway/ledger integration is a later-phase surface and is deliberately NOT faked); deposit-based refunds (deposits table is still planned); charge-line/partial-refund splitting; daily reconciliation and settlement surfaces.
- **Next steps:** the Phase 3 slate is now COMPLETE (registration → appointments → queue → encounters → lab → pharmacy → discharge/follow-up → refunds → reminders → disbursement). Next is the next program phase per the governing plan, with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 13: the remaining documented IPD workflow (transfers, nursing notes, MAR, vitals)

- **Phase:** 3 slice 13 (ROADMAP Phase 8 — IPD, PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23/§3.27). The IPD slice completes the documented inpatient workflow: wards/rooms/beds already existed (Phase 4), admission/discharge with the signed discharge-summary clinical note already existed (slice 6); this slice adds the four nursing surfaces the design specifies — audited bed/ward **transfers**, **nursing notes** (draft → signed), **MAR administration**, and **vital observations**.
- **Task:** implement the remaining documented IPD workflow with the same safety discipline: race-safe occupancy, audited transfers with reasons, clinical immutability, tenant/facility isolation, claim-based RLS, PHI-safe audit, idempotency, and concurrency guarantees. "Discharge summary" was already delivered by slice 6 (a signed clinical-note of type discharge referenced by `discharge_summary_id`); ROADMAP's "complete before settlement" criterion depends on Phase 13 and remains documented.
- **Files created:**
  - `backend/database/migrations/2026_08_16_200000_create_ipd_nursing_tables.php` — four TENANT_FACILITY tables with tenant-safe composite FKs: `transfer_events` (from-bed → to-bed, reason CHECK, authorizing staff, transferred_at; index `(tenant_id, admission_id, transferred_at)`), `nursing_notes` (content jsonb, `draft|signed` CHECK, `(tenant_id, admission_id, created_at)`), `mar_entries` (`scheduled|given|refused|missed|held` CHECK, nullable administered_by/at + reason, **partial unique `uq_mar_entries_tenant_line_scheduled` — one administration per scheduled dose**, `(tenant_id, admission_id, scheduled_at)`), `vital_observations` (`bp|pulse|temp|spo2|weight|score` CHECK, value jsonb, nullable `is_abnormal`, BRIN on `measured_at` + `(tenant_id, patient_id, measured_at)` + `(tenant_id, admission_id, measured_at)`); additive composite-FK support index `uq_beds_tenant_id` (prescription_lines already carries `uq_prescription_lines_tenant_id` from the pharmacy slice).
  - `backend/database/migrations/2026_08_16_200100_enable_ipd_nursing_row_level_security.php` — TENANT_FACILITY tier, RLS on + FORCED, claims-only (zero GUC refs): **4 tables × 4 policies = 16 → 188 → 204 total; scoped matrix 48 → 52 tables**.
  - `backend/app/Models/{TransferEvent,NursingNote,MarEntry,VitalObservation}.php` + 4 factories.
  - `backend/app/Services/IpdNursingService.php` — createNote (draft), signNote (author-only CAS, signed immutable), recordVital (append-only, open admission only), scheduleMar (line must belong to the admission's patient and stay `ordered`; duplicate line+time → 409 via pre-check + unique backstop), administerMar (CAS scheduled → given/refused/missed/held; reason required for refused/missed/held; identity re-confirmation enforced at the request layer for `given`).
  - `backend/app/Http/Controllers/Api/IpdNursingController.php` + 5 request classes (`TransferAdmissionRequest`, `StoreNursingNoteRequest`, `StoreVitalObservationRequest` with per-type value validation, `ScheduleMarEntryRequest`, `AdministerMarEntryRequest`).
  - `backend/tests/Feature/IpdNursingWorkflowTest.php` — 11 tests / 163 assertions.
- **Files modified:**
  - `backend/app/Services/AdmissionService.php` — `transfer()` (row-locked admission + both beds; CAS admission → `transferred`; vacated bed occupied → cleaning; target bed CAS-claimed — two clerks can never book the same bed; immutable `transfer_events` row) and **discharge now accepts `transferred`** (a transferred admission discharges normally, releasing its CURRENT bed — the integration fix the slice requires).
  - `backend/app/Http/Controllers/Api/AdmissionController.php` — `transfer` + `transfers` (historical bed timeline) actions; transfer authority = any active doctor/admin in the admission's tenant+facility (the `admission:transfer` gate is the doctor-approval; ward transfers are a care-team act, not limited to the encounter provider).
  - `backend/app/Models/Admission.php` — `transfers()/nursingNotes()/marEntries()/vitalObservations()` relations.
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for the 4 new resources.
  - `backend/database/seeders/RolePermissionSeeder.php` — 3 permissions: `admission:transfer` (doctor/org_admin/hospital_admin), `nursing:document` + `mar:administer` (nurse/org_admin/hospital_admin — the nurse's acts, like pharmacy dispensing; doctors do NOT administer MAR).
  - `backend/routes/api.php` — 9 routes (transfer + timeline, notes create/list/sign, MAR schedule/list/administer, vitals create/list).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 188→204 policies; matrix 48→52 on (67 tables / 15 off); new claims proof for the full nursing surface (transfer_events, nursing_notes, mar_entries, vital_observations) — tenant/facility isolation + mutation immunity + org-wide visibility.
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 48→52; full two-tenant seed chain + update probes extended to the four tables.
  - `DATABASE.md` — §3.23 transfers implemented note; §3.27 marked implemented with the four-table design.
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: ROADMAP Phase 8, PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23/§3.27, CLINICAL_SAFETY §190, MASTER_RULES §11.2):** transfers are **audited with reasons** — the reason lives in the immutable `transfer_events` row (clinical context) and NEVER in audit payloads; the vacated bed goes occupied → cleaning (consistent with discharge — never immediately reassignable) and the target bed is CAS-claimed so no double-booking is possible even under contention; the historical bed timeline = current bed (`beds.current_admission_id`) + all `transfer_events`; nursing notes mirror the clinical_notes discipline (draft → signed, author-only, signed immutable; amendments later phase); MAR administration from prescription lines — `given` REQUIRES identity re-confirmation (name + MRN, CLINICAL_SAFETY §190) and refused/missed/held require a captured reason; one administration per scheduled dose is DB-enforced; a cancelled/reversed line can never be administered; `is_abnormal` is the later-phase CDSS-derived flag (nullable, never client-supplied); vitals/nursing are the nurse's acts (`nursing:document`, `mar:administer` — clinical roles only, like pharmacy dispensing). Audit (`admission.transferred`, `nursing_note.created/.signed`, `vital_observation.recorded`, `mar_entry.scheduled/.administered`) carries facts and ids only — proven PHI-safe (no patient name, note content, vital values, reasons, or admitting diagnosis in any payload).
- **Tests:** IpdNursingWorkflowTest **11 / 163** (transfer happy path + audited timeline + bed states; invalid transfers — same/occupied/cross-facility/unknown bed, missing reason, discharged admission; transfer bed-claim race — exactly one winner, loser 409 with zero rows; **discharge after transfer releases the NEW bed**; RBAC — nurse cannot transfer, doctor cannot administer MAR, pharmacist denied both, unauthenticated 401; nursing notes — draft → author-only sign once, re-sign 409, other-nurse 403; vitals — per-type value validation (malformed/unknown/isAbnormal-rejected 422), chronological list; MAR — schedule/administer given (identity confirmation required) / refused (reason required), double-administer 409, duplicate line+time 409, cancelled line / other patient / other tenant refused with zero rows; cross-tenant + cross-facility isolation with data untouched; audit PHI-safety with facts present); RLS suites **26 / 1047** (new nursing-surface claims proof; 52-table sweep; 204 policies / zero GUC refs); full backend Pest **533 passed / 5,771 assertions** (slice-11 baseline 521/5,474 → +12/+297); Node harness **855/855** (unchanged); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS (426 files); `git diff --check` CLEAN; artifact/debug
marker sweep CLEAN; tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** automated dose-time generation from prescription frequency (needs the scheduler/queue infrastructure — the nurse schedules each dose explicitly), MAR dual-verification (verified_by stays nullable; a second-nurse policy is later-phase), CDSS-derived is_abnormal and abnormal-value escalation, nursing-note amendments, bed-day charging (later-phase billing), and discharge-summary-complete-before-settlement enforcement (depends on Phase 13 settlement). ICU/ER-specific observation schedules are later-phase per the roadmap.
- **Next steps:** the Phase 3 IPD slate is now complete. Next is the next program phase per the governing plan (Emergency per ROADMAP Phase 9), with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 14: Emergency (minimal-data registration, configurable triage, time-stamped events, audited disposition)

- **Phase:** 3 slice 14 (ROADMAP Phase 9 — Emergency, PRODUCT_REQUIREMENTS §6.6, DATABASE.md §3.17b). The ER surface reuses the clinical spine — an ER visit is an `encounters` row with `type='er'`, ER admission reuses the IPD CAS bed-claim path with `admission_type='emergency'` — and adds the four ER-specific tables the design names: **minimal-data registration**, **configurable triage** (the acuity level IS the queue priority), **time-stamped append-only ER events**, and **audited admit/transfer/discharge disposition**.
- **Task:** implement the documented Emergency workflow with the same discipline: minimal-data registration that works under peak load (unidentified patients get a documented placeholder + estimated age; identity later resolved via the existing controlled patient-merge), triage reassessment that supersedes via CAS with a DB partial-unique backstop, immutable medico-legal events, and disposition that claims beds through the SAME CAS admission path as IPD. Wrong-patient safeguards: the ER encounter is the anchor; events/triage/registration always carry the encounter's own patient (composite FK, never client-chosen).
- **Files created:**
  - `backend/database/migrations/2026_08_16_210000_create_er_tables.php` — four TENANT_FACILITY tables with tenant-safe composite FKs: `er_registrations` (patient_id/encounter_id NOT NULL, registered_by → staff, registered_at, nullable presenting_complaint CHECK ≤2000, nullable estimated_age CHECK 0–150, is_unidentified, nullable completed_at/completed_by; unique `(tenant_id, encounter_id)`, `(tenant_id, registered_at)`), `triage_scales` (code/name, level CHECK 1–10, nullable color, nullable reassessment_minutes CHECK 5–1440, is_default, active/inactive CHECK; unique `(tenant_id, facility_id, code)` + `uq_triage_scales_tenant_id` backing the assignments FK), `triage_assignments` (encounter_id/patient_id/triage_scale_id NOT NULL, level/color snapshotted from the scale, assessed_by_staff_id, assessed_at, is_override + nullable override_reason with CHECK `is_override = false OR override_reason IS NOT NULL`, active/superseded CHECK; **partial unique `(tenant_id, encounter_id) WHERE status='active'` — exactly one ACTIVE triage per ER encounter**, `(tenant_id, encounter_id, assessed_at)`), `er_events` (event_type CHECK enum of 14 medico-legal types, nullable notes CHECK ≤2000, occurred_at NOT NULL, nullable actor_staff_id; `(tenant_id, encounter_id, occurred_at)` + `(tenant_id, patient_id, occurred_at)`; immutable — no UPDATE/DELETE path exists).
  - `backend/database/migrations/2026_08_16_210100_enable_er_row_level_security.php` — TENANT_FACILITY tier, RLS on + FORCED, claims-only (zero GUC refs): **4 tables × 4 policies = 16 → 204 → 220 total; scoped matrix 52 → 56 tables**.
  - `backend/app/Models/{ErRegistration,TriageScale,TriageAssignment,ErEvent}.php` + 4 factories.
  - `backend/app/Services/ErService.php` — `register` (full patient + ER encounter + registration + first `registered` event in one transaction; unidentified path), `assignTriage` (CAS-supersede the active row or insert; partial-unique backstop → 409 CONFLICT; override reason carried), `recordEvent` (append-only), `dispose` (admitted → AdmissionService::admit CAS bed claim, encounter stays open with disposition; referred/home/deceased → closed; CAS on status+lock_version → stale disposer 409 LOCK_CONFLICT).
  - `backend/app/Http/Controllers/Api/ErController.php` + 6 request classes (`StoreErRegistrationRequest` with DOB/estimatedAge mutual exclusion, `StoreTriageScaleRequest`, `UpdateTriageScaleRequest` with CAS lockVersion, `AssignTriageRequest` with overrideReason gated on `er:disposition`, `StoreErEventRequest`, `ErDispositionRequest` with bedId required for admitted).
  - `backend/tests/Feature/EmergencyWorkflowTest.php` — 16 tests / 174 assertions.
- **Files modified:**
  - `backend/app/Models/Patient.php` — sex constants (`male/female/other/unknown`) used by the ER registration validation.
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for the 4 new resources.
  - `backend/database/seeders/RolePermissionSeeder.php` — 6 permissions: `er:view` (clinical + admin + support), `er:register` (front desk — receptionist/org_admin/hospital_admin; NEVER clinical roles), `triage:assign` (nurse/doctor/org_admin/hospital_admin), `er:document` (nurse/doctor/admin), `er:disposition` (doctor/org_admin/hospital_admin — clinical authority; also gates triage overrides), `er:manage` (org_admin/hospital_admin).
  - `backend/routes/api.php` — 9 routes (registration, queue, triage-scales index/store/patch, triage assign, events index/store, disposition).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 204→220 policies; matrix 52→56 on (71 tables / 15 off); new claims proof for the full ER surface (er_registrations, triage_scales, triage_assignments, er_events) — tenant/facility isolation + mutation immunity + org-wide visibility.
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 52→56; full two-tenant seed chain + update probes extended to the four tables.
  - `DATABASE.md` — new §3.17b emergency section with the four-table design, queue/disposition contract, permissions, and audit rules.
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: ROADMAP Phase 9, PRODUCT_REQUIREMENTS §6.6, DATABASE.md §3.17b, CLINICAL_SAFETY, MASTER_RULES §11.2):** registration is **speed over completeness** — a full patient record is always created, but an unidentified patient (no name) gets the documented placeholder `'Unidentified'`, `sex='unknown'`, and `estimated_age` or the sentinel DOB `1900-01-01`; the source facts live on the registration and identity is resolved later through the existing controlled patient-merge (never an unsafe ad-hoc link). The **triage level IS the queue priority** (`GET er/queue`: level asc, untriaged last, oldest registration first). Triage reassessment is the documented supersede path — history preserved, exactly one ACTIVE per encounter (partial unique; a second concurrent active insert is rejected at the DB), and an OVERRIDE requires clinical authority (`er:disposition`) and is audited separately. `er_events` is the medico-legal log — append-only, every event time-stamped, no update/delete path. Disposition `admitted` goes through the **same CAS bed claim as IPD** (no double-booking, admission_type 'emergency', the encounter stays open); referred/home/deceased close the encounter. Audit (`er.registered`, `triage_scale.created/.updated`, `triage.assigned/.overridden`, `er.event`, `er.disposition`) carries facts and ids only — presenting complaints, event notes, override reasons, and discharge notes are PHI and never reach audit payloads (proven).
- **Tests:** EmergencyWorkflowTest **16 / 174** (full registration → patient+MRN+encounter+registration+first event; unidentified registration with placeholder + estimated age; contradictory facts (DOB+age) and unknown fields → 422; triage-scale catalog CRUD with CAS and facility scoping; triage assign + reassessment supersede with preserved history; override requires er:disposition (nurse 403, doctor ok); **partial-unique backstop** — second ACTIVE insert rejected, supersede path keeps exactly one active; events append + chronological immutable list; queue priority (urgent first, untriaged last); disposition admitted → CAS bed claim + emergency admission; disposition home → closed encounter; disposition validation (bedId required, unknown disposition 422); double disposition 409 + **stale concurrent disposer → LOCK_CONFLICT** (service-level CAS race with zero rows changed); non-ER encounter refused on the ER surface; RBAC — pharmacist cannot register, receptionist registers but cannot triage, nurse triages but cannot dispose, unauthenticated 401; cross-tenant isolation with data untouched;
 audit PHI-safety with facts present); RLS suites **27 / 1,142** (new ER-surface claims proof; 56-table sweep; 220 policies / zero GUC refs); full backend Pest **550 passed / 6,118 assertions** (slice-13 baseline 533/5,771 → +17/+347, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; Pint PASS (445 files); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (only the pre-existing harness runner console.log lines); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** reassessment scheduling from `reassessment_minutes` (needs the scheduler/queue), ER observation-area stay tracking, ER-specific charge packages, ambulance/arrival-mode tracking, and CDSS triage decision support — all later-phase per the roadmap.
- **Next steps:** Emergency is complete. Next is the next program phase per the governing plan (Laboratory per ROADMAP), with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 15: complete laboratory workflow (specimens & custody, corrected result versions, HL7/LIS readiness)

- **Phase:** 3 slice 15 (ROADMAP Phase 10 — Laboratory, PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.19/§3.20, CLINICAL_SAFETY §7, INTEROPERABILITY.md §5). The lab catalog/order/result/verify/report/critical-value surfaces already existed (slices 2 + 7). This slice completes the documented Phase 10 deliverables that were missing: **specimens with accession + chain of custody**, **corrected result versions** (an explicit ROADMAP acceptance criterion: corrections must be versioned, verified, and re-trigger critical escalation), and **HL7/LIS readiness** (INTEROPERABILITY.md requires ORU^R01 mappers contract-tested against fixtures).
- **Task:** implement the complete documented laboratory workflow with the same safety discipline: separation of result-entry and verification roles, clinical-safety critical escalation, tenant/facility isolation, claim-based RLS, PHI-safe audit, idempotency, and concurrency guarantees.
- **Files created:**
  - `backend/database/migrations/2026_08_16_220000_create_specimens_and_result_versions.php` — two TENANT_FACILITY tables with tenant-safe composite FKs: `specimens` (lab_order_item_id NOT NULL, accession_no with **tenant+facility-unique partial index `WHERE deleted_at IS NULL`**, specimen_type, collection_method, collected_by_staff_id/collected_at (nullable — ordering + downstream collections fill them), received_by_staff_id/received_at (accession), status CHECK `ordered|collected|processing|completed|rejected`, rejected_reason CHECK ≤500 (required only when rejected), lock_version BIGINT DEFAULT 0, SoftDeletes) and `lab_result_versions` (lab_order_item_id NOT NULL, version_no INT NOT NULL, value, unit, reference_range, flag, result_status, entered_by_staff_id/entered_at, verified_by_staff_id/verified_at (nullable), note ≤1000, lock_version; unique `(tenant_id, lab_order_item_id, version_no)`). Schema additions to `lab_orders` (corrected_reason ≤1000, corrected_by_staff_id, correcting_since, status `correcting` CHECK) and `lab_order_items` (correction_count INT NOT NULL DEFAULT 0, last_corrected_at) plus the `lab_orders` → `staff` composite FK backing.
  - `backend/database/migrations/2026_08_16_220100_enable_specimens_result_versions_row_level_security.php` — TENANT_FACILITY tier, RLS on + FORCED, claims-only (zero GUC refs): **2 tables × 4 policies = 8 → 220 → 228 total; scoped matrix 56 → 58 tables**.
  - `backend/app/Models/{Specimen,LabResultVersion}.php` + 2 factories (composite-tenant-safe).
  - `backend/app/Support/Hl7/{Hl7Segment,Hl7Message,OruR01Parser,OruResultMapper}.php` — pure, dependency-free HL7 v2.3.1 ORU^R01 layer: segment/field parser (escaped-component unescape, correct MSH-1 offset semantics, CR/LF/CRLF tolerant), message model, and the mapper (MSH/PID/OBR/OBX → typed result payload with specimen type, collection time, priority, result status, unit/reference-range/flag). No live connection, no network I/O — a readiness/mapping layer.
  - `backend/tests/Fixtures/hl7/{oru_r01_basic,oru_r01_critical,oru_r01_multiple_orders}.hl7` + `backend/tests/Unit/{Hl7MessageTest,OruResultMapperTest}.php` — 12 tests / 80 assertions.
  - `backend/app/Http/Requests/Lab/{CollectSpecimensRequest,RejectSpecimenRequest,CorrectLabOrderRequest}.php`.
  - `backend/tests/Feature/LaboratoryWorkflowTest.php` — 13 tests / 263 assertions.
- **Files modified:**
  - `backend/app/Http/Controllers/Api/LabOrderController.php` — specimen custody surface (`collect`, `accession`, `process`, `reject`, `complete`) with CAS + status-machine guards (rejected never re-enters; completed is terminal), `correct` (initiates a correction — order → `correcting`, items' results snapshotted into `lab_result_versions` and re-set to entered-by-original-entrant for re-entry; **a correction to a previously reported item whose critical event is still OPEN re-triggers the critical event** — the existing partial-unique backstop makes this safe), `enterResults`/`verifyResults` now write/verify the latest **version** (latest version stamped, not the oldest — corrected the relation-ordered ASC pitfall with `reorder()->latest()`), and `present()` includes specimens + latest version per item.
  - `backend/app/Models/LabOrder.php` — STATUS_CORRECTING, correcting casts + relations; `LabOrderItem.php` — `versions()` (ordered by version_no ASC) + correction counters.
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for `specimen` and `lab_result_version`.
  - `backend/database/seeders/RolePermissionSeeder.php` — `lab:correct` (doctor/lab_supervisor/hospital_admin — a correction is a clinical act; lab technicians enter and verify but cannot correct) + lab_supervisor grant of the existing lab permissions.
  - `backend/routes/api.php` — 7 routes (specimen custody: collect/accession/process/reject/complete; correction: correct).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 220→228 policies; matrix 56→58 on (73 tables / 15 off); new claims proof for the two new tables (tenant/facility isolation + mutation immunity + org-wide visibility).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 56→58; full two-tenant seed chain + update probes extended to the two tables.
  - `DATABASE.md` — §3.19/§3.20 specimens + result-version design, correction lifecycle, and status machine; `INTEROPERABILITY.md` — HL7 readiness row updated from Design to "readiness layer implemented" (mappers + fixtures contract-tested; live LIS transport remains future).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: ROADMAP Phase 10, PRODUCT_REQUIREMENTS §6.7, CLINICAL_SAFETY §7, INTEROPERABILITY.md §5):** specimens are the **chain of custody** — collection records who drew what when, accession records receipt, and processing/completed advance the status machine (rejected is final and reason-required; completed is terminal; CAS lock_version guards every transition so two technicians cannot race a specimen into an invalid state). A correction NEVER mutates history: the reported values are snapshotted into `lab_result_versions` (append-only), the order enters `correcting`, items re-enter results, and the new values are verified/reported through the SAME entry/verification separation as the original — a corrected value is never released without verification. Critical escalation re-triggers on correction only for items whose previous event is still OPEN (escalation backstop: the partial unique `uq_critical_value_events_tenant_item_open` — an open event is never duplicated, a closed event is re-opened by the corrected value). HL7 is a **readiness layer, not an integration**: the ORU^R01 parser/mapper are pure, fixture-tested, and versioned, but inbound transport (inbox/dedup/webhook signature) and any LIS connection remain future — nothing is stubbed as live. Audit (`specimen.collected/.accessioned/.processed/.rejected/.completed`, `lab_result_version.entered/.verified`, `lab_order.correcting/.corrected`) carries facts and ids only — values, units, notes, specimen types, and correction reasons are PHI/clinical and never reach audit payloads (proven).
- **Tests:** LaboratoryWorkflowTest **13 / 263** (custody lifecycle happy path — collect/accession/process/complete with actor stamps; reject requires reason and is terminal; invalid transitions 422/409; CAS stale race → LOCK_CONFLICT zero rows changed; RBAC — technician enters+verifies, cannot correct (403), lab_supervisor/doctor correct, pharmacist denied, unauthenticated 401; correction lifecycle — snapshot created, order `correcting`, re-entry + re-verify + re-report, version_no increments, original version preserved; **critical re-trigger on correction** (open event not duplicated / closed event re-opened); verification separation — unverified value cannot be reported; PHI-safe audit with facts present and no values/notes in payloads); HL7 unit t
ests **12 / 80** (parse + unescape, MSH-1 offset semantics, CRLF tolerance, multi-order messages, critical-flag mapping, mapper field placement verified against fixtures); RLS suites **28 / 1,190** (new specimens/versions claims proof; 58-table sweep; 228 policies / zero GUC refs); full backend Pest **576 passed / 6,522 assertions** (slice-14 baseline 550/6,118 → +26/+404, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; Pint PASS (461 files); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (no probes — the temporary `_dbg_*` files were removed); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** live LIS/analyzer connection and inbound message transport (inbox, dedup, webhook signature verification — INTEROPERABILITY.md §8/§11, still future), reference-range/flag derivation from patient demographics (ranges are captured per result), auto-panics/Delta checks, CDSS interpretation, specimen printing/labels, and instrument interfacing — all later-phase per the roadmap.
- **Next steps:** Laboratory is complete. Next is the next program phase per the governing plan (Radiology per ROADMAP), with the same success/failure/RBAC/isolation/audit/concurrency discipline.

---

## 2026-08-16 — Phase 3 slice 16: Radiology (orders, modality scheduling, studies, preliminary/final reports, DICOM references)

- **Phase:** 3 slice 16 (ROADMAP Phase 11 — Radiology, PRODUCT_REQUIREMENTS §6.9, DATABASE.md §3.29, CLINICAL_SAFETY §8, INTEROPERABILITY.md §5). Radiology orders already ran on the SHARED lab/radiology order surface (§3.28 — a `lab_tests` row with `category='radiology'`). This slice implements the documented Phase 11 deliverables that were missing: the **modality catalog with scheduling**, the **study record** (the imaging lifecycle on the shared order surface), **preliminary vs final reports with verification discipline and visible timing**, **amendments as preserved new versions**, and **DICOM/PACS references that never dangle**.
- **Task:** implement the complete documented Radiology workflow with the same safety discipline: report timing/traceability (preliminary at / final at — CLINICAL_SAFETY §8), no dangling DICOM references, authorization (radiology:* permissions + the new radiographer/radiologist roles), tenant/facility isolation, claim-based RLS, PHI-safe audit, idempotency, and concurrency safety.
- **Files created:**
  - `backend/database/migrations/2026_08_16_230000_create_radiology_tables.php` — four TENANT_FACILITY tables with tenant-safe composite FKs: `modalities` (code unique per tenant+facility while active, modality_type CHECK xray/usg/ct/mri/fluoroscopy/mammography/other, status CHECK active/inactive/down — `down` documents modality downtime, daily_capacity, SoftDeletes, lock_version), `studies` (lab_order_id NOT NULL → the shared order surface, modality_id NULL, status CHECK ordered/scheduled/performed/reported/cancelled, **cancel_reason CHECK-required when cancelled**, ordered_at/scheduled_at/performed_at, performed_by_staff_id, preparation_instructions, lock_version; **partial unique `uq_studies_tenant_order` — one study per order**), `radiology_reports` (study_id NOT NULL, report_type CHECK preliminary/final/addendum, status CHECK draft/preliminary/final/amended, content/impression/critical_findings, reported_by_staff_id/reported_at, verified_by_staff_id/verified_at NULL with CHECK, parent_report_id NULL — the amendment chain; **partial unique `uq_radiology_reports_tenant_study_final` — exactly one ACTIVE final per study**), `image_references` (study_id NOT NULL, reference_type CHECK dicom_study_instance_uid/dicom_series_instance_uid/dicom_sop_instance_uid/pacs_url, reference_value — references only, never pixels). Composite FK ordering: the (tenant_id, id) / (tenant_id, facility_id, id) unique backers are declared before the child FKs (the self-referencing parent FK is added via ALTER after its backer — PostgreSQL validates at declaration time).
  - `backend/database/migrations/2026_08_16_230100_enable_radiology_row_level_security.php` — TENANT_FACILITY tier, RLS on + FORCED, claims-only (zero GUC refs): **4 tables × 4 policies = 16 → 228 → 244 total; scoped matrix 58 → 62 tables**.
  - `backend/app/Models/{Modality,Study,RadiologyReport,ImageReference}.php` + 4 factories.
  - `backend/app/Services/RadiologyService.php` — `createOrder` (radiology catalog items only; order + study created atomically), `schedule` (CAS ordered → scheduled; modality must be active in tenant+facility), `perform` (CAS scheduled → performed), `cancel` (CAS, terminal, reason-required), `draftReport` (performed only), `verifyReport` (draft → preliminary|final; **different-staff guard — the verifier must not be the drafter**; a verified final CAS-advances the study to `reported`), `amendReport` (final → amended preserved + NEW draft with parent link; study back to performed for re-verification), `addImageReferences` (performed/reported only).
  - `backend/app/Http/Controllers/Api/RadiologyController.php` + 9 request classes (`StoreRadiologyOrderRequest`, `StoreModalityRequest`, `UpdateModalityRequest` with CAS, `ScheduleStudyRequest`, `PerformStudyRequest`, `CancelStudyRequest`, `DraftRadiologyReportRequest`, `VerifyRadiologyReportRequest`, `AmendRadiologyReportRequest`, `StoreImageReferenceRequest`).
  - `backend/tests/Feature/RadiologyWorkflowTest.php` — 14 tests / 108 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 14 routes (order from encounter, radiology queue, modalities index/store/patch, study show/schedule/perform/cancel, report draft, verify, amend, image references, patient report list).
  - `backend/database/seeders/RolePermissionSeeder.php` — 7 permissions (`radiology:view/order/schedule/perform/report/verify/manage`) + 2 new facility-scoped roles: `radiographer` (view/schedule/perform) and `radiologist` (view/report/verify — verification of a DIFFERENT radiologist's report is the discipline); org_admin/hospital_admin get the full surface; doctor gets view+order; nurse/branch_manager get view.
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for the 4 new resources.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 228→244 policies; matrix 58→62 on (77 tables / 15 off); new claims proof for the full radiology surface (modalities, studies, radiology_reports, image_references) — tenant/facility isolation + mutation immunity + org-wide visibility.
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 58→62; full two-tenant seed chain + update probes extended to the four tables.
  - `DATABASE.md` — §3.29 rewritten from "planned" to implemented with the four-table design, state machines, and audit rules; `INTEROPERABILITY.md` — DICOM row updated from Design to "readiness layer implemented" (reference model + contract-tested; MWL/live PACS remain future).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: ROADMAP Phase 11, PRODUCT_REQUIREMENTS §6.9, CLINICAL_SAFETY §8, INTEROPERABILITY.md §5):** the study is the traceability anchor — report → study → modality → order, and every report names its study (a report cannot be released against a study that does not exist; the composite FKs make dangling references structurally impossible). Preliminary vs final is explicit with visible timing (`reported_at`/`verified_at` on every row); a FINAL release advances the study to `reported` (a preliminary never does); verification is a distinct audited act by a DIFFERENT radiologist (entry ≠ verification, mirroring lab) — the same radiologist verifying their own draft is 403. Amendments NEVER mutate history: the superseded final is preserved (`amended`), a new draft references it via `parent_report_id`, and the partial unique guarantees exactly one active final per study. `critical_findings` is CAPTURED on the report but NOT auto-escalated in this slice (the radiology analogue of `critical_value_events` is later-phase; the field is never in audit payloads). Modality `down` status is the documented downtime fallback. Audit (`radiology_order.created`, `modality.created/.updated`, `radiology_study.scheduled/.performed/.cancelled`, `radiology_report.drafted/.verified/.amended`, `radiology_study.image_references`) carries facts and ids only — report content, impressions, and critical-findings text are PHI and never reach audit payloads (proven).
- **Tests:** RadiologyWorkflowTest **14 / 108** (order + study atomic, priority/indication carried; non-radiology catalog item rejected 422; RBAC — unauthenticated 401, nurse cannot order 403, pharmacist no radiology visibility 403, ordering doctor cannot schedule 403; full lifecycle — schedule/perform/draft/verify with different-staff guard (same radiologist 403) and study→reported on final; preliminary verifies without releasing the study; cannot draft before performed 409; amendment — original preserved `amended`, new draft parent-linked, re-verification required, exactly one active final; DICOM references attach only to performed studies (409 on ordered) and the composite FK is the no-dangling guarantee; patient surface exposes released preliminary AND final for the bound patient only; stale concurr
ent schedule → 409 with zero rows changed; cross-tenant isolation — read 404, write 403, queues isolated, data untouched; audit PHI-safety — no content/impression/criticalFindings keys and no secret strings in any payload; modality CAS update + documented downtime; cancel terminal with reason (missing reason 422, cancelled never performed)); RLS suites **29 / 1,286** (new radiology-surface claims proof; 62-table sweep; 244 policies / zero GUC refs); full backend Pest **593 passed / 7,011 assertions** (slice-15 baseline 576/6,522 → +17/+489, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS; `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (no probes — the temporary debug script was removed); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** critical-findings escalation (the `critical_findings` text is captured on the report; the loud/acknowledged/escalation surface for radiology is a later-phase analogue of `critical_value_events`), DICOM MWL worklists and live PACS connections (INTEROPERABILITY.md §DICOM remains future — the reference model is the enabler), report-turnaround monitoring (production metric), modality capacity conflict-checking beyond the unique slot index, and radiology charge packages (later-phase billing) — all documented in DATABASE.md §3.29.
- **Next steps:** Radiology is complete. Next is the next program phase per the governing plan, with the same success/failure/RBAC/isolation/audit/concurrency discipline.
---

## 2026-08-16 — Phase 3 slice 17: Pharmacy batch/expiry + controlled-substance dual verification

- **Phase:** 3 slice 17 (ROADMAP Phase 12 — remaining documented Pharmacy scope; PRODUCT_REQUIREMENTS §6.7; DATABASE.md §3.30/§3.31). Building on slices 3 (dispensing) and 8 (returns/reversals), this slice implements the missing pharmacy deliverables: **batch-selected dispensing**, **batch/expiry tracking**, **policy-driven verification**, **expired-batch prevention**, and **Phase-2 controlled-substance dual verification**.
- **Task:** implement the complete remaining pharmacy surface with the same safety discipline: stock-ledger correctness, no double-dispense, transactional stock/charge behavior, tenant/facility isolation, claim-based RLS, PHI-safe audit, idempotency, and concurrency safety — preserving the existing returns/reversals and financial linkage.
- **Files created:**
  - `backend/database/migrations/2026_08_16_240000_create_stock_batches_and_dual_verification.php` — one TENANT_FACILITY table plus additive dispensing columns: `stock_batches` (tenant_id, facility_id, inventory_item_id, medication_id, batch_number, expiry_date, quantity_received CHECK >= 0, quantity_remaining CHECK >= 0, status CHECK available/depleted/quarantined, controlled_dispense_requires_dual, lock_version; unique `(tenant_id, inventory_item_id, batch_number)`, `(tenant_id, id)` composite-FK backer declared BEFORE the child FKs, FEFO index `(tenant_id, facility_id, expiry_date)`); `prescription_lines` gains `batch_id/batch_number/batch_expires_at/batch_quantity_minor` (source-batch stamps, CHECK batch_quantity_minor > 0, composite FK to stock_batches) + `dual_verified_by_staff_id/dual_verified_at` (tenant-scoped FK to staff since lines have no facility_id; CHECK dual_verified_at null or dual_verified_by_staff_id not null); `inventory_movements` gains `stock_batch_id` (batch-level ledger traceability, composite FK, index).
  - `backend/database/migrations/2026_08_16_240100_enable_stock_batches_row_level_security.php` — TENANT_FACILITY tier, RLS on + FORCED, claims-only (zero GUC refs): **1 table × 4 policies = 4 → 244 → 248 total; scoped matrix 62 → 63 tables**.
  - `backend/app/Models/StockBatch.php` + `backend/database/factories/StockBatchFactory.php`.
  - `backend/app/Services/PharmacyService.php` — `fefoBatch` (available, unexpired, stocked, ordered by expiry then created, `lockForUpdate`), `resolveSelectedBatch` (medication+tenant+facility validated; unknown/wrong-medication → 422; **expired → 409 never issuable**; unavailable/depleted → 409), `deductFromBatch` (CAS on status + expiry + quantity_remaining + lock_version → concurrent/deleted/expired batch leaves 0 rows and 409), `requiresDualVerification`.
  - `backend/tests/Feature/PharmacyScopeTest.php` — 9 tests / 80 assertions.
- **Files modified:**
  - `backend/app/Http/Controllers/Api/PharmacyController.php` — batch-aware `dispense` (explicit `batchSelections` keyed by line or FEFO auto-selection; each line's batch resolved + CAS-deducted atomically in the same transaction as shelf deduction, ledger movement, line stamps, and charge posting), new `dualVerify` action (second pharmacist's stamp; dispenser != second verifier; only controlled lines require it), `present`/`presentLine` expose batchId/batchNumber/batchExpiresAt/batchQuantityMinor/dualVerified.
  - `backend/app/Http/Controllers/Api/InventoryController.php` + `StoreInventoryRequest` — batch-aware receipts (optional `batches` array creates/upserts `stock_batches` rows).
  - `backend/app/Models/InventoryMovement.php` (+ `stock_batch_id`), `backend/app/Models/PrescriptionLine.php` (batch + dual-verify fields, `batch_expires_at` date cast).
  - `backend/app/Services/PharmacyReturnService.php` — a return restores stock to the SAME batch the line was dispensed from (ledger movement carries the batch).
  - `backend/app/Support/AuditLogger.php` — resource map entries for `stock_batches` (inventory.received) and the dual-verify surface.
  - `backend/routes/api.php` — dual-verify route.
  - `backend/database/seeders/RolePermissionSeeder.php` — `pharmacy:dual_verify` permission (pharmacist role).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 244→248 policies; matrix 62→63 on (78 tables / 15 off); new claims proof for `stock_batches` (tenant/facility isolation + mutation immunity).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 62→63; full two-tenant seed chain + update probes extended to `stock_batches` (ledger movement chained to its batch).
  - `backend/tests/Feature/PharmacyDispensingTest.php` + `PharmacyReturnReversalTest.php` — stock helpers create matching batches (batch-aware dispense requires them).
  - `DATABASE.md` — §3.30/§3.31 rewritten from \"planned\" to implemented (batch stamps, dual verification, expiry CAS, batch-level ledger traceability).
- **Database changes:** TWO migrations (schema + RLS/FORCE). No production/staging schema touched; disposable local PostgreSQL only.
- **Key contract decisions (source: ROADMAP Phase 12, PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.30/§3.31):** a batch is the unit of expiry-safe dispensing — FEFO auto-selection among available, unexpired batches, or an explicit pharmacist selection validated against the line's own medication; an EXPIRED batch is structurally un-issuable (the deduction CAS refuses it — 409 — and explicit selection is refused the same way). Controlled substances carry a per-batch policy flag; the dispenser and the second verifier MUST differ (self-verify 403/422) and the line is not released until both stamps are present. Dispensed lines snapshot their source batch (id/number/expiry/quantity) so a return restores the SAME batch — the financial linkage via the existing charge is untouched, and the ledger records the batch on every movement (batch-level traceability). No double-dispense: the batch CAS (status + expiry + quantity_remaining + lock_version) lets exactly one concurrent dispense win; the loser 409s with zero rows changed. Audit (`inventory.received`, `pharmacy.dispensed`, dual-verify stamps) carries facts and ids only — medication names, batch numbers, and free-text reasons are PHI/operational and never reach audit payloads (proven).
- **Tests:** PharmacyScopeTest **9 / 80** (FEFO picks oldest-expiry first with ledger + shelf agreement; explicit batch selection works and wrong-medication batch 422; expired batch skipped by FEFO and explicit selection 409 never issuable; controlled dual verification required, same-pharmacist rejected, non-controlled lines exempt; return restores the SAME batch with consistent ledger; double-dispense CAS — one winner, loser 409 with nothing changed; cross-tenant isolation — batch data unreachable and immutable from outside; audit PHI-safety); existing pharmacy suites re-run green after the batch-aware dispense change (PharmacyDispensingTest + PharmacyReturnReversalTest); RLS suites **29 / 1,286** (new stock_batches claims proof; 63-table sweep; 248 policies / zero GUC refs; FORCE intact); full backend Pest **602 passed / 7,108 assertions** (slice-16 baseline 593/7,011 → +9/+97, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS (490 files, 4 style fixes re-verified); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (no probes); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** pharmacy returns of PARTIAL batches with batch-lot selection by the pharmacist (returns currently restore to the exact source batch — correct but not pharmacist-selectable), broader billing/finance (refund disbursement completion, invoice refinement — later phases), and procurement (vendors/POs/GRNs — DATABASE.md §3.32 still planned) — all later-phase per the roadmap.
- **Next steps:** Pharmacy scope is complete. Next is the next program phase per the governing plan (broader Billing/Finance), with the same success/failure/RBAC/isolation/audit/concurrency discipline.
---

## 2026-08-16 — Phase 3 slice 18: Billing and Finance (deposits, aging, settlements, insurance claims)

- **Phase:** 3 slice 18 (ROADMAP Phase 13 — remaining Billing and Finance; PRODUCT_REQUIREMENTS §6.13–6.14; DATABASE.md §3.33–3.35; BILLING.md §14). Building on slices 5/11 (refunds) and Phase 6/7 (charges/invoices/payments), this slice implements the remaining documented deliverables: **deposits** (advance payments held and allocated — exact, CAS), **outstanding aging** (computed from invoice truth), **daily cashier reconciliation** (variance never silently absorbed), and **insurance policies/claims** (built from invoice truth, submitted, tracked, settled). No payment gateway is connected (INTEROPERABILITY.md §13 — planned, no provider contract exists) and nothing is faked.
- **Task:** implement the complete remaining Billing and Finance surface with the same safety discipline: integer money, immutable postings/reversals, segregation of duties, idempotency, exact allocation, tenant/facility isolation, claim-based RLS, PHI-safe audit, and concurrency safety.
- **Files created:**
  - `backend/database/migrations/2026_08_16_250000_create_finance_tables.php` — five tables: `deposits` (TENANT_FACILITY — amount CHECK > 0, remaining CHECK 0..amount, status active/exhausted/refunded, idempotency key unique per tenant, lock_version), `deposit_allocations` (append-only; **unique (tenant, deposit, invoice)** — a double allocation is structurally impossible; composite FKs to deposits/invoices), `settlements` (TENANT_FACILITY — unique (tenant, facility, cashier, day), expected/actual/variance, status open/reconciled/disputed, lock_version), `claims` (TENANT tier — one per (invoice, policy) full unique, claim_number unique per tenant, status draft/submitted/pending/partial/paid/denied, settlement_minor, lock_version), `claim_lines` (TENANT tier — **unique (tenant, invoice_line_id)** — one claim line per invoice line, billed = invoice truth). Backers `uq_insurance_policies_tenant_id`/`uq_invoice_lines_tenant_id` declared before the child FKs.
  - `backend/database/migrations/2026_08_16_250100_enable_finance_row_level_security.php` — 3 TENANT_FACILITY + 2 TENANT tier, RLS on + FORCED, claims-only (zero GUC refs): **5 tables × 4 policies = 20 → 248 → 268 total; scoped matrix 63 → 68 tables**.
  - Models + factories: `Deposit`, `DepositAllocation`, `Settlement`, `InsuranceClaim` (`$table = 'claims'`), `InsuranceClaimLine` (`$table = 'claim_lines'`).
  - `backend/app/Services/FinanceService.php` — `collectDeposit` (idempotent per key), `allocateDeposit` (CAS on status+remaining+lock_version; invoice must be same tenant/facility/patient; over-allocation 422; double allocation 409 — pre-checked so the unique is a clean 409, not a 500), `reconcileSettlement` (expected = the cashier's user's captured payments for the day; zero variance reconciles, non-zero disputes; second reconcile of a closed day CAS-fails 409), `buildClaim` (lines copy invoice amount+tax), `submitClaim` (draft→submitted CAS), `reopenClaim` (denied→draft for resubmission — no duplicate rows), `recordClaimStatus` (submitted→pending|denied; pending→partial|paid|denied; denial requires reason; settlement never exceeds billed; CAS).
  - `backend/app/Http/Controllers/Api/FinanceController.php` + 6 request classes (StoreDeposit, AllocateDeposit, ReconcileSettlement, StoreClaim, RecordClaimStatus, SettleClaim). Claims are TENANT-tier (no facility_id) so the scope gate runs through the claim's invoice (the established parent-resource pattern) — `AccessCheck::scoped` on a tenant-tier model with a facility context would wrongly deny.
  - `backend/tests/Feature/FinanceWorkflowTest.php` — 14 tests / 178 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 12 routes (patient deposits list/collect, deposit allocate, patient aging, cashier-settlements list/reconcile, invoice claims list/build, claim show/submit/reopen/status/settle) + FinanceController import.
  - `backend/database/seeders/RolePermissionSeeder.php` — 3 new permissions: `billing:reconcile` (org_finance/org_admin/hospital_admin — NOT billing_clerk: the cashier whose drawer is settled does not reconcile it), `insurance:claim` (clerk + finance roles), `insurance:settle` (finance roles only — the clerk builds/submits but never settles; segregation of duties).
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for `deposit`, `deposit_allocation`, `settlement`, `insurance_claim` (tenant-tier — resolves to a tenant-level event).
  - `backend/app/Models/Patient.php` (deposits relation), `Invoice.php` (claims relation).
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 248→268 policies; matrix 63→68 on (83 tables / 15 off); new claims proof for the whole finance surface (deposits/allocations/settlements TENANT_FACILITY + claims/claim_lines TENANT tier — facility-agnostic).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 63→68; full two-tenant seed chain + update probes extended to the five tables.
  - `DATABASE.md` (§3.33–3.35 rewritten from \"planned\" to implemented), `BILLING.md` (§14 implemented-status note).
- **Database changes:** TWO migrations (schema + RLS/FORCE). Disposable local PostgreSQL only; no production/staging schema touched. (Note: the first RLS migration application lacked the `enable/force row level security` statements — the policies were created but RLS stayed off; fixed in the migration and both local DBs re-verified: `relrowsecurity`/`relforcerowsecurity` true on all five tables.)
- **Key contract decisions (source: ROADMAP Phase 13, PRODUCT_REQUIREMENTS §6.13–6.14, DATABASE.md §3.33–3.35):** deposits are held money — collection is idempotent, allocation is exact (CAS on remaining + unique per deposit+invoice pair), and a deposit can never be over-allocated or double-allocated. Settlements reconcile the DAY per cashier — expected is derived from captured payments, and a variance is never silently absorbed (disputed + audited). Claims map EXACTLY to invoice truth — `billed_minor` is the frozen invoice amount+tax, one claim line per invoice line (unique), one claim per (invoice, policy); a denial REQUIRES a reason and resubmission REOPENS the same claim (denied→draft→submitted) so no duplicate rows or fabricated lines are ever created — the denial stays in the audit trail. Settlements never exceed the billed total. No payment gateway is connected or faked (INTEROPERABILITY.md §13 — planned, no provider contract). Audit (`deposit.collected/.allocated`, `settlement.reconciled`, `insurance_claim.built/.submitted/.reopened/.status/.settled`) carries facts and ids only — patient names, policy numbers, denial-reason text, and settlement notes never reach payloads (proven).
- **Tests:** FinanceWorkflowTest **14 / 178** (deposit collect idempotent replay; exact allocation with remaining decrement + exhaust; over-allocation 422, cross-patient invoice 422, double allocation 409; concurrent allocation CAS — stale snapshot affects zero rows and the post-race state is correct; aging buckets computed from invoice truth with paid excluded; settlement reconcile/dispute with variance, closed-day CAS 409, expected per cashier+day; claim build maps lines to invoice truth, duplicate build 409, submit CAS double-submit 409, status tracking with denial-reason requirement, settlement capped at billed, double settle refused, draft→paid refused, denied reopen + resubmission reusing the same row; RBAC — unauthenticated 401, doctor cannot collect 403 (reads granted), clerk cannot settle/reconcile 403; cross-tenant isolation — read 404, write denied, data untouched; audit PHI-safety — no names/policy numbers/free-text); existing billing suites (RefundAdjustment, BillingPayment) re-run green; RLS suites **30 / 1,400** (new finance claims proof; 68-table sweep; 268 policies / zero GUC refs; FORCE intact); full backend Pest **617 passed / 7,428 assertions** (slice-17 baseline 602/7,108 → +15/+320, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS (re-verified); `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (no probes); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** payment gateway integration (planned — INTEROPERABILITY.md §13, no provider contract exists, nothing faked), bank reconciliation feeds, general-ledger/accounting exports (Phase 2), insurance payer portals/EDI (future), benefit-limit enforcement at charge time (configured, not enforced), and deposit-refund reversals (deposit remaining is tracked; the charge-linked refund workflow remains the documented refund path) — all later-phase per the roadmap.
- **Next steps:** Billing and Finance is complete for the documented MVP+Phase-2 claim surface. Next is the next program phase per the governing plan (Inventory and Procurement — ROADMAP Phase 14), with the same success/failure/RBAC/isolation/audit/concurrency discipline.
## 2026-08-16 — Phase 3 slice 19: HR and Assets (ROADMAP Phase 15)

- **Phase:** 3 slice 19 (ROADMAP Phase 15 — HR Phase 2 + Assets Phase 3; PRODUCT_REQUIREMENTS §6.17–6.18; DATABASE.md §3.45–3.47). Implementing the remaining documented HR surface (employees/positions, shifts, attendance with approval, leave with balance, audited payroll-ready export) and asset surface (register, maintenance, lifecycle, RFID/IoT readiness) — building on the existing staff/departments/locations tables rather than duplicating identity.
- **Task:** implement the complete HR + asset surface with the same safety discipline: staff personal data protected to the same standard as patient data, roster conflict detection (overlaps + rest rules), corrections with approval, leave balance tracking, audited payroll exports (who exported what), honest asset downtime (an asset with an open downtime work order is under_repair — a machine listed as available while down is a planning hazard), append-only transfers, provable maintenance certifications, and the RFID/IoT-ready data model with NO faked device integration. Tenant/facility isolation, claim-based RLS, PHI-safe audit, idempotency, and concurrency safety throughout.
- **Files created:**
  - `backend/database/migrations/2026_08_16_260000_create_hr_assets_tables.php` — 13 tables: HR — `positions` (department-linked catalog, unique code per facility), `shift_templates` (day/night/rotating, working_minutes CHECK), `rosters` (staff × shift × date, unique, CAS lock_version), `attendance_records` (unique per staff+date; correction approval flow with PROPOSED clock times — actual times change only on approval), `leave_types` (paid_days_per_year/carryover_days entitlements), `leave_requests` (range CHECK, CAS), `payroll_exports` (period, row_count, format, payload_hash, exported_by); Assets — `asset_categories`, `assets` (serial/rfid/barcode partial uniques, lifecycle CHECK procured/deployed/under_repair/retired, CAS), `asset_transfers` (append-only location history), `maintenance_schedules` (preventive/contract/certification, frequency CHECK), `work_orders` (number unique per tenant, downtime CHECK end > start, certification_ref), `iot_readings` (location/condition/usage, source rfid/device/manual). Composite-FK backers declared BEFORE child FKs; `uq_locations_tenant_facility_id` added to the existing locations table for the asset location FKs.
  - `backend/database/migrations/2026_08_16_260100_enable_hr_assets_row_level_security.php` — all 13 tables TENANT_FACILITY, RLS on + FORCED, claims-only (zero GUC refs): **13 × 4 = 52 → 268 → 320 policies; scoped matrix 68 → 81 tables**.
  - Models (13) + factories (13): Position, ShiftTemplate, Roster, AttendanceRecord, LeaveType, LeaveRequest, PayrollExport, AssetCategory, Asset, AssetTransfer, MaintenanceSchedule, WorkOrder, IotReading.
  - `backend/app/Services/HrAssetsService.php` — roster conflict detection (overlap + 8h rest rule), roster confirm CAS, attendance correction request/approve/reject (proposed values applied only on approval; CAS), leave approve/reject with balance check in the same transaction (over-entitlement 422; double approval 409), payroll export generation (structured payload + hash + audit row), asset lifecycle transitions (CAS; retired terminal), append-only transfers, work orders with honest downtime (open with downtime → under_repair; complete/cancel → deployed; certification + schedule advancement).
  - `backend/app/Http/Controllers/Api/HrController.php` + `AssetController.php` + 16 request classes (Hr: positions, shift templates, rosters, attendance, correction, leave type, leave request, decide, payroll export; Assets: category, asset, transfer, maintenance schedule, work order open/complete, iot reading).
  - `backend/tests/Feature/HrAssetsTest.php` — 10 tests / 123 assertions.
- **Files modified:**
  - `backend/routes/api.php` — 31 routes (HR + Assets) + controller imports.
  - `backend/database/seeders/RolePermissionSeeder.php` — 9 new permissions (`hr:employee`, `hr:roster`, `hr:attendance`, `hr:leave`, `hr:payroll_export`, `assets:register`, `assets:transfer`, `assets:maintain`, `assets:retire`) granted to org_admin + hospital_admin.
  - `backend/app/Support/AuditLogger.php` — facility-scoped resource map for all 13 resource types.
  - `backend/tests/Feature/ClaimsBasedRlsTest.php` — 268→320 policies; matrix 68→81; new claims proof for the whole HR/asset surface (tenant/facility isolation + mutation immunity).
  - `backend/tests/Feature/TenancyDatabaseInventoryTest.php` — scoped set 68→81; full two-tenant seed chain + update probes extended to the 13 tables.
  - `DATABASE.md` — §3.45–3.47 written (HR, Assets, RLS).
- **Database changes:** TWO migrations (schema + RLS/FORCE). Disposable local PostgreSQL only; no production/staging schema touched.
- **Key contract decisions (source: ROADMAP Phase 15, PRODUCT_REQUIREMENTS §6.17–6.18):** staff IS the employee record (staff/departments/locations already exist — no duplicated identity). A roster conflict is refused (overlap or < 8h rest between consecutive shifts). An attendance correction is never silently edited in: the request captures the reason + PROPOSED times, and only an HR approval applies them (CAS — double approval affects zero rows). Leave balance is COMPUTED at approval time against the entitlement — an over-entitlement approval is refused 422 inside the same transaction, and a double approval is 409 (exactly one winner). The payroll export is an audited fact: who exported what for which period, payload hashed and delivered once. Downtime truthfulness is structural: opening a work order with downtime moves the asset to under_repair in the same transaction; completing/cancelling returns it to deployed — a machine listed as available while down is a planning hazard. Transfers are append-only (never edited/deleted); certifications are provable via certification_ref. iot_readings is the DESIGNED data model for Phase 3 device feeds — manual readings exercise it end to end, nothing is faked. Audit payloads carry facts and ids only — names, license numbers, and free-text reasons never reach them (proven).
- **Tests:** HrAssetsTest **10 / 123** (position catalog RBAC — unauthenticated 401, doctor 403; shift templates + roster creation with overlap 409 and rest-rule 409 and duplicate 409; attendance record → correction request (record untouched) → approve (proposed times applied) → double-approve 409; correction reject leaves clock times untouched; leave balance — approve within entitlement, over-entitlement approval 422, reject consumes nothing, double approve 409; payroll export — structured payload (worked days/shifts/leave), row count, hash, audit row who/what/when; asset lifecycle — register procured → deploy → transfer (append-only) → downtime work order under_repair → complete with certification back to deployed → invalid downtime order 422 → cancel → retire terminal (transfer/deploy refused); iot readings record + list; facility scoping + cross-tenant isolation (read 404, write 403, data untouched); PHI-safe audit payloads across the surface — no names/licenses/reasons); RLS suites **44 / 1,681** (new 13-table HR/asset claims proof; 81-table sweep; 320 policies / zero GUC refs; FORCE intact); full backend Pest **628 passed / 7,924 assertions** (slice-18 baseline 617/7,428 → +11/+496, re-run post-Pint); Node harness **855/855** (unchanged — the slice is Laravel-only, no Supabase Edge Function); frontend vitest **26 passed** + tsc clean; harness tsc PASS; Pint PASS; `git diff --check` CLEAN; artifact/debug-marker sweep CLEAN (no probes); tracked-secret scan 0 matches.
- **Known limitations / remaining scope (NOT implemented):** HR self-service views for employees (the contract's "employees see their own records" — the HR surface is admin-operated; a portal self-view is later-phase), biometric/clock-device attendance integration (schedule-based + manual clocking implemented; device feeds are future), position↔staff binding (staff keeps its free-text designation; linking staff to the position catalog is a later refinement), RFID/IoT device feeds (the data model is designed; Phase 3 device integration is NOT faked), and procurement integration for asset acquisition (assets are registered manually; PO-linked acquisition is later-phase procurement).
- **Next steps:** HR and Assets are complete for the documented Phase 15 surface. Next is the next program phase per the governing plan, with the same success/failure/RBAC/isolation/audit/concurrency discipline.
---

## 2026-08-16 — Phase 3 slice 20: OT, ICU, and Blood Bank (ROADMAP Phase 16)

**Objective.** Implement the surgical, critical-care, and transfusion workflows at the same safety standard as OPD/IPD — theatre scheduling with conflict detection, surgical safety checklists with recorded per-step completion (compliance-gated case closure), ICU acuity-based admission with ENFORCED observation schedules (a missed observation escalates), computed NEWS-style warning scores with acknowledgment-required alerts, and the blood supply chain (donors → componentized units → testing → compatibility/crossmatch → issue → dual-verified transfusion → reaction reporting → discard). Life-critical invariants: wrong-unit and missed-observation are incidents by design.

**Contract.** PRODUCT_REQUIREMENTS §6.10–6.12, ROADMAP Phase 16, DATABASE.md §3.48–3.51, CLINICAL_SAFETY.md §16–17.

**Files changed (22 new tables + models + factories + 3 controllers + 24 requests + service + 2 migrations + RLS/audit/seeder/docs/tests).**
- Migrations: `2026_08_16_270000_create_ot_icu_blood_bank_tables.php`, `2026_08_16_270100_enable_ot_icu_blood_bank_row_level_security.php`
- Models (22): Theatre, ProcedureRequest, Procedure, SurgicalTeamMember, AnesthesiaRecord, SurgicalEvent, ChecklistTemplate, ChecklistItem, RecoveryRecord, IcuBed, IcuAdmission, IcuObservationSet, WarningScore, IcuAlert, CriticalCareNote, Donor, Donation, BloodUnit, CompatibilityResult, Crossmatch, Transfusion, ReactionReport (+ 22 factories)
- Service: `OtIcuBloodBankService` — scheduling conflict detection (theatre row locked), checklist compliance gate, ICU score computation + escalation/missed-observation/threshold alerts + bed CAS, blood issue guards (tested/unexpired/compatible) + dual verification (starter ≠ verifier, completion refused until verified) + wrong-patient refusal + discard
- Controllers/requests: OtController, IcuController, BloodBankController + 24 request classes; routes wired with `authorize:ot:* / icu:* / bloodbank:*` and `AccessCheck::scoped` on every route-bound model
- Permissions (13 new, granted to org_admin + hospital_admin, clinical subset to doctor/nurse/lab_technician): `ot:schedule/document/checklist/close`, `icu:admit/observe/document/transfer`, `bloodbank:register_donor/process/issue/transfuse/discard`
- AuditLogger: 22 new facility-scoped resource types; donor PII (name/DOB/phone) and clinical content (procedure names, observation values, note content) never in payloads — proven
- RLS: 22 TENANT_FACILITY tables, enabled + FORCED — policies 320 → **408**, scoped matrix 81 → **103** tables

**Migrations.** Two, applied to the disposable test DB (`swasthya_test`) and dev DB (`swasthya`). No real/staging Supabase touched.

**Tests.**
- New `OtIcuBloodBankTest` — **16 tests / 139 assertions**: theatre RBAC (401/403), conflict detection (overlap refused 409, non-overlap OK), checklist compliance (close blocked 422 until complete, double-completion 409), team/anesthesia/events/recovery lifecycle, ICU bed CAS (taken bed refused), one-open-admission DB backstop, computed scores + escalation/threshold alerts + acknowledgment audit + double-ack 409, MISSED-observation escalation, bed release on step-down, PHI-safe donor audit, componentized units (quarantined → tested → available / failed → discarded), issue guards (untested/no-crossmatch/incompatible/expired refused; compatible OK), dual verification (same-staff 409, different-staff OK, complete refused until verified, double-verify 409), wrong-patient transfusion refused, reaction + stop, terminal discard, cross-tenant isolation (route-bound 403, body-supplied id 404 — no existence leak)
- ClaimsBasedRlsTest: +1 proof (22-table OT/ICU/blood claims isolation), 320 → 408 policies, matrix 81 → 103
- TenancyDatabaseInventoryTest: +22 tables in the scoped set + chain seed + update probes; 81 → 103 counts

**Gate results (all green).**
| Gate | Result |
|---|---|
| OtIcuBloodBankTest (new) | **16 passed / 139 assertions** |
| RLS suites (ClaimsBased + Inventory) | **32 passed / 2,037 assertions** (408 policies, 103-table sweep, FORCE intact) |
| Full backend Pest (re-run post-Pint) | **645 passed / 8,657 assertions** (Slice-19 baseline 628/7,924 → **+17/+733**) |
| Node harness | **855 / 855** (unchanged — Laravel-only slice) |
| Frontend Vitest | **26 passed** + tsc clean |
| Harness TypeScript | PASS |
| Pint | PASS (re-verified) |
| `git diff --check` | CLEAN |
| Debug-marker / artifact sweep | CLEAN |
| Tracked-secret scan | 0 matches |

**Remaining risks.** Real DICOM/device/barcode integration is not faked (Phase 3 readiness only); ICU bedside offline sync and ventilator feeds are future integration (PRODUCT_REQUIREMENTS §6.11 "queued sync with reconciliation" is designed for, not implemented); blood-bank barcode unit tagging is a future integration; PACU observations are a JSON snapshot (structured PACU observation tables are future work if metrics demand).
---

## Phase 3 — Slice 21: Analytics and Reporting (ROADMAP Phase 17)

**Date:** 2026-08-16

**Objective.** Operational dashboards, financial/clinical analytics, scheduled replica-fed reports, and executive dashboards — from OBSERVED data only (MASTER_RULES.md P.15: no fabricated metrics). Versioned KPI definitions ("a changing KPI is not a KPI"), idempotent metric snapshots computed from the real source tables at generation time, curated dashboards with a drill-down path, and an audited report/export surface executing on the dedicated `reporting` read-replica connection. No new dependencies; monolith preserved.

**Files created.**
- Migrations: `2026_08_16_280000_create_analytics_tables.php` (7 tables), `2026_08_16_280100_enable_analytics_row_level_security.php` (7 × 4 policies, RLS + FORCE)
- Models: `KpiDefinition`, `MetricSnapshot`, `Dashboard`, `DashboardKpi`, `ReportTemplate`, `ReportSchedule`, `ReportRun` (+ 7 factories)
- Service: `AnalyticsService` — whitelisted source/date/filter/sum columns per source (an unlisted column is never read), observed-only snapshot computation on the `reporting` connection, CAS supersede, idempotent refresh (savepoint-guarded), one-run-per-due-schedule CAS
- Requests: `Analytics/*` (8), Controller: `AnalyticsController`, routes (15) under `authorize:analytics:view|manage|reports:run|schedule|export`
- Test: `AnalyticsTest`

**Files modified.** `config/database.php` (new `reporting` connection — `REPORTING_DB_*` envs point at a read replica in production; simulated replica locally), `routes/api.php`, `RolePermissionSeeder` (5 new permissions: analytics:view/manage, reports:run/schedule/export — org_admin + hospital_admin hold all; org_finance holds view+run; branch_manager holds view), `AuditLogger` (7 new resource types), `ClaimsBasedRlsTest`, `TenancyDatabaseInventoryTest`, `DATABASE.md` (§3.52), `OBSERVABILITY.md` (§15), this log.

**Migrations.** Two, applied to the disposable test DB (`swasthya_test`) and dev DB (`swasthya`). No real/staging Supabase touched.

**Tests.**
- New `AnalyticsTest` — **13 tests / 103 assertions**: 401/403 gating; versioned definition creation (v1 active) + duplicate active code 409 + finance can view but not define; unwhitelisted source/column/filter rejected 422; supersede v1→v2 with CAS (stale supersede 409, never double-created); OBSERVED-data proof (snapshot value EQUALS real source count; out-of-window rows excluded; idempotent refresh → one snapshot per period); point-in-time occupancy + sum aggregation over integer money; dashboard composition + drill-down (number → latest snapshot); report run on the reporting connection with row counts; scheduled reports executed exactly once per due window (CAS) with invalid-cron rejected at creation; audited exports with sha256 fingerprint checksum and no PHI on the run row; cross-tenant/cross-facility isolation (404, no existence leak, empty lists); concurrent refresh → single snapshot (DB partial unique); PHI-safe audit payloads (fact keys only)
- ClaimsBasedRlsTest: +1 proof (7-table analytics claims isolation), 408 → 436 policies, matrix 103 → 110
- TenancyDatabaseInventoryTest: +7 tables in the scoped set + chain seed + update probes; 103 → 110 counts

**Gate results (all green).**
| Gate | Result |
|---|---|
| AnalyticsTest (new) | **13 passed / 103 assertions** |
| RLS suites (ClaimsBased + Inventory) | **33 passed / 2,177 assertions** (436 policies, 110-table sweep, FORCE intact) |
| Full backend Pest | **659 passed / 8,975 assertions** (Slice-20 baseline 645/8,657 → **+14/+318**) |
| Node harness | **855 / 855** (unchanged — Laravel-only slice) |
| Frontend Vitest | **26 passed** + tsc clean |
| Harness TypeScript | PASS |
| Pint | PASS (661 files) |
| `git diff --check` | CLEAN |
| Debug-marker / artifact sweep | CLEAN |
| Tracked-secret scan | 0 matches |

**Remaining risks.** Financial/clinical analytics breadth (more sources/dimensions via the same whitelist) and executive dashboards per role are Phase 2/3 per PRODUCT_REQUIREMENTS §6.19; patient-level drill-down stays access-controlled like clinical data; AI forecasting is Phase 3 and never fabricated; the `reporting` connection is a simulated replica locally (real read-replica wiring is a deployment-phase task — `REPORTING_DB_*` envs).
---

## Phase 3 — Slice 22: Patient Portal (PRODUCT REQUIREMENTS §6.2)

**Date:** 2026-08-17

**Objective.** The patient's secure, CONSENT-BOUND window into their OWN hospital record — read-only access to permitted appointments, results, and bills. Strict self-only access (the patient identity is DERIVED from the authenticated portal token by the new `ResolvePortalContext` middleware — never from client input), DB-backed lockout, append-only audited sessions, per-scope consent grants staff issue for a stated purpose and the PATIENT can revoke themselves. No new dependencies; monolith preserved.

**Files created.**
- Migrations: `2026_08_17_290000_create_patient_portal_tables.php` (3 tables), `2026_08_17_290100_enable_patient_portal_row_level_security.php` (3 × 4 policies, RLS + FORCE), `2026_08_17_290200_add_patient_actor_type_to_audit_events.php` (audit CHECK extended with `patient`)
- Models: `PortalAccount`, `PortalSession`, `PortalAccessGrant` (+ 3 factories)
- Middleware: `ResolvePortalContext` (derives patient + projects tenant/facility onto `request.jwt.claims` exactly like ResolveTenantContext; ONE transaction, LOCAL GUCs)
- Service: `PatientPortalService` — identifier normalization (email lower-cased / phone E.164), DB-backed per-account lockout (`lockForUpdate`), within-tenant login resolution (org code disambiguates the tenant), CAS grant revocation, 409-on-duplicate provisioning/grant, session-resolved logout, PHI-safe self-views
- Requests: `Portal/LoginRequest`, `Portal/ProvisionAccountRequest`, `Portal/GrantAccessRequest`
- Controller: `PatientPortalController` (public login; portal-authed me/appointments/results/bills/grants/self-revoke; staff provision/grant/revoke/disable)
- Test: `PatientPortalTest`

**Files modified.** `routes/api.php` (public `portal/login` behind `throttle:auth`; standalone portal-authed group with `throttle:api → auth:sanctum → ResolvePortalContext` — deliberately OUTSIDE the staff tenant group since a portal token's tokenable is a PortalAccount, never a User; staff surfaces under `authorize:portal:manage`), `bootstrap/app.php` (ResolvePortalContext priority slot before SubstituteBindings so portal model binding resolves inside the portal context), `TenantContext` (portalAccount + patient), `AuditEvent` (ACTOR_PATIENT), `AuditLogger` (portal resource types + portal-principal actor derivation), `RolePermissionSeeder` (1 new permission: `portal:manage` — org_admin + hospital_admin), `ClaimsBasedRlsTest`, `TenancyDatabaseInventoryTest`, `DATABASE.md` (§3.53), this log.

**Migrations.** Three, applied to the disposable test DB (`swasthya_test`) and dev DB (`swasthya`). No real/staging Supabase touched.

**Tests.**
- New `PatientPortalTest` — **17 tests / 153 assertions**: login happy path (token + session row); no enumeration (unknown org code ≡ wrong password, same 401); per-account lockout → 429 with Retry-After (per-IP auth throttle raised to isolate the account layer, AuthTest pattern); disabled account → 403; every portal surface 401 unauthenticated; logout revokes the token AND the session row (subsequent 401, idempotent); staff provisioning 201 + duplicate → 409 (one account); receptionist (no `portal:manage`) → 403; appointments consent-bound (no grant → generic 403; grant → own rows only, sibling patient's appointment never leaks, no patientId echoed); results consent-bound (reported orders only — drafts and other patients' reported orders excluded; item result value verified); bills consent-bound (voided + other patients' invoices excluded); patient self-revoke closes the surface immediately AND a patient cannot revoke another patient's grant (404, no existence leak); staff revoke + double-revoke → 409 (CAS); duplicate ACTIVE grant → 409 + re-grant after revocation (DB partial unique); disable revokes every token + session and refuses login; audit events for every action with fact-only payloads (no PHI keys) and `actor_type = patient` for portal login; cross-tenant structural isolation (two tenants, same-shaped accounts, per-tenant tokens)
- ClaimsBasedRlsTest: +1 proof (3-table portal claims isolation: tenant/facility/mutation immunity, org-wide visibility), 436 → 448 policies, matrix 110 → 113
- TenancyDatabaseInventoryTest: +3 tables in the scoped set + chain seed + update probes; 110 → 113 counts

**Gate results (all green).**
| Gate | Result |
|---|---|
| PatientPortalTest (new) | **17 passed / 153 assertions** |
| RLS suites (ClaimsBased + Inventory) | **34 passed / 2,247 assertions** (448 policies, 113-table sweep, FORCE intact, swasthya_app NOBYPASSRLS) |
| Full backend Pest | **677 passed / 9,213 assertions** (Slice-21 baseline 659/8,975 → **+18/+238**) |
| Node harness | **855 / 855** (unchanged — Laravel-only slice) |
| Frontend Vitest | **26 passed** + tsc clean |
| Harness TypeScript | PASS |
| Pint | PASS (677 files) |
| `git diff --check` | CLEAN |
| Debug-marker / artifact sweep | CLEAN |
| Tracked-secret scan | 0 matches |

**Remaining risks.** MFA enforcement for portal accounts is stored (`mfa_enabled`) but Phase-2 enforcement is future; portal password reset and patient document access are future; appointment self-booking (write side) is explicitly NOT in this slice; the patient portal UI is not yet built (API surface only); portal login has no email/phone verification channel yet (identifier is the credential, exactly like staff login — documented, not faked).

## Phase 3 — Slice 23: Interoperability Readiness (ROADMAP Phase 18)

**Date:** 2026-08-17

**Objective.** Readiness layers ONLY — nothing connects to, simulates, or claims a live national/LIS/PACS integration (the honesty clause, INTEROPERABILITY.md §13). Implemented truthfully: the integration registry with MEASURED status, the egress allowlist (SSRF guard), tenant-scoped OAuth2 partner registration/token issuance, fixture-tested FHIR R4 projections and an HL7 v2 ADT^A01 mapper, and signed-webhook verification. No new dependencies; monolith preserved.

**Files created.**
- Migrations: `2026_08_17_300000_create_interop_readiness_tables.php` (5 tables: integrations, integration_events, egress_allowlist, oauth_partners, oauth_partner_tokens), `2026_08_17_300100_enable_interop_row_level_security.php` (5 × 4 = 20 policies, RLS + FORCE, TENANT tier)
- Models: `Integration`, `IntegrationEvent`, `EgressDestination`, `OauthPartner`, `OauthPartnerToken` (+ 5 factories)
- Services: `IntegrationRegistryService` (CAS status/kill-switch, bounded retry budget + quarantine, egress guard, unique 409s), `PartnerOauthService` (client_credentials issuance, hash-at-rest secrets, scoped short-lived revocable tokens), `FhirProjection` (Patient/Encounter/MedicationRequest/DiagnosticReport R4 projections)
- Support: `Hl7/AdtA01Mapper` (canonical internal shape), `WebhookSignature` (HMAC verify with replay protection)
- Middleware: `ResolvePartnerContext` (partner tenant DERIVED from the token — never client input; ONE transaction, LOCAL GUCs, projects only the tenant claim)
- Requests: `Interop/StoreIntegrationRequest`, `RecordIntegrationStatusRequest`, `StoreEgressDestinationRequest`, `RegisterPartnerRequest`, `IssueTokenRequest`
- Controller: `InteropController` (staff registry/allowlist/partner surface under `authorize:integration:view|manage`; public `oauth/token` behind `throttle:auth`; partner FHIR projection surface gated by scope + ACTIVE data-use consent at the boundary)
- Fixtures: `tests/Fixtures/fhir/*.json` (5 resources), `tests/Fixtures/hl7/adt_a01_basic.hl7`
- Test: `InteroperabilityTest`

**Files modified.** `AccessCheck` (new `tenantScoped` — the TENANT-tier mirror of RLS invisibility: a cross-tenant id is a 404 for every method, never an existence leak, never a reachable write; scoped() stays for TENANT_FACILITY models), `routes/api.php` (staff interop group in the tenant group; public `interop/oauth/token`; standalone partner FHIR group OUTSIDE the staff tenant group with `throttle:api → ResolvePartnerContext`), `bootstrap/app.php` (ResolvePartnerContext priority slot before SubstituteBindings), `RolePermissionSeeder` (2 permissions: `integration:view` + `integration:manage` — org_admin + hospital_admin; support_agent view-only), `AuditLogger` (interop resource types + ACTOR_INTEGRATION), `ClaimsBasedRlsTest`, `TenancyDatabaseInventoryTest`, `DATABASE.md` (§3.54), `INTEROPERABILITY.md` (§13 FHIR row + slice-23 note), this log.

**Migrations.** Two, applied to the disposable test DB (`swasthya_test`) and dev DB (`swasthya`). No real/staging Supabase touched.

**Tests.**
- New `InteroperabilityTest` — **22 tests / 104 assertions**: FHIR projections match fixtures exactly (patient/encounter/lab observation/medication request/reported diagnostic report); ADT^A01 maps to the canonical shape; webhook signature verify + replay protection; integration registration + duplicate 409; registry management denied without `integration:manage`; measured status CAS (concurrent writer 409); kill-switch independent + audited; egress allowlist enforced (SSRF guard); partner registration returns the client secret exactly once; scoped client_credentials issuance + bad/revoked client refusal; partner revocation revokes every token; partner surface requires auth + resource scope; consent at the boundary (no ACTIVE data-use consent → 403); FHIR Patient served to a scoped consented partner; DiagnosticReport only for REPORTED orders; **cross-tenant isolation both ways** (a tenant-A partner token cannot resolve tenant-B's patient → 404; a tenant-B admin cannot bind tenant-A's integration → 404 — no existence leak); audit payloads fact-only (no secret/token keys anywhere)
- ClaimsBasedRlsTest: interop claims isolation proof; 448 → 468 policies, matrix 113 → 118
- TenancyDatabaseInventoryTest: +5 tables in the scoped set + chain seed + update probes; 113 → 118 counts

**Gate results (all green).**
| Gate | Result |
|---|---|
| InteroperabilityTest (new) | **22 passed / 104 assertions** |
| RLS suites (ClaimsBased + Inventory) | **34 passed / 2,332 assertions** (468 policies, 118-table sweep, FORCE intact, swasthya_app NOBYPASSRLS) |
| Full backend Pest | **699 passed / 9,432 assertions** (Slice-22 baseline 677/9,213 → **+22/+219**) |
| Node harness | **855 / 855** (unchanged — Laravel-only slice) |
| Frontend Vitest | **26 passed** + tsc clean |
| Harness TypeScript | PASS |
| Pint | PASS (677 files, 1 style fix in InteroperabilityTest) |
| `git diff --check` | CLEAN |
| Debug-marker / artifact sweep | CLEAN (removed a stray empty file artifact) |
| Tracked-secret scan | 0 matches (remaining hits are pre-existing non-secret column-name/script matches) |

**Remaining risks.** Real adapter implementations (LIS/PACS/national) are explicitly OUT of scope and never claimed — the registry records readiness truthfully; live FHIR/HL7 endpoints depend on real partners; the reporting replica wiring remains a deployment-phase task; the egress allowlist and webhook verification are the guards a future adapter must pass.

---

## Phase 3 — Slice 24: Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS §6.20)

**Date:** 2026-08-17

**Objective.** Virtual consultations integrated with the SAME record, not a separate product (CLINICAL_SAFETY.md §7): a teleconsult is booked through the SAME schedule/queue model as OPD (`appointment_type = 'teleconsult'`), then conducted over a secure video session with an EXPLICIT, consent-bound recording decision and a documented connectivity-failure fallback. The shared `Encounter` (`type = 'teleconsult'`) is created at start and documented/signed to the SAME standard as OPD. No new dependencies; monolith preserved.

**Files created.**
- Migrations: `2026_08_17_310000_create_telehealth_tables.php` (teleconsults + video_sessions — video sessions are METADATA ONLY: provider room ref / storage ref, never pixels or media), `2026_08_17_310100_enable_telehealth_row_level_security.php` (2 × 4 = 8 policies, RLS + FORCE, TENANT_FACILITY tier)
- Models: `Teleconsult`, `VideoSession` (+ 2 factories)
- Service: `TelehealthService` — the full state machine (scheduled → ready → in_progress → completed; cancelled / failed side branches), the CONSENT GATE (ACTIVE telehealth consent covering the medium before start), the RECORDING POLICY (facility `telehealth.recording_policy` setting — default disabled — + separate `telehealth:record` permission + patient consent when required), CAS on every transition, the documented fallback (phone / in_person / reschedule — never a silent drop), and duplicate-schedule 409 via partial unique
- Requests: `Telehealth/ScheduleTeleconsultRequest`, `StartTeleconsultRequest`, `OpenVideoSessionRequest`, `FailVideoSessionRequest`, `RecordingRequest`
- Controller: `TelehealthController` (`authorize:telehealth:schedule` for schedule/cancel; `authorize:telehealth:conduct` for ready/start/video-sessions/end/fail/complete; `authorize:telehealth:record` — separate and restricted — for explicit recording start/stop)
- Test: `TelehealthTest`

**Files modified.** `routes/api.php` (telehealth group in the tenant group), `RolePermissionSeeder` (3 permissions: `telehealth:schedule` — org_admin/hospital_admin/receptionist; `telehealth:conduct` — org_admin/hospital_admin/doctor; `telehealth:record` — org_admin/hospital_admin only), `AuditLogger` (teleconsult + video_session resource types, `telehealth.*` actions), `ClaimsBasedRlsTest` (468 → 476 policies, matrix 118 → 120), `TenancyDatabaseInventoryTest` (+2 tables in the scoped set + chain seed + update probes; 113 → 120 counts), `DATABASE.md` (§3.55), this log.

**Migrations.** Two, applied to the disposable test DB (`swasthya_test`) and dev DB (`swasthya`). No real/staging Supabase touched.

**Tests.**
- New `TelehealthTest` — **18 tests / 114 assertions**: schedule from a teleconsult appointment + duplicate 409; refuse a non-teleconsult appointment; schedule denied without `telehealth:schedule`; authentication required on the whole surface; the full happy path (schedule → ready → consent-gated start → video session → OPD-standard note/sign → complete → session end) with every audit event; the consent gate (no active telehealth consent → 403, no encounter created, no state change); consent scope must cover the medium (phone fallback needs phone consent); connectivity failure with a documented fallback (session failed + teleconsult failed + fallback mode, never silent); invalid fallback mode rejected; recording gated by default-disabled policy (doctor without `telehealth:record` denied outright; admin with the permission refused with `recordingAllowed: false`, consult unaffected); recording allowed only when policy + consent + permission all pass (idempotent stop — one audit event); recording consent required under `consent_required` policy; complete refused before the encounter is signed; cancel + double-cancel 409; invalid transitions (start before ready, complete before in_progress); **tenant + facility isolation** (cross-tenant read 404 / write 403 — the established convention; sibling-facility invisible); video-session metadata PHI-safe (audit payloads fact-only, storage refs are references); **CAS concurrency** (two simultaneous starts — exactly one winner, one encounter)
- ClaimsBasedRlsTest: telehealth RLS proof via matrix; 468 → 476 policies, matrix 118 → 120
- TenancyDatabaseInventoryTest: +2 tables in the scoped set + chain seed + update probes; 120-table two-sided cross-tenant sweep green

**Gate results (all green).**
| Gate | Result |
|---|---|
| TelehealthTest (new) | **18 passed / 114 assertions** |
| RLS suites (ClaimsBased + Inventory) | **34 passed / 2,366 assertions** (476 policies, 120-table sweep, FORCE intact, swasthya_app NOBYPASSRLS) |
| Full backend Pest | **717 passed / 9,625 assertions** (Slice-23 baseline 699/9,432 → **+18/+193**) |
| Node harness | **855 / 855** (unchanged — Laravel-only slice) |
| Frontend Vitest | **26 passed** + tsc clean |
| Harness TypeScript | PASS |
| Pint | PASS (716 files, no fixes needed this run) |
| `git diff --check` | CLEAN |
| Debug-marker / artifact sweep | CLEAN (probe files removed during debugging) |
| Tracked-secret scan | 0 matches in Slice-24 files |

**Remaining risks.** The video/relay integration is provider-agnostic by design (`provider_session_ref` / `recording_storage_ref` are adapter-boundary references) — a real WebRTC/SFU vendor is a later-phase deployment decision, never simulated as a live call; the reporting replica wiring remains a deployment-phase task.

---

## Phase 22 — National Scale (2026-08-17)

**Objective.** ROADMAP Phase 22 / milestone M5: measured capacity, resilience drills, localization, integration and compliance evidence — with the roadmap's hard rule that nothing is claimed without recorded evidence.

**Honesty anchors.** Production multi-region cutover, WAL-archiving PITR, production-scale SLOs, compliance, and live national integrations are **NOT PROVEN** and are explicitly recorded as such (`NATIONAL_SCALE.md` §7). No fake integration, no invented capacity, no compliance claim.

**Files created.**
- `frontend/src/i18n/I18nProvider.tsx`, `locales/en.ts` (34 keys), `locales/ne.ts` (Devanagari mirror, parity-enforced), `I18nProvider.test.tsx`, `localized-shell.test.tsx`
- `frontend/scripts/verify-devanagari.mjs` (static gate on shipped tokens.css — Vitest stubs CSS)
- `backend/ci/failover-drill.sh` (app switched to pre-verified standby; HTTP `health/live`+`health/ready` against it; RLS probes)
- `NATIONAL_SCALE.md` (evidence register), `LEGAL_COMPLIANCE_ASSESSMENT.md` (verified controls vs NOT PROVEN legal items), `docs/national-scale/*.log` (raw measured evidence)

**Files modified.** `frontend/src/main.tsx`, `App.tsx`, `layout/AppShell.tsx` (language toggle), `pages/LoginPage.tsx`, `styles/tokens.css` (`html[lang='ne']` Devanagari-first stacks), `layout/shell.css` (toggle styling), `backend/ci/load-benchmark.sh` + `backend/ci/backup-restore-drill.sh` (**measurement-integrity fix**: canonical `request.jwt.claims` wiring; drill isolation probe uses one patient's own tenant/facility), `DEPLOYMENT.md`, `DISASTER_RECOVERY.md` (§13.1 drill evidence register), `OBSERVABILITY.md`, `MASTER_RULES.md` (§39.1a), `INTEROPERABILITY.md`, this log.

**Measured evidence (all recorded).**
- **Load @ 1M patients (~2.9M rows, 1,235 MB):** point lookups 0.2–0.8 ms, provider-day schedule 0.27 ms, inserts ~0.3–0.5 ms, update ~3.3 ms, delete ~86 ms (WITH CHECK), tenant-scoped name search 147–158 ms (documented hot spot); error rate 0; `BENCH_EXIT=0`. RLS index-cond folding proven in explains (0 "never executed").
- **Restore drill @ national scale:** backup 34 s (152 MB dump), restore 104 s + role/grants fixup, total 140 s; 135 tables, 97 migrations, 1,000,000 patients / 500,000 appointments; 476 = 476 policies; RLS on; `swasthya_app bypass=false super=false`; isolation on restored data 1/0/0.
- **Failover-readiness drill:** app serves from standby — `health/ready` database check ok; switch-over ~1 s; RLS on standby 1/0/0.
- **Localization:** Vitest 32/32 (26 baseline + 6 new), `verify-devanagari.mjs` PASS, TS PASS; Devanagari `lang='ne'` stacks shipped.

**Gates (all green).** Full backend Pest **717 passed / 9,625 assertions** (no backend PHP surface changed — CI scripts only); RLS suites 34 passed / 2,366; Node harness 855/855; frontend Vitest 32 passed; frontend + harness TypeScript PASS; Pint PASS; `git diff --check` CLEAN; debug/artifact sweep CLEAN; tracked-secret scan 0 new matches.

**Remaining risks (deployment-phase, NOT PROVEN).** production-scale SLO verification; WAL/PITR production posture; real multi-region cutover (annual failover drill); legal/compliance assessment; live national integrations (none specified); page-content-level Nepali localization beyond the shell/login.

**Baseline note.** Phases 20 (RPM) and 21 (CDSS/AI) were requested but never implemented (no code, no commits) — Phase 22 proceeded per the authoritative instruction; the roadmap's "all prior phases" dependency is partially unmet and is flagged in the STOP report.
