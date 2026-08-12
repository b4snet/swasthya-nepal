# Swasthya Staging Deployment Report

> **Date:** 2026-08-12 · **Milestone:** Staging deployment & production engineering
> **Scope:** git foundation · CI/CD · local staging mirror · backup/restore drill ·
> observability, performance, accessibility verification. No new HMS business modules.

## 1. Executive Summary

Swasthya is now a **real deployable staging system**. The repository is under
Git with a secret-free baseline; a provisioned staging environment exists
(dedicated `swasthya_staging` PostgreSQL database, least-privilege
`swasthya_app_staging` role with `NOBYPASSRLS`, backend running
`APP_ENV=staging`); the CI pipeline gained a `frontend` job and the full
backend+frontend pipeline was executed end-to-end locally against a
disposable PostgreSQL; a real backup/restore drill was performed and
verified; and the staging E2E suite (desktop OPD workflow, mobile receptionist
flow, axe accessibility scans) is green.

Two accessibility defects were found and fixed (WCAG 1.4.1 link
distinguishability; sub-AA muted-text contrast). Two real staging bugs were
found and fixed (the fixture seeder lacked the formulary, stalling the
prescription step; the seeder linked the doctor staff profile to the wrong
login).

**What has NOT happened (stated honestly):** CI has not run on a real
GitHub-hosted runner (the repository has not been pushed); no cloud staging
host with TLS and a secrets store exists (provider not selected); RPO/RTO
have not been measured at production scale. The local drill is evidence of
the recovery procedure, not of production recovery targets.

## 2. Environment

| Item | Value |
|---|---|
| Backend | Laravel (PHP 8.x), `APP_ENV=staging`, `APP_DEBUG=false` |
| Database | PostgreSQL 16 (port 54329), database `swasthya_staging` |
| Runtime role | `swasthya_app_staging` — `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS`, never table owner |
| Backend URL | `http://127.0.0.1:58998` |
| Frontend | React + TypeScript + Vite SPA (dev server per environment) |
| Auth throttle (staging) | `SWASTHYA_RATE_LIMIT_AUTH=60` per IP (prod-like control; automated E2E uses 200 on the CI runner) |
| Tenants | `smoke-group` (A) and `apex-care` (B) — synthetic staging fixtures only |

Environment separation holds: the staging database (`swasthya_staging`) and
role (`swasthya_app_staging`) are distinct from the dev database (`swasthya`)
and role (`swasthya_app`); the staging connection values live in
`backend/.env.staging` (untracked) with the shape committed as
`backend/.env.staging.example`. Development credentials cannot connect to the
staging database.

## 3. Infrastructure

The current staging infrastructure is the **local mirror** of the STAGING.md
topology (single app instance + PostgreSQL). Component inventory:

- **PostgreSQL 16** — `swasthya_staging` DB; 50 tables, 47 migrations,
  **144 RLS policies across 37 RLS-enabled tables**; RLS enforced on every
  application query through the runtime role.
- **Laravel API** — one process on 58998, serving `/api/v1`; health probes
  `/health/live` and `/health/ready`.
- **Frontend** — Vite dev SPA (proxy `/api` to the backend) used for the
  staging E2E runs; the production build (`npm run build`, 219.59 kB JS /
  18.50 kB CSS gzip) is what CI produces as the deployable artifact.

No Redis, object storage, load balancer, or TLS termination exist yet — all
deferred to the provider-selected staging host (STAGING.md §2, §15).

## 4. CI/CD

`.github/workflows/ci.yml` now contains two jobs:

- **backend** (existing): PHP 8.2/8.3 matrix, Pint, disposable `postgres:16`,
  migrations, RLS verification, full Pest suite (241 tests / 1,748 assertions
  incl. RLS, tenant isolation, audit), build artifact.
- **frontend** (new): Node 20 + npm ci, typecheck, 20 unit/component tests,
  `npm run build`, then `playwright.ci.config.ts` against the disposable DB —
  the config starts the backend itself (as `swasthya_app`), runs the desktop
  OPD workflow, the mobile receptionist flow, and the axe accessibility scan.

No production secrets are committed; the app-role password comes from the
`APP_ROLE_PASSWORD` secret with a CI-only default for the disposable DB.

**Local twin execution (this machine):** `backend/ci/run-local-ci.sh` — full
backend pipeline green (241 passed, 1,748 assertions) on a disposable
`swasthya_ci` database; the CI Playwright config ran 4/4 green against the
same disposable DB (backend as `swasthya_app`). The twin proves the pipeline
stages are correct; a **real GitHub-hosted run remains outstanding** (push
the repository).

## 5. Frontend Deployment

