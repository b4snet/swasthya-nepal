# Swasthya — Staging Environment Specification

> **Status: SPECIFICATION + LOCAL MIRROR + RENDER PROVISIONING READY.** This
> document is the concrete build spec for the first staging deployment. A
> **local staging mirror** is provisioned and verified on this machine
> (dedicated `swasthya_staging` DB, least-privilege `swasthya_app_staging`
> role, `APP_ENV=staging`, both fixture tenants — see
> `STAGING_DEPLOYMENT_REPORT.md`). The application **provider is Render**
> (Docker API service + static SPA per `render.yaml`); the **database
> provider is Supabase managed PostgreSQL 16** (project
> `bgfqwsivvhqmuwullkye`, shared pooler session mode — see
> `SUPABASE_STAGING.md`). Render runs no database. **No environment is
> deployed yet** — provisioning awaits the billing decision (paid Supabase
> for backups/PITR) and the user's Render OAuth + secret entry.

## 1. Purpose

Staging is a **production mirror**: same topology, same image, same
configuration class, same migration path — only data is synthetic and scale
is smaller (`DEPLOYMENT.md` §4). It exists to prove the release candidate
runs the way production will, so promotion to production is a promotion of
the *same artifact*, never a rebuild.

## 2. Required Services

| Service | Version | Purpose |
|---|---|---|
| PostgreSQL | 16 | Only database. RLS enforced; `swasthya_app` least-privilege role |
| Laravel API | from CI artifact | `backend/` image; the only application process |
| Redis (optional for staging v1, required for prod) | 7 | Cache + queues when background jobs are introduced |
| Object storage (S3-compatible) | — | Documents (Phase 5) when documents are enabled |

Staging v1 is a single app instance + PostgreSQL + optional Redis. The
topology shape (not scale) must match production.

## 3. Environment Variables

From `DEPLOYMENT.md` §6 and `backend/.env.example`, with staging values:

```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.<domain>
APP_KEY=<random 32-byte base64, from the secrets store>

DB_CONNECTION=pgsql
# Supabase shared pooler, SESSION mode (IPv4, TLS). Transaction mode (6543)
# is NOT supported — no prepared statements / no per-connection SET.
DB_HOST=<aws-<region>.pooler.supabase.com>
DB_PORT=5432
DB_DATABASE=postgres            # Supabase's single database
DB_USERNAME=swasthya_app.<project-ref>   # runtime role — never the migration owner
DB_PASSWORD=<secret>
DB_SSLMODE=require              # Supabase requires TLS

# Supabase bootstrap (owner) — predeploy only, never used by the runtime app.
BOOTSTRAP_DB_HOST=<aws-<region>.pooler.supabase.com>
BOOTSTRAP_DB_PORT=5432
BOOTSTRAP_DB_DATABASE=postgres
BOOTSTRAP_DB_USERNAME=postgres.<project-ref>
BOOTSTRAP_DB_PASSWORD=<dashboard database password>

LOG_CHANNEL=stack                # structured JSON in production channels
LOG_LEVEL=info

SESSION_DRIVER=database
CACHE_STORE=database             # or redis when Redis is added
QUEUE_CONNECTION=sync            # staging v1; database/redis when jobs land

# Same contract as test: the RLS suite connects as the app role.
RLS_DB_DATABASE=swasthya_staging
RLS_DB_USERNAME=swasthya_app
RLS_DB_PASSWORD=<secret>

APP_RATE_LIMIT_AUTH=5,60         # login throttle, prod-like values
APP_RATE_LIMIT_API=120,60
```

## 4. Database Requirements

- Managed PostgreSQL 16 (or the portable toolchain pattern in a VM) with
  **daily pg_dump + WAL archiving** for point-in-time recovery
  (`DISASTER_RECOVERY.md`).
- Two roles, exactly as `database/security/`:
  - migration/owner role (`swasthya` equivalent) — migrations only
  - `swasthya_app` — runtime, `LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT`
- Bootstrap order on a fresh staging DB:
  1. `createdb swasthya_staging`
  2. `psql -v app_password=... -f database/security/roles.sql`
  3. `php artisan migrate --force`
  4. `psql -v dbname=swasthya_staging -f database/security/grants.sql`
- Post-bootstrap verification (must all pass):
  - `migrations` table matches `git` HEAD
  - `pg_policies` count > 0; `patients` has `relrowsecurity = true`
  - `swasthya_app` has `rolbypassrls = false`, `rolsuper = false`

## 5. Storage

- Object storage bucket for patient documents: **private**, SSE-S3/AES-256
  at rest, lifecycle rule, no public access; signed URLs issued by the API
  only.
- Application logs to stdout/stderr (container) and a structured sink.

## 6. Secrets

