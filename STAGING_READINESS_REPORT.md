# Swasthya Staging Readiness Report

> **Scope:** staging-readiness validation and deployment foundation only. No
> new HMS business module was built. Tenancy V2 (Branch, RLS, database roles,
> platform-access control) was re-verified as the base line.
>
> **Evidence baseline:** 241 tests / 1,742 assertions green against real
> PostgreSQL (and again on a disposable CI database); the complete OPD
> workflow walked live over HTTP as the least-privilege `swasthya_app` role
> under active RLS; RLS load-tested to 1M patients; a real backup/restore
> drill executed and verified; health checks and observability verified.

---

## 1. Executive Summary

The Tenancy V2 verdict (READY FOR STAGING) was tested rather than assumed.
This validation pass produced:

1. **The complete OPD workflow re-ran live over real HTTP** as `swasthya_app`
   (RLS active, non-owner role, no bypass): patient → appointment →
   check-in (token) → queue → encounter → clinical note → diagnosis →
   prescription → sign → invoice → payment → audit. Every step produced a
   real database record, real authorization, and an audit event; DB records
   were verified per step as the app role under tenant GUCs. Idempotent
   payment replay was exercised live (`payment.replayed` audit event, no
   duplicate row).
2. **A CI/CD pipeline was written** (`.github/workflows/ci.yml`, PHP
   8.2/8.3, disposable `postgres:16` service) **and its local twin was
   executed end-to-end** (`backend/ci/run-local-ci.sh`) against a
   disposable `swasthya_ci` database: source check → Pint → roles.sql
   (NOBYPASSRLS) → `migrate:fresh` → RLS policy/role verification → full
   suite green → cleanup.
3. **RLS was load-tested on a synthetic 1M-patient workload** (≈2.9M rows,
   DB 1.2 GB, 20 orgs × 2 facilities). Point lookups stay sub-millisecond
   to ~4 ms under RLS (policies fold into index conditions). The one hot
   spot — patient-name ILIKE search at ~57 ms — was root-caused (the
   facility OR-NULL policy predicate prevents a facility-prefixed trgm
   index), tested, and **deliberately not over-optimized**; it is
   documented as a future refinement.
4. **A real backup/restore drill passed and exposed a genuine recovery
   gap**: pg_dump does not preserve roles or `ALTER DEFAULT PRIVILEGES`,
   so a restored database left the runtime role with no access despite
   intact RLS policies. Fixed with an idempotent post-restore grants
   fixup (`database/security/grants.sql`) now part of the drill. Restored
   DB verified: schema, migrations, data, 144 policies, role flags, the
   full OPD chain, and cross-tenant isolation (with context → 1, without →
   0, wrong tenant → 0).
5. **Health checks and observability verified live**: `/health/live` and
   `/health/ready` (real DB check); structured JSON logs carry
   `request_id` + `correlation_id`; auth lockouts and authorization
   denials are logged; the never-log rule holds in application behavior.
6. **Staging does not exist** and was not invented: a concrete build
   specification (`STAGING.md`) now defines services, env vars, DB role
   bootstrap, storage, secrets, networking, TLS, health checks, monitoring,
   deployment steps, and an acceptance checklist.

**Verdict: READY FOR STAGING** — with the explicit caveat that the staging
environment itself must now be built from `STAGING.md` and its acceptance
checklist executed; CI must run once on a real runner (requires `git init`);
and the frontend remains designed-not-built, so viewport/mobile verification
is still impossible. Those are build/process prerequisites, not artifact
defects. See §24.

---

## 2. Tenancy V2 Status

Re-verified as implemented and engine-enforced (unchanged from
`SWASTHYA_TENANCY_V2_REPORT.md`, verdict READY FOR STAGING):

- Branch hierarchy (org → facility → branch → catalogs) with documented
  ownership decisions.
- PostgreSQL RLS on every tenant-owned table with per-operation policies;
  transaction-local GUCs (`app.tenant_id/facility_id/branch_id/user_id/
  is_platform`) set only by the tenant-context middleware; hard reset after
  each request.
- `swasthya_app` runtime role: `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
  NOINHERIT NOBYPASSRLS`; migration/owner role separate.
- Platform administration confined to `platform/*`; tenant data access only
  via explicit, time-limited, reason-required, fully audited support
  sessions; no bypass-everything path.
- The two Tenancy V2 defects (login-under-RLS assignments; middleware-order
  binding) remain fixed with regression tests.