The frontend is delivered as a static production build (Vite, `frontend/dist`)
that talks same-origin through `/api` (the deployment serves it behind the
same host/load balancer as the API). For the staging E2E runs the SPA runs
against the staging backend (`SWASTHYA_API_TARGET=http://127.0.0.1:58998`)
— verified: login, session restoration, tenant context, patient list,
appointment booking, queue, doctor workspace, billing, payment all exercised
against the staging backend/DB, never localhost-only UI state.

## 6. Backend Deployment

Staging backend verified live on 58998 with `APP_ENV=staging`:

- `GET /api/v1/health/live` → `{"status":"ok"}`
- `GET /api/v1/health/ready` → `{"status":"ok","checks":[{"name":"database","status":"ok"}]}`
- Both fixture tenants authenticate (`smoke.hadmin@two.test`,
  `smoke.hadmin@three.test`); RBAC, tenant context, RLS, and audit verified
  through the E2E and isolation probes.

## 7. Database

`swasthya_staging` PostgreSQL database:

- **Migrations:** 47 applied (fresh `migrate:fresh` + grants + fixture seed).
- **Schema:** 50 tables; tenant-safe composite foreign keys
  (`(tenant_id, id)` patterns) make cross-tenant references structurally
  impossible.
- **RLS:** enabled with per-table policies on all tenant-owned tables (37
  tables, 144 policies); policies are role-agnostic (no `TO` clause → apply
  to every role including `swasthya_app_staging`).
- **Audit:** `audit_events` restored/retained; 123 events present in the
  fixture staging DB after the E2E runs (append-only, hash-chained).
- **Indexes:** tenant/facility composite indexes in place from Tenancy V2;
  the RLS load test earlier established index usage on tenant-scoped paths.

## 8. Migrations

