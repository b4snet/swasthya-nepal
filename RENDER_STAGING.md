# Swasthya — Render Staging Deployment Runbook

> **Status:** READY TO PROVISION (not yet deployed). This runbook captures the
> Render-specific engineering decisions and the exact steps to stand up the
> staging environment. **Nothing here claims a deployed environment** — the
> `render.yaml` blueprint in this repository is the source of truth for the
> target architecture; it has not been provisioned yet.

---

## 1. Verified Render platform facts (from Render docs, 2026-08-12)

| Requirement (STAGING.md §2–§9) | Render capability | Verdict |
|---|---|---|
| PostgreSQL 16 | Major versions **13–18** selectable per new instance | ✅ 16 supported |
| Backups / recovery | **Paid instances only**: PITR (Hobby workspace: past 3 days; Pro+: past 7 days) + on-demand logical exports. **Free instance type: no backups, no PITR, no exports** | ✅ with paid plan; ⚠️ **billing decision required** |
| Encryption at rest | AES-256 for primary, replicas, and **backups** | ✅ |
| TLS in transit | Render-managed TLS on external connections; TLS 1.2+ and specific cipher suites required | ✅ |
| Networking | Internal (private, same-region) URL + external URL; IP allowlist (CIDR) restricts external access; can disable external access entirely (internal still works) | ✅ |
| Connection limits | 100 (RAM < 8 GB) … 500 (RAM ≥ 32 GB) | ✅ staging scale is fine |
| Role management | Provisioned user is NOT superuser (some ops need Render support) but **can `CREATE DATABASE`**; the `swasthya_app` least-privilege bootstrap (roles.sql → migrate → grants.sql) is designed to **fail closed** if `CREATE ROLE` is ever denied | ✅ fail-closed design |
| Slow-query visibility | Queries > 2 s logged (`duration:` lines) | ✅ |
| HTTPS | Automatic TLS on `*.onrender.com` | ✅ (no custom domain needed for initial verification) |

**Billing boundary (cannot be decided here):** PITR/logical exports require a
**paid Render Postgres plan** (and the workspace plan determines the recovery
window). `render.yaml` uses `plan: starter` as a placeholder — the user must
confirm the plan and the payment method in the Render Dashboard before
provisioning. This is an explicit billing decision, not something the
repository can make.

---

## 2. Target architecture (what render.yaml defines)

```
GitHub (b4snet/swasthya-nepal)
  └─ render.yaml → Render Blueprint (env: staging)
       ├─ Postgres 16 (swasthya-db) — paid plan, region singapore
       ├─ swasthya-api  — Docker web service (backend/Dockerfile)
       │    ├─ preDeployCommand: SWASTHYA_RUN_BOOTSTRAP=1 docker-entrypoint
       │    │    (owner) roles.sql → migrate --force → grants.sql  [fails deploy on error]
       │    ├─ runtime: connects as swasthya_app (NOBYPASSRLS), never owner
       │    └─ health: /api/v1/health/ready  (liveness implied; readiness gates traffic)
       └─ swasthya-frontend — static site (frontend/), VITE_API_BASE_URL → API URL
```

### 2.1 The bootstrap (safe migrations, STAGING.md §6)

1. `roles.sql` — idempotent `CREATE ROLE swasthya_app … NOBYPASSRLS` with the
   runtime `DB_PASSWORD` (single generated secret — one value, never two).
2. `php artisan migrate --force` — **only forward migrations**. No
   `migrate:fresh`, no `db reset`, no `DROP DATABASE` anywhere in the flow.
3. `grants.sql` — idempotent DML grants + default privileges for
   `swasthya_app`.

`set -e` + `ON_ERROR_STOP` + Render's `preDeployCommand` semantics mean **any
failure aborts the deploy before the new version serves traffic.**

### 2.2 Role separation