Re-verified live this pass: login payload (assignments + facility name)
under RLS; cross-tenant/cross-facility probes denied; concurrent-tenant
isolation at the database level (two connections, tenants 7 and 11, each
saw exactly its own rows).

---

## 3. Live OPD Workflow

Executed against the running API connected as `swasthya_app` (real RLS),
real PostgreSQL, real auth (access + refresh tokens), real authorization,
real audit. Evidence: `backend/smoke_staging.log` (each step's HTTP status
and DB verification) and the audit trail.

| Step | API | Result | DB verification (as swasthya_app) |
|---|---|---|---|
| 0. Health | `GET /health/live`, `/health/ready` | 200 / 200 (db=ok) | — |
| 1. Login (admin) | `POST /auth/login` | 200, assignments + facility resolved | user row active |
| 2. Register patient | `POST /organizations/{org}/patients` | 201, MRN issued | patient row (tenant/facility set) |
| 3. Login (doctor) | `POST /auth/login` | 200 | doctor assignment active |
| 4. Availability | `GET /staff/{staff}/availability` | 200, derived slots | schedule template Tue 09:00–11:00 |
| 5. Book | `POST /appointments` | 201 | appointment row, status booked |
| 6. Check-in | `POST /appointments/{id}/check-in` | 200, token issued | token_no + checked_in_by set |
| 7. Queue | `GET /appointments/queue` | 200, token visible | — |
| 8. Start encounter | `POST /appointments/{id}/start-encounter` | 201 | encounter row, status open |
| 9. Clinical note | `POST /encounters/{id}/notes` | 201 | note row, draft |
| 10. Diagnosis | `POST /encounters/{id}/diagnoses` | 201 | diagnosis row (J11.1, primary) |
| 11. Prescription | `POST /encounters/{id}/prescriptions` | 201 | prescription + line rows |
| 12. Sign note + encounter | `POST .../notes/{note}/sign`, `POST .../sign` | 200/200 | signed_at set; status signed |
| 13. Invoice | `POST /encounters/{id}/invoice` | 201, total 32000 (5000 + 3000×9) | invoice issued, 2 lines |
| 14. Payment (idempotent) | `POST /invoices/{id}/pay` ×2 same key | captured; replay → `payment.replayed` | 1 payment row; invoice paid 32000 |
| 15. Audit trail | `GET /audit-events` | 200 | every step present with actor + facility |
| 16. Timeline | `GET /patients/{id}/timeline` | 200 | events present |
| 17. Isolation probes | no token; foreign facility | 401 INVALID_TOKEN; 422 VALIDATION_ERROR | — |

All DB verification queries ran as `swasthya_app` with tenant GUCs — the
records were visible *through* RLS, and the isolation probes confirmed the
same role cannot see anything outside its context.

---

## 4. Desktop Verification

**BLOCKED — no frontend exists.** The repository contains no SPA: the only
frontend artifact is the static documentation dashboard (`docs/`), which is
not the application. Per `ARCHITECTURE.md` / `README.md` the React SPA is
designed-but-not-built. The workflow was verified through the real API and
real database (the interface a frontend will consume); desktop-browser
interaction cannot be verified until the SPA ships. Recorded, not glossed.

---

## 5. Mobile Verification

**BLOCKED — no frontend exists.** Same reason as §4; mobile viewport
verification is impossible until the mobile-first SPA is built. The API
contracts it will consume (mobile-first design system, `DESIGN_SYSTEM.md`)
are verified and load-tested; interaction-level mobile behavior is a
frontend milestone, not a backend one.

---

## 6. CI/CD Status

- **Provider:** none exists in the repository (no `.github/`, no
  `.gitlab-ci.yml`; the repository is not yet a git repository). GitHub
  Actions was chosen as the default provider and the workflow written; it
  has **not yet run on a real runner** (requires `git init` + a remote).
- **Written:** `.github/workflows/ci.yml` — PHP 8.2/8.3 matrix, disposable
  `postgres:16` service container, composer install, Pint, `roles.sql`
  (NOBYPASSRLS), `migrate:fresh` on the disposable DB, RLS policy + role
  verification, full Pest suite (RLS suite connects as `swasthya_app`),
  build artifact, failure log upload. No production secrets in the repo;
  the app-role password comes from a repo secret with a CI-only fallback.