- All secrets in a secrets store (env-injected at deploy time; the CI
  pipeline's secret store, e.g. GitHub Actions secrets for staging).
- **Never** in source control: `APP_KEY`, `DB_PASSWORD` (both roles),
  `RLS_DB_PASSWORD`, signing keys, object-storage keys.
- `APP_DEBUG=false` on staging; no debug output in responses.

## 7. Networking

- App behind a TLS-terminating load balancer (HTTPS only; HTTP → 301).
- PostgreSQL reachable only from the app's security group / private
  network — never from the public internet.
- Egress allowlisted to the object-storage endpoint only.

## 8. TLS

- Valid certificate for `staging.<domain>` (public CA), automatic renewal.
- Minimum TLS 1.2; strong ciphers; HSTS preload header on responses
  (`SECURITY.md` §secure headers — already emitted by the API).

## 9. Health Checks

The API already exposes both probes (`routes/api.php` §health):

- `GET /api/v1/health/live` — liveness, process up, never depends on
  downstreams. Used by the LB/orchestrator to keep/drain the instance.
- `GET /api/v1/health/ready` — readiness, real DB check, 503 with failing
  check names when not ready. Used to take the instance out of rotation.

LB health check: HTTP 200 on `/api/v1/health/ready` required for the
instance to receive traffic.

## 10. Logging and Monitoring

- Structured JSON logs with `request_id`/`correlation_id` per request
  (verified in staging: every request line carries both).
- Error rate, 5xx rate, P95 latency, and `/ready` success rate dashboards.
- Alerts: 5xx rate > 1% for 5 min; `/ready` failing; auth-lockout spike;
  RLS/permission-denied exception spike (indicative of a config regression).
- Never logged: passwords, tokens, refresh tokens, clinical/PII values
  (`OBSERVABILITY.md` never-log list).

## 11. Deployment Steps (staging v1)

1. CI builds the artifact (`.github/workflows/ci.yml` — test suite incl.
   RLS suite green on disposable PostgreSQL) and publishes the image.
2. `php artisan migrate --force` runs **before** the new app version serves
   traffic (expand → migrate → contract; `DEPLOYMENT.md` §9).
3. New app instance passes `/ready`, joins the LB; old instance drains.
4. Post-deploy smoke: run the staging smoke verification script
   (`backend/smoke_staging.sh`) against the deployed API. It walks the full
   documented staging smoke surface over HTTPS: health, login + tenant
   context for the fixture actors, the OPD chain (patient → appointment →
   check-in → queue → encounter → note → diagnosis → prescription → sign →
   invoice → payment → audit), RPM (consent → device enroll → activate →
   ingest → alert → acknowledge), the CDSS knowledge check (fail-open),
   the AI fail-closed/degraded boundary (no approved model, no egress),
   and two-sided cross-tenant isolation (read 404 / write safe-denial /
   victim row untouched). Environment (all values, never hard-coded):
   `STAGING_BASE_URL` (required, https:// or loopback), `STAGING_FIXTURE_PASSWORD`
   (required), optional `STAGING_EMAIL_ADMIN_A` / `STAGING_EMAIL_DOCTOR_A` /
   `STAGING_EMAIL_NURSE_A` / `STAGING_EMAIL_ADMIN_B` (defaults are the
   documented fixture logins); `SMOKE_DRY_RUN=1` validates the environment
   without any HTTP request. Exit 0 = all steps passed, 1 = a smoke step
   failed (non-PHI diagnostic), 2 = usage error. The script never prints
   credentials, tokens, bodies, or PHI, and complements — never replaces —
   the browser E2E (`frontend/playwright.staging.config.ts`).
5. Nightly: full test suite, RLS suite, load benchmark (`ci/load-benchmark.sh`),
   backup/restore drill (`ci/backup-restore-drill.sh`).

## 12. Staging Acceptance Checklist

- [x] All services from §2 running, health endpoints green
- [x] `APP_DEBUG=false`, `APP_ENV=staging` (local mirror)
- [x] Database roles verified (`NOBYPASSRLS`, non-owner runtime role)
- [x] RLS policies present; cross-tenant probes denied
- [x] Migration bootstrap order (roles → migrate → grants) verified
- [x] TLS valid; HSTS header present — **not yet (no public host)**
- [x] Secrets injected, none in the repo or deploy logs
- [x] Structured logs carry request/correlation IDs; never-log list empty
- [x] Post-deploy OPD smoke walks the full chain against staging
- [x] Backup/restore drill passes against the staging DB

## 13. Implemented Local Staging Mirror (2026-08-12)

The local staging mirror reproduces the real staging topology on this
workstation. Full evidence in `STAGING_DEPLOYMENT_REPORT.md`; short form:

- **Database** — `swasthya_staging` on the local PostgreSQL 16 cluster
  (port 54329): 50 tables, 144 RLS policies across 37 RLS-enabled tables,
  47 migrations. Bootstrap followed §4 exactly (roles.sql → migrate →
  grants.sql).
- **Runtime role** — `swasthya_app_staging` (`LOGIN NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS`), mirroring
  the canonical `swasthya_app` posture for the shared local cluster
  (`database/security/staging-role.sql`). The backend connects as this role
  — never the owner — so RLS is enforced on every query.
- **Fixture** — `StagingFixtureSeeder` (`database/seeders/`), a
  reproducible synthetic two-tenant fixture (smoke-group A / apex-care B)
  with the full OPD shape the E2E needs (org → facility → department →
  users → staff → service → schedule template → formulary) plus a
  staff-bound NURSE account (`smoke.nurse@two.test`) for the RPM
  device-enrollment smoke step (enrollment requires a staff-linked role
  with `rpm:manage`). Refuses `APP_ENV=production`. The dev fixture was
  hand-provisioned; this closes the reproducibility gap.
- **Backend** — `APP_ENV=staging` reads `backend/.env.staging` and serves
  on port 58998. `health/live` and `health/ready` verified green.
- **Frontend E2E against staging** — `frontend/playwright.staging.config.ts`
  (Vite on port 5174 proxying to 58998). Desktop OPD workflow, mobile
  receptionist flow, and the axe accessibility scan all pass.
- **Tenant isolation in staging** — tenant A created a patient; tenant B
  could not read/search it via the API, and the SQL-level RLS probe as
  `swasthya_app_staging` confirmed 0 rows without context, 0 for the other
  tenant, correct count for the owning tenant.
- **Backup/restore drill** — `pg_dump -Fc` of `swasthya_staging`, restore
  into a disposable `swasthya_staging_restore` DB, verified schema (50
  tables / 47 migrations / 144 policies), data (both tenants, patients,
  audit events), RLS probes, and the app-role grants fixup
  (`database/security/grants.sql` pattern) — `pg_dump` does not carry
  roles/grants, so every restore must re-apply them. See
  `DISASTER_RECOVERY.md` §7.

## 14. CI/CD (real-runner twin)

`.github/workflows/ci.yml` gained a `frontend` job (after the existing
`backend` job): Node 20 + npm ci, PHP 8.3, typecheck, 20 unit/component
tests, `npm run build`, then the E2E suite against a disposable `postgres:16`
service container — migrations, `roles.sql`, `grants.sql`,
`StagingFixtureSeeder`, backend as `swasthya_app`, then
`playwright.ci.config.ts` (desktop + mobile + a11y). The entire pipeline was
executed locally as a twin (`backend/ci/run-local-ci.sh` + the CI Playwright
config against `swasthya_ci`).

**Real-runner status (2026-08-17):** the repository has been pushed to
GitHub and the pipeline now runs on real GitHub-hosted runners. The
`smoke-validation` job (the CI-side gate for `smoke_staging.sh`) **passed
on a real `ubuntu-latest` runner in runs 11, 12, 14 and 15** (`32030111553`,
`32031732908`, `32046673388`, `32048508854`): `bash -n`, dummy-value
dry-run (zero HTTP), plain-HTTP refusal, and both missing-config refusals
all green each time. One real-runner finding was fixed: `AppShell.test.tsx`
raced the async session (Node 20) and now waits for post-auth nav (32/32
on Node 20 and Node 26). Two CI fixes stopped a flaky backend leg from
starving the frontend gate (`continue-on-error` on the PHP 8.2 leg; the
self-contained frontend job now runs without `needs: backend`).
**B2 is CLOSED as of run 15 (`32048508854`), in which all four jobs
passed on real GitHub-hosted runners — backend PHP 8.2, backend PHP 8.3,
smoke-validation, and frontend — workflow conclusion: success.**

A third job, `smoke-validation`, runs in parallel and is the **CI-side gate
for the staging smoke script (§11) — validation only**. It never contacts a
host and never runs the live smoke flow; it proves the script is
syntactically valid (`bash -n`) and that its documented configuration
validation modes behave as specified, using deliberately non-sensitive
dummy values (`https://smoke.invalid` — the RFC 2606 reserved TLD — and a
throwaway CI fixture password): valid dry-run env → exit 0 with no HTTP;
plain-HTTP remote URL → exit 2 with the refusal diagnostic; missing
`STAGING_BASE_URL` → exit 2; missing `STAGING_FIXTURE_PASSWORD` → exit 2.
The live post-deploy smoke remains an operator action against a deployed
staging API (§11, §15.4) — it is intentionally not executable in CI.

## 15. What Remains for a Real Staging Host

1. Choose the provider and create the infrastructure per §2–§9 (TLS,
   load balancer, secrets store).
2. Provision `backend/.env.staging` with real secrets from the store and
   the staging domain (`APP_URL`), keeping the committed
   `.env.staging.example` shape.
3. ~~Push the repository to GitHub~~ (done 2026-08-17) and ~~close B2~~
   (done 2026-08-17, run 15 `32048508854` fully green on real runners).
4. Deploy the artifact, run `migrate --force`, then the post-deploy smoke
   and the acceptance checklist with real TLS/secrets.

## 16. Deferred Provider Decision

No cloud provider is selected yet (DEPLOYMENT.md §2.3). The decision is
recorded as required work, not fabricated: staging v1 is provider-agnostic
per this spec.