Deployment migration policy enforced: `migrate:fresh`/`DROP DATABASE` are CI
and drill-only on disposable databases — never used against staging. Staging
was provisioned through the documented bootstrap order: `roles.sql` →
`migrate --force` → `grants.sql` → `StagingFixtureSeeder`. A migration
failure stops the deploy (pipeline fails hard; the CI frontend job depends on
the backend job's success).

## 9. Secrets

No secrets are committed to Git (verified by staged-content pattern scan:
no `.env*` beyond the `.env.example` templates, no keys, no certificates).
`backend/.env.staging` is untracked; its committed twin `.env.staging.example`
documents every variable with placeholders. The staging app-role password and
`APP_KEY` live in the local `.env.staging` only. **Remaining:** a secrets
store and env-injection at the real staging host (STAGING.md §6, §15).

## 10. Authentication

Real authentication verified against the staging backend: login (argon2id
hash verification), rotating refresh tokens with reuse detection, session
restoration, RBAC-filtered UI, facility context derived from the
server-issued `assignments` payload (never from the browser). The staging
fixture uses the documented synthetic test credential `SmokePass-2026!` —
never a production credential.

## 11. Tenant Isolation

Staging has two synthetic tenants. Proven:

- **API level:** tenant A registered a patient; tenant B could not read or
  search it (tenant-scoped 404/empty semantics).
- **Database/RLS level:** as `swasthya_app_staging` on `swasthya_staging` —
  no context → 0 patient rows; tenant B context → 0; tenant A context →
  correct count (9). Cross-tenant SELECT/UPDATE/DELETE are engine-enforced,
  not application-only.
- The same probe passes on the **restored** database (§11 below).

## 12. RLS

PostgreSQL RLS is active on the staging database: 144 policies across 37
tenant-owned tables, all enforced through the non-owner
`swasthya_app_staging` role (`NOBYPASSRLS`, `NOSUPERUSER`). Every application
query runs with the transaction-local tenant/facility GUCs set by the
tenant-context middleware; the engine independently rejects any row outside
the current context. RLS is defense-in-depth — application authorization and
RBAC remain the primary gate (TENANCY.md V2).

## 13. OPD E2E

The complete OPD workflow runs green against the **staging** backend/DB/RLS
(`frontend/playwright.staging.config.ts`):

```
login → patient → appointment → check-in → queue → doctor → encounter →
clinical note → diagnosis → prescription → sign → invoice → payment → audit
```

- Desktop workflow spec: **passed (1.2 m)** — every step a real API call
  against `swasthya_staging`, every record persisted, audit events appended.
- The chain includes real availability (fixture Tuesday schedule), token
  issuance, signed clinical records, idempotent payment.

## 14. Mobile E2E

The mobile receptionist flow at the iPhone 13 viewport (390×844) runs green
against staging: **passed (14.9 s)** — no horizontal overflow, bottom
navigation usable, patient search, booking, check-in, queue all exercised on
mobile. (The doctor workspace at mobile viewport remains covered by the
desktop spec only — an open item from the frontend milestone.)

## 15. Backup

Real backup of `swasthya_staging` performed (2026-08-12):

| Metric | Value |
|---|---|
| Tool | `pg_dump -Fc` (custom format — schema, data, RLS policies, functions, triggers) |
| Start | 02:40:51 UTC |
| Complete | 02:40:52 UTC |
| Duration | ~1 s |
| Size | 304,440 bytes |
| Content verified | 50 table-data sections; 144 `POLICY` entries present; 0 `ROLE` entries (expected — roles are cluster-level, not carried by pg_dump) |

## 16. Restore

Restored into a disposable `swasthya_staging_restore` database:

| Metric | Value |
|---|---|
| Tool | `pg_restore --no-owner --no-privileges` |
| Duration | ~1 s |
| Exit code | 0 |

**Post-restore verification (all passed):** 50 tables; 47 migrations; 144
policies; 37 RLS-enabled tables; both tenants (smoke-group + apex-care); 6
users; 2 medications; 123 audit events intact. **RLS on restored data:** as
`swasthya_app_staging` — tenant A → 9 patients visible; tenant B → 0;
no context → 0. **Roles/grants fixup:** the app-role grants were re-applied
on the restored DB before probing (`database/security/grants.sql` pattern,
idempotent) — this is the documented post-restore step, since `pg_dump` does
not carry roles or grants. The disposable DB was dropped afterwards.

## 17. Observability

Verified on the staging backend logs:

- Every request line is structured JSON carrying `request_id` and
  `correlation_id`, plus where applicable `service`, `method`, `path`,
  `status`, `duration_ms`, `ip`, and — for authenticated requests —
  `tenant`, `facility`, `user`, `platform`.
- Auth failures, authorization denials, and RLS/permission exceptions are
  visible in logs.
- **Never-log check:** scanning the log for passwords, bearer tokens,
  access/refresh token strings and PHI values found no application leaks
  (the single regex hit was a stale development SQL error naming the
  `password` *column*, not a secret value).
- Health endpoints expose only status and check names — no credentials, no
  internals.

## 18. Monitoring

No production monitoring stack is deployed (no host). Defined for staging in
STAGING.md §10: 5xx-rate, error-rate, P95 latency, `/ready` success rate,
auth-lockout and RLS-denial spikes. The instrumentation hooks (structured
logs, health probes, request IDs) that these dashboards consume are verified
working on staging.

## 19. Security

Staging security review performed:

- **CORS** — same-origin SPA via the Vite proxy / same-host deploy; no
  permissive cross-origin policy observed in the API responses.
- **CSRF** — bearer-token API (no cookie-based session on the API);
  Sanctum token auth; CSRF surface limited to the session-bearing routes.
- **Headers** — security headers emitted by the API middleware (verified in
  earlier milestones: HSTS preload, CSP-adjacent defaults, nosniff).
- **Rate limiting** — auth throttled (60/min per IP on staging); API throttle
  active; E2E raises the auth limit via env on CI only.
- **Authorization / tenant isolation** — RBAC gates + server-side tenant
  context + PostgreSQL RLS (verified, §11–§12).
- **Secret exposure** — no secrets in Git; none in logs.
- **Error exposure** — `APP_DEBUG=false`; API errors return the envelope
  format with correlation IDs, no stack traces.

## 20. Performance

Micro-benchmarks on the local staging stack (dev machine, PHP dev server —
indicative, not production performance):

| Operation | Median latency (5 runs) |
|---|---|
| Login | ~0.93–1.11 s (includes argon2id verification) |
| Patient search (tenant-scoped) | ~0.60 s |
| Appointments list (tenant-scoped) | ~0.60 s |
| Queue (tenant-scoped) | ~0.57 s |
| `/auth/me` | ~0.39 s |

No error rate observed during the benchmarks. These numbers are for the local
mirror; production performance will be measured on the real staging host.
The earlier RLS load benchmark (STAGING_READINESS_REPORT §9) established that
RLS predicates use indexed tenant columns; no full-table-scan regression was
introduced by this milestone.

## 21. Accessibility

Added `@axe-core/playwright` and `frontend/e2e/accessibility.spec.ts` —
scans dashboard, patients, appointments, queue, billing (admin) and the
doctor queue workspace, asserting **zero serious/critical violations**. Two
real AA defects found and fixed:

1. **Muted text contrast:** `--slate-500 #64748b` on the app background was
   4.38:1 (< 4.5 AA). Darkened to `#627188` (4.96:1 on white, 4.56:1 on mist).
2. **Link distinguishability (WCAG 1.4.1):** inline links had no underline
   and 1.53:1 contrast against parent muted text. Global `a` now underlines
   by default; nav chrome (side/bottom nav, more-sheet) opts out.

Result: **both a11y specs pass (2/2)** against the staging stack.

## 22. Problems Found

1. **Staging E2E desktop stalled at the prescription step** — the fixture
   seeder provisioned no formulary; the medication `selectOption({ index: 1 })`
   could not resolve, and the button stayed disabled after a network-failed
   login during one load-test-induced run.
2. **Fixture doctor link** — the staging seeder linked the doctor staff
   profile to the first assignment (superadmin) instead of the doctor login;
   the doctor's clinical-note save failed on staging.
3. **Vite IPv4/IPv6 binding mismatch** — `localhost` resolved to `::1` while
   the API helper targeted `127.0.0.1`, producing `ECONNREFUSED` in the E2E
   helper; the staging config bound Vite explicitly to `127.0.0.1`.
4. **Accessibility:** muted-text contrast 4.38:1; inline links
   indistinguishable without color.
5. **`pg_restore` hang on first attempt** — the drop/create + restore were
   chained in one command and the DB creation raced; splitting steps and
   retrying restored cleanly (procedure, not a product bug).
6. **App-role password mismatch on the shared cluster** — `swasthya_app` is
   cluster-wide and pre-existed from dev, so `roles.sql` skipped it; the
   staging mirror uses a dedicated `swasthya_app_staging` role
   (`database/security/staging-role.sql`) to preserve environment separation.

## 23. Problems Fixed

- **Fixed:** seeder now provisions the formulary (`para-500` Paracetamol,
  3000 minor — the dev fixture's exact catalog entry) idempotently per
  tenant; re-seeding updated existing tenants.
- **Fixed:** seeder links `DOC-001` to `smoke.doctor@two.test` (keyed by
  employee code); verified in the staging DB and by the green E2E.
- **Fixed:** staging Playwright config binds Vite to `127.0.0.1`; the API
  helper base URL is env-driven (`SWASTHYA_E2E_BASE_URL`).
- **Fixed:** tokens.css muted token `#627188`; base.css link underline +
  nav opt-outs.
- **Fixed:** CI config passes PATH to the backend webServer and the E2E base
  URL explicitly to the test process.
- **Fixed:** E2E helper login is resilient to the shared-cluster role
  password by using the documented staging role.

Each fix is covered by the green E2E/a11y suites that exercise the exact path
that failed.

## 24. Remaining Risks

1. **CI never run on a real GitHub-hosted runner** — the pipeline is proven
   locally; a real runner run requires pushing the repository.
2. **No cloud staging host** — TLS, load balancer, secrets store, provider
   selection outstanding (STAGING.md §15–16).
3. **RPO/RTO unmeasured at production scale** — the drill measured local
   restore wall-time (~1 s for 300 KB); real targets depend on WAL archiving
   cadence and the actual host.
4. **Backup encryption not yet applied** — the local drill used plain
   pg_dump; production backups must be encrypted per DISASTER_RECOVERY.md.
5. **Doctor workspace at mobile viewport** — not E2E-covered (desktop spec
   only); tracked since the frontend milestone.
6. **MFA, secrets store, compliance assessment** — open from prior milestones.
7. **Staging app-role password** lives in the untracked local
   `.env.staging` — real staging must use the secrets store.

## 25. Production Blockers

- Real-runner CI execution (push repository to GitHub).
- A real staging host with TLS + secrets store + provider decision.
- Production-scale backup (encrypted, WAL-based) and restore measurement.
- MFA TOTP flow and the secrets-store migration for production credentials.

## 26. Final Verdict

> **CORRECTED 2026-08-12 by `STAGING_STATUS_REPORT.md`.** The original draft
> verdict below ("READY FOR STAGING") treated the local mirror as satisfying
> the staging requirement. Under the corrected classification rule — localhost
> is never staging, and staging is only VERIFIED when the application is
> deployed into the environment specified by STAGING.md (real host, domain,
> TLS, managed PostgreSQL, secrets store, real-runner CI) — the verdict is:
>
> **NOT READY FOR STAGING**
>
> The engineering evidence in §1–§25 stands and is real; it proves the
> *procedures* work locally, not that a staging deployment exists. The exact
> missing requirements and blockers are enumerated in
> `STAGING_STATUS_REPORT.md` §23–§24.

_Draft verdict retained for the record:_ **READY FOR STAGING** — the local
staging mirror is provisioned and verified, the full CI pipeline executes
green as a local twin, the backup/restore procedure is proven on real data,
and the entire OPD workflow plus mobile and accessibility suites pass against
the staging stack. READY FOR PRODUCTION REVIEW was not claimed: CI has not
run on a real runner, no TLS/secrets-equipped staging host exists, and
RPO/RTO have not been measured at scale.