- **Executed locally:** `backend/ci/run-local-ci.sh` — the same pipeline
  against a disposable `swasthya_ci` database — **PASSED end-to-end**
  (source check → Pint → roles → migrations → RLS verification → 241
  tests/1,742 assertions → disposable DB dropped). This proves the
  pipeline steps work; only the runner execution remains.
- **Failure policy:** pipeline fails on lint, migration, RLS, or test
  failures; no tests are disabled or weakened to go green.

---

## 7. Automated Test Results

| Suite class | Coverage | Result |
|---|---|---|
| Unit | validation, envelope, services, slot logic | PASS |
| Feature (integration/API/DB) | auth, RBAC, org/facility/branch isolation, RLS (database-level as `swasthya_app`), support access, audit, concurrency, workflow success + failure paths, migration-on-test-DB | PASS |
| **Total** | **241 tests / 1,742 assertions** | **GREEN** (96.3 s against real PostgreSQL; 104.9 s on disposable CI DB) |
| Lint | Pint | PASS — 293 files |

No tests skipped; no assertions weakened; the full suite was run twice this
pass (regression run + disposable-CI run).

## 8. Database Migration Tests

Executed on a **disposable** PostgreSQL (never a developer's local DB):

1. `createdb swasthya_ci` → `roles.sql` (creates `swasthya_app`,
   NOBYPASSRLS, idempotent) → `php artisan migrate:fresh --force` — all 47
   migrations applied cleanly on the empty database.
2. RLS verification after migrate: policy count > 0; policies present on
   patients/appointments/encounters/invoices; `swasthya_app` has
   `rolbypassrls=false`, `rolsuper=false`.
3. The Feature suite runs `RefreshDatabase` (migrate-on-test) against the
   disposable DB and passed — the migration path itself is the test
   (`TESTING_STRATEGY.md` §3.15).
4. The RLS suite connects to the disposable DB as `swasthya_app` and passed
   — proving migrations + policies + grants + role compose correctly from
   scratch.

## 9. RLS Load Test

**Environment:** synthetic, disposable `swasthya_load` database. No
production or patient-derived data. 20 orgs × 2 facilities; per row n:
tenant = n%20, facility = n%40; 1 department/doctor/service/medication per
facility. Dataset (at size 1,000,000):

| Table | Rows |
|---|---|
| patients | 1,000,000 |
| appointments | 500,000 |
| encounters | 250,000 |
| diagnoses | 125,000 |
| prescriptions / lines | 100,000 / 200,000 |
| charges | 250,000 |
| invoices / lines | 125,000 / 125,000 |
| payments / allocations | 125,000 / 125,000 |
| **Total rows** | **≈2.9M** |
| Database size | **1,208 MB** |

**Method:** `backend/ci/load-benchmark.sh` runs 20 benchmark statements
under RLS (`swasthya_app` + GUCs) and a controlled baseline (owner, RLS
disabled; re-enabled immediately after — the real application databases are
never touched). Each query runs warm.

**Results (1M patients; RLS vs baseline, ms):**

| Query | RLS | Baseline | Delta |
|---|---|---|---|
| Patient by id | 0.21 | 4.25 | −4.04 (index cond) |
| Patient by id (warm) | 0.13 | 0.18 | ~0 |
| Name search ILIKE | 57.0 | 0.12 | **+56.9** |
| Name search (warm) | 58.3 | 10.7 | +47.6 |
| Appointment by id | 3.8 | 8.9 | −5.1 |
| Encounters for patient | 0.20 | 3.5 | −3.3 |
| Invoices for patient | 5.8 | 0.14 | +5.7 |
| Provider-day schedule | 0.65 | 39.0 | −38.4 |
| Invoice + lines join | 14.5 | 6.3 | +8.2 |
| Insert patient ×3 | 0.6–2.7 | 1.3–4.8 | ~0 |
| Update patient | 26.6 | 0.76 | +25.9 |
| Delete ×3 | 0.79 | 20.1 | −19.3 |

**Latency/throughput:** point lookups 0.1–4 ms → ≈5,000+ qps per connection;
inserts 0.6–2.7 ms → ≈400–1,500/s per connection. **Error rate: 0** in both
modes. **Concurrency:** two simultaneous connections (tenant 7, tenant 11)
each returned exactly its own 25,000 patients — no leakage.

**Slow queries / indexes / RLS observations:**

- RLS tenant predicates fold into index conditions (the GUC comparison
  becomes a one-time filter and the tenant column is part of the index
  conds): point lookups are effectively free under RLS.
- **Hot spot — name search (~57 ms at 1M):** the facility-scope policy
  predicate `(facility_id = GUC OR GUC IS NULL)` cannot be pushed into a
  facility-prefixed index; the planner scans the tenant's 50k rows
  (parallel bitmap) and filters. Composite GIN indexes (tenant-prefixed,
  facility-prefixed, with `btree_gin`) were tested — the OR-NULL clause
  defeats all of them, so **no index was added** (a blind index would not
  help). Documented future refinement: facility-required contexts (drop
  the OR-NULL) would enable a facility-prefixed trgm index.
- Update ~27 ms under RLS reflects the WITH CHECK clause re-evaluating the
  policy on the target row — correct, bounded, acceptable.
- Baseline numbers are noisy (cold cache, autovacuum); the RLS-vs-baseline
  deltas on the hot path are the signal, and the only material one is the
  name search.

## 10. Backup Results

Real `pg_dump -Fc` of the development database (`ci/backup-restore-drill.sh`):

| Metric | Value |
|---|---|
| Backup start | 19:37:06 |
| Backup completion | 19:37:07 |
| Backup duration | **1 s** |
| Backup size | **292,744 bytes** |
| Format | custom (`-Fc`), plus a schema-only SQL copy for inspection |

## 11. Restore Results

Restore into a clean `swasthya_restore` database (`createdb` → restore →
roles + grants fixup):

| Metric | Value |
|---|---|
| Restore start | 19:37:08 |
| Restore completion | 19:37:09 |
| Restore duration | **1 s** |
| Total drill | **2 s** |

**Verified after restore (all passed):**
- Schema: 50 base tables; migrations table: 47 rows.
- Data: patients, appointments, invoices, payments, diagnoses,
  prescriptions, notes, charges, 144 audit events.
- **The full OPD chain restored intact:** `completed → signed → paid →
  captured` across appointments/encounters/invoices/payments.
- RLS: 144 policies (source 144 = restored 144); `relrowsecurity=true` on
  patients and audit_events.
- Role: `swasthya_app bypass=false super=false`.
- **Isolation re-verified on the restored DB** as `swasthya_app`: with
  tenant context → 1 row visible; without context → 0; wrong tenant → 0.

**Gap found and fixed by this drill:** pg_dump does not preserve cluster
roles or `ALTER DEFAULT PRIVILEGES`, so a restored database left the
runtime role with no access despite intact policies. Fixed with
`database/security/grants.sql` (idempotent re-application of the
migration's grants, incl. default privileges for future tables); the drill
now runs `roles.sql` + `grants.sql` after restore and verifies access.

## 12. RPO

**Dev environment (as configured): on-demand backups; no WAL archiving —
PITR is not available in dev.** RPO is therefore "since the last on-demand
backup" (manual). This is stated, not claimed: managed PostgreSQL with
continuous WAL archiving (the `DEPLOYMENT.md`/`DISASTER_RECOVERY.md` design)
is required for a bounded RPO; it is a staging/production deployment item,
not present in dev.

## 13. RTO

**Measured in this environment: ~1 s restore + role/grants re-creation
(seconds total).** This is the dev-database restore path only. A production
RTO target cannot be claimed until the production/staging database,
infrastructure, and failover procedure exist and are drilled.

## 14. Database Role Verification

After the restore drill, re-verified on the restored database:

- `swasthya_app` exists with `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
  NOINHERIT NOBYPASSRLS` (queried: `bypass=false super=false`).
- Non-owner: tables owned by the migration role; the runtime role has DML
  grants only (no ownership, no DDL).
- Cannot bypass tenant policies: the no-context read returned 0 rows; the
  wrong-tenant read returned 0 rows; the in-context read returned exactly
  its own row — all through the real engine as `swasthya_app`.
- The cross-tenant tests (SELECT/UPDATE/DELETE denial) are part of the RLS
  suite (`DatabaseRowLevelSecurityTest`) and re-ran green after all of this
  work.

## 15. Security Regression

Full suite re-run after all validation work: **241 tests / 1,742
assertions green**, Pint clean. Targeted suites re-run (all green):

- authentication (login, refresh rotation, expiry, lockout, throttling)
- RBAC (roles/permissions/grants, wrong-role gates at every clinical and
  financial step)
- organization / facility / branch isolation
- RLS (database-level SELECT/INSERT/UPDATE/DELETE denial, escape via
  UPDATE WITH CHECK, no-context safe failure, audit append-only, support
  sessions, middleware ordering)
- privileged support access (session lifecycle, reason, expiration)
- audit (append-only, actor + facility, hash chain)
- concurrency (double-booking race, token uniqueness)
- workflow failure paths (invalid status transitions, cross-tenant
  resources, malformed requests, expired sessions)

Test count did not decrease; no tests skipped.

## 16. Observability

Verified against the live application:

- **Structured logs:** JSON lines with `request_id`, `correlation_id`,
  `service`, `method`, `path`, `status`, `duration_ms`.
- **Failures visible:** 1,634 authorization-denial entries and 70
  lockout entries logged (from the test/probe volume) — auth and
  authorization failures are visible with correlation IDs.
- **Never-log rule:** application behavior logs no passwords, tokens,
  refresh tokens, MRNs, or PHI. One dev-only artifact was found (a tinker
  SQL error during earlier debugging logged a bound value in the exception
  message) — flagged as a finding: production should keep DB exception
  binding logging off (Laravel default redaction settings verified, but the
  exception message path was exercised only by a dev tool invocation).

## 17. Health Checks

Verified live (both endpoints intentionally unauthenticated and
unrate-limited, per `MASTER_RULES.md` §20.2):

- `GET /api/v1/health/live` — 200 `{status: ok, time}` — liveness only,
  no downstream dependency (an LB must not drain a healthy instance).
- `GET /api/v1/health/ready` — 200 `{checks: [{name: database, status:
  ok}]}` — readiness with a real DB connectivity check; returns 503
  `SERVICE_UNAVAILABLE` listing failing checks when not ready.

No sensitive information is exposed through either endpoint.

## 18. Staging Environment

**Does not exist** (verified: no `.env.staging`, Dockerfile, compose, or
IaC in the repository). Per the task, no cloud infrastructure was invented.
A concrete build specification was produced: `STAGING.md` — services
(PostgreSQL 16 + the Laravel API image; Redis/object storage optional for
staging v1), environment variables (incl. `APP_DEBUG=false`), database
requirements (two-role split, bootstrap order roles → migrate → grants,
post-bootstrap verification), storage (private bucket, encrypted), secrets
(store-injected, never in the repo), networking (app behind TLS LB, DB
private), TLS (public CA, ≥1.2, HSTS), health checks (live/ready usage),
logging/monitoring (request/correlation IDs, alert thresholds), deployment
steps (expand → migrate → contract; post-deploy OPD smoke; nightly suite +
load + drill), and a §12 acceptance checklist. `DEPLOYMENT.md` §4 remains
the design reference.

## 19. Deployment Requirements

(Specified in `STAGING.md`; summarized here.)

- Services: PostgreSQL 16 (managed, WAL-archived), one Laravel API image
  from CI, optional Redis/object storage.
- Environment: `APP_ENV=staging`, `APP_DEBUG=false`, prod-like rate limits,
  `DB_SSLMODE=require`.
- Database bootstrap on a fresh staging DB: `createdb` → `roles.sql` →
  `migrate --force` → `grants.sql` → verification (migrations = HEAD,
  policies present, `NOBYPASSRLS`).
- Secrets: env-injected from the CI secrets store; never in source control;
  no debug output.
- Networking/TLS: HTTPS-only LB, private DB network, allowlisted egress,
  valid public TLS with HSTS.
- Health: LB uses `/api/v1/health/ready` (200) for rotation.
- Deployment: expand → migrate → contract; post-deploy OPD smoke against
  staging; nightly full suite + RLS suite + load benchmark + backup/restore
  drill.
- Build-once-promote-same-artifact; no rebuild on deploy
  (`DEPLOYMENT.md` §0–§4).

## 20. Problems Found

1. **Restore left the runtime role with no access** (backup/restore drill):
   pg_dump does not preserve roles or `ALTER DEFAULT PRIVILEGES`; with
   `--no-privileges` the restored DB had intact RLS policies but zero
   grants for `swasthya_app` — a silent recovery gap.
2. **Patient-name search is the RLS hot spot** (~57 ms at 1M patients):
   the facility OR-NULL policy predicate prevents any facility-prefixed
   index from being used (tested tenant/facility composite GIN variants).
   Not fixed (a blind index does not help); documented.
3. **One dev-log artifact:** a tinker SQL error during earlier debugging
   logged a bound value (a hash) in an exception message. Not application
   behavior; flagged for production (keep DB binding logging off).
4. **CI has never run on a real runner** and no git repository exists —
   the workflow is written and its steps proven locally, but runner
   execution is pending `git init`.

## 21. Problems Fixed

1. **Post-restore grants fixup:** added `database/security/grants.sql`
   (idempotent; mirrors the RLS migration's grants incl. `ALTER DEFAULT
   PRIVILEGES`) and made the drill run `roles.sql` + `grants.sql` after
   restore with explicit verification. The drill now proves the restored DB
   is fully usable by `swasthya_app` (in-context read works, isolation
   holds).
2. During the live re-run, three smoke-script defects were fixed (not
   application bugs): response-shape extraction paths (flat `data.id` vs
   `data.patient.id`), the availability array shape (`data.0.startsAt`),
   and the rate-limiter interplay (lockout/throttle were flushed once; the
   controls themselves behaved as designed).

## 22. Remaining Risks

1. No git repository / no CI runner: the CI workflow is unproven on a real
   runner; the first green run is a prerequisite for promotion discipline.
2. No staging environment: `STAGING.md` is a spec; the acceptance checklist
   has not been executed anywhere.
3. Frontend absent: desktop/mobile interaction, mobile-first UX, and
   viewport behavior cannot be verified; the workflow is API- and DB-verified.
4. Patient search latency at multi-million-row volumes (~57 ms at 1M)
   without the documented facility-context refinement.
5. RPO is unbounded in dev (no WAL archiving); production RPO/RTO targets
   are unclaimed until a managed database and drills exist.
6. MFA TOTP, secrets store, breach-list checking, compliance assessment
   remain designed-not-implemented (open items, not this milestone).

## 23. Future Work

(Recorded only — no new business features were built, per scope.)

- **Deployment foundation:** `git init` + first commit + ADR-001
  ratification; wire the CI runner; build the staging environment from
  `STAGING.md` and execute its acceptance checklist; managed PostgreSQL
  with WAL archiving and a production RTO drill.
- **Security backlog (SECURITY.md):** MFA TOTP, secrets store, breach-list
  checking, DAST in CI, load-test-based performance budgets.
- **Frontend:** the React SPA (Phase per ROADMAP) — only then can
  desktop/mobile viewport verification, a11y, and responsive tests run.
- **Performance refinement (documented, not urgent):** facility-required
  context would enable a facility-prefixed trgm index for patient search.
- **Observability:** ensure DB exception binding logging is disabled in
  production channels; alert thresholds from the load-test baselines.

## 24. Final Verdict

**READY FOR STAGING.**

Justification:

- [x] Tenancy V2 re-verified: RLS engine-enforced, non-owner `swasthya_app`
  role, server-controlled tenant context, platform-access confinement
- [x] Complete OPD workflow re-walked live over real HTTP under RLS with
  per-step DB and audit verification; idempotent payment proven live
- [x] Full suite green twice: 241 tests / 1,742 assertions (real PostgreSQL
  and disposable CI database); Pint clean; no tests skipped or weakened
- [x] Migrations proven on a disposable database (fresh create → roles →
  migrate → RLS verification → full suite incl. RLS as the app role)
- [x] RLS load-tested to 1M patients with the hot spot root-caused and
  documented; concurrency isolation proven
- [x] Backup/restore drill executed, a real recovery gap found and fixed
  (grants fixup), restored DB fully verified
- [x] Database role configuration verified after restore (NOBYPASSRLS,
  least privilege, isolation re-tested)
- [x] Health checks (live/ready) and observability (request/correlation
  IDs, failure logging, never-log rule) verified
- [x] Staging specification produced (`STAGING.md`) with an acceptance
  checklist — no environment was invented or claimed

**Not yet READY FOR PRODUCTION REVIEW** because: the staging environment
does not exist (the checklist in `STAGING.md` has not been executed); CI has
not run on a real runner (no git repository); the frontend SPA is absent;
MFA and the compliance assessment remain open. These are build and process
next steps per `DEPLOYMENT.md` / `ROADMAP.md`, recorded here rather than
silent.

---

*This report documents only work actually performed in this milestone: live
re-verification, CI/CD authoring plus a locally executed pipeline, RLS load
testing, a real backup/restore drill with its fix, observability/health
verification, and a staging build specification. No new HMS business module
was implemented.*