| Credential | Used by | Render source |
|---|---|---|
| `BOOTSTRAP_DB_*` (owner) | predeploy bootstrap only | `fromDatabase` (owner user/password) |
| `DB_USERNAME=swasthya_app`, `DB_PASSWORD` (generated) | running application | `DB_PASSWORD: generateValue`; role created with same value |
| `APP_KEY` | Laravel encryption | `generateValue` |
| `APP_URL`, `SWASTHYA_CORS_ALLOWED_ORIGINS`, `VITE_API_BASE_URL` | config | `sync: false` — entered in Dashboard at creation |

**Why no two generated secrets:** `roles.sql` creates `swasthya_app` with a
password; the app connects with a password. They must be the **same** value or
every request fails at the database. `render.yaml` therefore generates exactly
one `DB_PASSWORD` used for both (entrypoint passes it to `roles.sql`).

---

## 3. What you must do (in order) — external actions

### 3.1 GitHub (CI)

- [x] Repository pushed: `b4snet/swasthya-nepal` @ `main` (force-with-lease
      over the auto-init placeholder commit — documented in DEVELOPMENT_LOG).
- [ ] **Enable GitHub Actions** if the push did not trigger `backend-ci`
      (check https://github.com/b4snet/swasthya-nepal/actions). If runs do
      not appear: Settings → Actions → General → Allow all actions and
      reusable workflows. CI needs a runner to exist before it can be "real".
- [ ] Optional: set `APP_ROLE_PASSWORD` as a repo secret (CI falls back to a
      disposable default for the ephemeral DB; a secret is cleaner but not
      required for CI to run).

### 3.2 Render (staging)

1. **Confirm billing:** a paid Render plan (Postgres PITR requires it) and a
   payment method on the workspace. This is a business decision — the repo
   cannot or should not decide it.
2. **Link GitHub:** Render → New + → Blueprint → connect
   `b4snet/swasthya-nepal`. Render needs OAuth access to the repo.
3. **Enter the `sync: false` secrets** at creation time (they are prompted by
   the Blueprint flow — never in git):
   - `APP_URL` → `https://swasthya-api.onrender.com` (or whatever URL Render
     generates)
   - `SWASTHYA_CORS_ALLOWED_ORIGINS` → `https://swasthya-frontend.onrender.com`
   - `VITE_API_BASE_URL` → `https://swasthya-api.onrender.com`
4. **Confirm region** (singapore is assumed — nearest Render region to Nepal;
   immutable after creation) and the **Postgres plan** (paid).
5. **Create.** The blueprint provisions Postgres, runs the bootstrap
   (roles/migrate/grants), then starts the API and builds the frontend.
6. **Tighten the DB allowlist** after creation: replace `0.0.0.0/0` with the
   app's egress / internal-only access (`render.yaml` documents this as a
   temporary staging allowlist; SECURITY.md §14 requires the DB not be
   publicly reachable).

### 3.3 What I cannot do (and will not fake)

- Create the Render account, workspace, or services (needs your OAuth + billing).
- Enter your secrets (they must go through Render's env, not git, not chat).
- Confirm the exact `onrender.com` URLs before Render generates them.

---

## 4. Post-deployment verification checklist (next milestone step)

1. `GET /api/v1/health/live` and `/api/v1/health/ready` → 200 over HTTPS.
2. Log in as both synthetic fixture tenants (`StagingFixtureSeeder`).
3. Real OPD workflow: Patient → Appointment → Check-in → Queue → Encounter →
   Diagnosis → Prescription → Sign → Invoice → Payment → Audit.
4. Tenant A vs Tenant B at the API **and** SQL/RLS level.
5. Mobile E2E + accessibility (Playwright against the staging URL).
6. Staging backup → restore into a disposable DB → verify schema, RLS,
   roles, grants, audit (Render PITR + logical exports).
7. Performance micro-benchmarks + slow-query review.

None of these are claimed yet. They become real only when the Render
environment exists and the checks are actually run.
