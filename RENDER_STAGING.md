# Swasthya — Render Staging Deployment Runbook

> **Status:** READY TO PROVISION (not yet deployed). This runbook captures the
> Render-specific engineering decisions and the exact steps to stand up the
> staging environment. **Nothing here claims a deployed environment** — the
> `render.yaml` blueprint in this repository is the source of truth for the
> target architecture; it has not been provisioned yet.
>
> **Provider decision (2026-08-12):** the PostgreSQL database is **Supabase
> managed PostgreSQL 16** (project `bgfqwsivvhqmuwullkye`), connected through
> the shared pooler in **session mode** — Render runs **no database service**.
> The full Supabase contract is in `SUPABASE_STAGING.md`.

---

## 1. Provider facts

**Database — Supabase managed PostgreSQL 16** (project `bgfqwsivvhqmuwullkye`).
Capabilities (backups/PITR on paid plans, TLS, IPv4 session pooler, custom
role support) are verified in `SUPABASE_STAGING.md` §1–§2 against Supabase's
documentation. The database lives in Supabase, never on Render.

**Render (app services) facts (Render docs, 2026-08-12):**

| Requirement | Render capability | Verdict |
|---|---|---|
| HTTPS | Automatic TLS on `*.onrender.com` | ✅ (no custom domain needed for initial verification) |
| Web service (Docker) | `runtime: docker` for the Laravel API; preDeployCommand aborts deploy on any failure | ✅ |
| Static site | `runtime: static` for the React SPA with build-time `VITE_API_BASE_URL` | ✅ |
| Egress IPv4 | Instance egress is IPv4 — compatible with Supabase's IPv4-only shared pooler | ✅ |

**Billing boundary (cannot be decided here):** Supabase **paid** plan is
required for automatic backups + PITR (free projects have none). The user must
confirm the Supabase plan and payment method in the Supabase Dashboard. This
is an explicit billing decision, not something the repository can make.

---

## 2. Target architecture (what render.yaml defines)

```
Supabase managed PostgreSQL 16 (bgfqwsivvhqmuwullkye)
  └─ shared pooler, SESSION mode (aws-<region>.pooler.supabase.com:5432, IPv4, TLS)

GitHub (b4snet/swasthya-nepal)
  └─ render.yaml → Render Blueprint (env: staging)   [NO database service on Render]
       ├─ swasthya-api  — Docker web service (backend/Dockerfile)
       │    ├─ preDeployCommand: SWASTHYA_RUN_BOOTSTRAP=1 docker-entrypoint
       │    │    (owner: postgres via pooler) roles.sql → migrate --force → grants.sql
       │    │    [fails deploy on error]
       │    ├─ runtime: connects as swasthya_app (NOBYPASSRLS) via session pooler, never owner
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
| `BOOTSTRAP_DB_*` (owner: `postgres.<ref>` via pooler) | predeploy bootstrap only | `sync: false` — entered at creation (Supabase dashboard DB password) |
| `DB_USERNAME=swasthya_app.<ref>`, `DB_PASSWORD` | running application | `sync: false` — ONE secret; roles.sql creates `swasthya_app` with the exact same value |
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

1. **Confirm Supabase billing:** a **paid** Supabase plan (automatic backups
   + PITR require it) and payment method on the Supabase account. This is a
   business decision — the repo cannot or should not decide it.
2. **From the Supabase Dashboard** copy the **session-pooler** connection
   string (Dashboard → Connect) for both `postgres` and (after bootstrap)
   `swasthya_app`; split them into the env values in `SUPABASE_STAGING.md` §3.
3. **Link GitHub:** Render → New + → Blueprint → connect
   `b4snet/swasthya-nepal`. Render needs OAuth access to the repo.
4. **Enter the `sync: false` secrets** at creation time (they are prompted by
   the Blueprint flow — never in git):
   - `DB_HOST`, `DB_USERNAME=swasthya_app.<ref>`, `DB_PASSWORD` (runtime)
   - `BOOTSTRAP_DB_HOST`, `BOOTSTRAP_DB_USERNAME=postgres.<ref>`,
     `BOOTSTRAP_DB_PASSWORD` (owner)
   - `APP_URL` → `https://swasthya-api.onrender.com` (or whatever URL Render
     generates)
   - `SWASTHYA_CORS_ALLOWED_ORIGINS` → `https://swasthya-frontend.onrender.com`
   - `VITE_API_BASE_URL` → `https://swasthya-api.onrender.com`
5. **Confirm region** (singapore is assumed — nearest Render region to Nepal;
   immutable after creation).
6. **Create.** The blueprint runs the bootstrap against Supabase
   (roles/migrate/grants, fail-closed), then starts the API and builds the
   frontend. There is no Render-managed database and no allowlist to tighten
   (the pooler is reached over TLS with credentials only).

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
   roles, grants, audit (Supabase daily backups + PITR; logical export and
   restore procedure in `SUPABASE_STAGING.md` §6).
7. Performance micro-benchmarks + slow-query review.

None of these are claimed yet. They become real only when the Render
environment exists and the checks are actually run.
