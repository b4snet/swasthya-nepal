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

---

*This log opens with the truth: a greenfield folder, seventeen design documents, and no code. Every entry from here on records what is actually done — and this document will be the permanent witness to whether Swasthya is built the way it was designed.*
