# Swasthya — Staging Environment Specification

> **Status: SPECIFICATION — no staging environment exists yet.** Nothing in
> this document describes a running environment. It is the concrete build
> spec for the first staging deployment (STAGING_READINESS_REPORT.md §18–19).
> Everything below is verifiable: each item maps to a check in the staging
> acceptance checklist at the end.

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
DB_HOST=<staging-db-host>
DB_PORT=5432
DB_DATABASE=swasthya_staging
DB_USERNAME=swasthya_app        # runtime role — never the migration owner
DB_PASSWORD=<secret>
DB_SSLMODE=require

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
4. Post-deploy smoke: the staging smoke script walks patient → appointment
   → check-in → queue → encounter → note → diagnosis → prescription → sign
   → invoice → payment → audit against staging (`backend/smoke_staging.sh`
   pattern, with staging env values).
5. Nightly: full test suite, RLS suite, load benchmark (`ci/load-benchmark.sh`),
   backup/restore drill (`ci/backup-restore-drill.sh`).

## 12. Staging Acceptance Checklist

- [ ] All services from §2 running, health endpoints green
- [ ] `APP_DEBUG=false`, `APP_ENV=staging`
- [ ] Database roles verified (`NOBYPASSRLS`, non-owner runtime role)
- [ ] RLS policies present; cross-tenant probes denied
- [ ] Migration bootstrap order (roles → migrate → grants) verified
- [ ] TLS valid; HSTS header present
- [ ] Secrets injected, none in the repo or deploy logs
- [ ] Structured logs carry request/correlation IDs; never-log list empty
- [ ] Post-deploy OPD smoke walks the full chain against staging
- [ ] Backup/restore drill passes against the staging DB
