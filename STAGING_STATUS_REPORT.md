# Swasthya Staging Status Report

> **Date:** 2026-08-12 · **Author:** engineering verification pass
> **Purpose:** determine exactly how far the STAGING DEPLOYMENT & PRODUCTION
> ENGINEERING milestone has actually progressed — against the standard in
> `STAGING.md`, not against localhost.
>
> **Critical distinction applied throughout:** `http://localhost:5173`, the
> localhost backends (58998/58999) and the local PostgreSQL cluster (54329)
> are **local development/preview infrastructure**. They are classified as
> evidence of implementation quality and of the local mirror, **never** as a
> real staging deployment. Staging is only VERIFIED when the application is
> deployed into the environment specified by `STAGING.md` §2–§9 (real host,
> domain, TLS, managed PostgreSQL, secrets store).

---

## 1. Executive Status

The engineering work behind the staging milestone is **substantial and
real**: the repository is under Git; the backend+frontend CI pipeline is
defined and was executed end-to-end as a **local twin** against disposable
PostgreSQL (241 backend tests / 1,748 assertions; 4 E2E/a11y tests green);
a **local staging mirror** exists and was verified (dedicated
`swasthya_staging` database, 144 RLS policies, `swasthya_app_staging`
NOBYPASSRLS role, both fixture tenants, full OPD E2E green); a real
backup/restore drill was performed and verified; two accessibility defects
were fixed.

However, **the real staging environment specified by STAGING.md does not
exist.** There is no cloud account, no domain/DNS, no TLS, no managed
PostgreSQL, no secrets store, no deployment target, and no CI run on a real
GitHub-hosted runner (the repository has zero remotes). Every verified item
below was verified **locally**. Under the explicit classification rule, the
only truthful verdict is:

**NOT READY FOR STAGING**

This corrects the earlier draft verdict in `STAGING_DEPLOYMENT_REPORT.md`
("READY FOR STAGING"), which treated the local mirror as satisfying the
staging requirement. It does not. The local mirror proves the *procedures*
work; it is not a deployment.

---

## 2. Local Preview Status — VERIFIED (as preview infrastructure only)

| Item | Status | Evidence |
|---|---|---|
| Frontend SPA | VERIFIED | Vite dev server on `http://localhost:5173` (PID 22176); renders the real React SPA (login page verified via snapshot) |
| Dev backend | VERIFIED | Laravel `artisan serve` on `127.0.0.1:58999` (PID 9044); `GET /api/v1/health/live` → HTTP 200 |
| PostgreSQL | VERIFIED | Port 54329 listening (PID 26620); databases `swasthya`, `swasthya_test`, `swasthya_staging` present |
| Runtime role | VERIFIED | Dev app connects as `swasthya_app` (non-owner, NOBYPASSRLS) |
| RLS active | VERIFIED | Enforced per query through the runtime role; tenant probes deny cross-tenant access |

This is healthy, real, working software — and it is **not staging**.

---

## 3. Git Status — VERIFIED (repository), MISSING (remote/hosting)

| Requirement | Status | Evidence |
|---|---|---|
| Repository initialized | VERIFIED | `git log`: `fd7d77f` baseline + `82f6b51` (untrack tsbuildinfo); `.gitignore` excludes `.env*`, `dist`, `vendor`, `node_modules`; no secrets in commits |
| Remote / hosting | MISSING | `git remote` returns **0 remotes** — the repository has never been pushed anywhere |
| Working tree cleanliness | PARTIAL | 20 uncommitted changes, including the CI frontend job, `StagingFixtureSeeder`, staging Playwright configs, a11y spec, `STAGING_DEPLOYMENT_REPORT.md`, and all documentation updates from this milestone — none committed yet |

The milestone's git work (safe init, .gitignore, baseline commit) is
complete; the repository is still local-only.

---

## 4. CI/CD Status — PARTIAL (defined + locally proven), MISSING (real-runner execution)

| Requirement | Status | Evidence |
|---|---|---|
| Pipeline definition | VERIFIED | `.github/workflows/ci.yml` defines `backend` job (PHP 8.2/8.3, Pint, disposable postgres:16, migrations, RLS verification, full Pest suite) and `frontend` job (Node 20, typecheck, 20 unit tests, build, E2E via `playwright.ci.config.ts`) |
| Local twin execution | VERIFIED | `backend/ci/run-local-ci.sh` green (241 tests / 1,748 assertions) on disposable `swasthya_ci`; CI Playwright config green (4/4) on the same disposable DB with backend as `swasthya_app` |
| Executed on a real GitHub-hosted runner | MISSING | No remote → no push → no runner run. **No CI run of any kind has ever executed on a real provider.** |
| CI file committed | PARTIAL | Base workflow committed in `fd7d77f`; the **frontend job addition is uncommitted** (working-tree only) |

The pipeline is well-defined and was proven locally; it is unproven in its
actual operating environment.

---

## 5. Frontend Status — VERIFIED (locally)

- Production build succeeds: `npm run build` → `frontend/dist/` (index.html,
  219.59 kB JS / 18.50 kB CSS, gzip-compressed).
- 20 unit/component tests green (Vitest + Testing Library); typecheck clean.
- E2E specs exist and pass against the local stack: desktop OPD workflow,
  mobile receptionist flow (iPhone 13 viewport), axe accessibility scan
  (`frontend/test-results/.last-run.json`: `status: passed`).
- No production deploy of this artifact exists.

## 6. Backend Status — VERIFIED (locally)

- Full Pest suite green on disposable PostgreSQL: **241 tests / 1,748
  assertions** (unit, integration, API, database, RLS, tenant isolation,
  audit).
- Health endpoints verified live: `/api/v1/health/live` and
  `/api/v1/health/ready` (readiness does a real DB check).
- Runs as the least-privilege role with RLS enforced; structured logging with
  request/correlation IDs verified.
- No container image, no artifact registry, no deployed instance.

## 7. Database Status — VERIFIED (local mirror), MISSING (managed staging host)

- Local `swasthya_staging` DB: 50 tables, 47 migrations, 144 RLS policies,
  37 RLS-enabled tables.
- Bootstrap order per STAGING.md §4 executed: roles.sql → migrate →
  grants.sql → fixture seed.
- **No managed PostgreSQL, no WAL archiving, no automated backups on a
  staging host.**

## 8. RLS Status — VERIFIED (local mirror)

- 144 policies across 37 tenant-owned tables; `swasthya_app_staging` role is
  `NOBYPASSRLS`, non-superuser, non-owner.
- SQL-level probes confirmed: no context → 0 rows; wrong tenant → 0 rows;
  owner tenant → correct count. Same probe passed on a restored copy.
- RLS is real and enforced — on the local mirror.

## 9. Tenant Isolation — VERIFIED (local mirror)

- Tenant A created a patient; tenant B could not read or search it via the
  API (tenant-scoped 404/empty semantics); RLS independently denies at the
  engine level. Both tenants (`smoke-group`, `apex-care`) are synthetic
  fixtures — no production data.

## 10. Authentication — VERIFIED (local mirror)

- Real login with argon2id verification, rotating refresh tokens with reuse
  detection, session restoration, facility context from the server-issued
  `assignments` payload. Both fixture tenants authenticate on the mirror.

## 11. RBAC — VERIFIED (local mirror)

- Role-permission gates exercised by the E2E (admin vs doctor) and by the
  backend authorization suite; wrong-role actors denied at each clinical and
  financial gate.

## 12. OPD E2E — VERIFIED (local stack; **not** against a staging host)

- Full chain green against the local stack: patient → appointment →
  check-in → queue → encounter → note → diagnosis → prescription → sign →
  invoice → payment → audit. Real APIs, real DB, real RLS — no mocks.

## 13. Mobile E2E — VERIFIED (local stack)

- Receptionist flow at iPhone 13 viewport (390×844): no horizontal overflow,
  bottom navigation, patient search, booking, check-in, queue — green.

## 14. Staging Infrastructure — MISSING

Per STAGING.md §2–§9, real staging requires: a host for the app, a TLS
terminator/load balancer, a domain with DNS, a managed PostgreSQL 16, and a
secrets store. **None of these exist.** There is no `deployment/` directory,
no `docker/` directory, no Dockerfile or docker-compose for the application
(only a transitive vendor file). No cloud provider is selected or configured.

## 15. Secrets — PARTIAL

- No secrets in Git (verified by pattern scan; `.env*` gitignored except
  `.env.example` / `.env.staging.example` templates).
- `.env.staging.example` committed as a template; real `.env.staging` is
  untracked.
- **No secrets store exists** (the deployment-platform secret manager
  required by STAGING.md §6 is absent). App keys and the app-role password
  live in untracked local env files only.

## 16. TLS — MISSING

- No public host, no certificate, no HSTS in a deployed context (the header
  is emitted by the API middleware, but there is no TLS endpoint anywhere).

## 17. Monitoring — MISSING

- No monitoring stack (no dashboards, no alerting, no metrics collector, no
  uptime checks) is deployed anywhere. STAGING.md §10 defines what staging
  must have; none of it runs. The instrumentation hooks (structured logs,
  health probes, request IDs) are verified working locally, which is
  necessary but not sufficient.

## 18. Backup — PARTIAL

- A real `pg_dump -Fc` of the local `swasthya_staging` was performed
  (304,440 bytes, ~1 s) and verified (50 table-data sections, 144 policy
  entries). 
- **Not met:** automated/scheduled backups, WAL archiving, encrypted
  backups, offsite/object-storage copy — all required by
  DISASTER_RECOVERY.md and absent.

## 19. Restore — PARTIAL

- Restore into a disposable DB verified (exit 0): schema, data, both
  tenants, 123 audit events, RLS probes holding on the restored copy;
  app-role grants re-applied post-restore (documented fixup).
- This validates the *procedure* locally. A restore on a real staging host
  with production-scale data has not been performed; RPO/RTO are unmeasured.

## 20. Security — PARTIAL

Verified locally: no secrets in source; RBAC + tenant context + RLS as
defense-in-depth; rate limiting; structured never-log discipline (no
passwords/tokens/PHI found in logs); `APP_DEBUG=false` on the mirror;
security headers emitted; accessibility defects fixed.
Not met: dependency/container scanning in CI (CI never ran), DAST/penetration
testing, secrets-store integration, real TLS posture, incident-response
tooling.

## 21. Performance — PARTIAL

- Local micro-benchmarks recorded (login ~0.9–1.1 s; tenant-scoped reads
  ~0.35–0.65 s) and an earlier RLS load benchmark exists.
- **Not met:** performance/load testing on a staging host; no production
  baseline; no SLOs measured.

## 22. Accessibility — VERIFIED (locally)

- `@axe-core/playwright` scans of dashboard, patients, appointments, queue,
  billing, and the doctor workspace: **zero serious/critical violations**.
- Two AA defects found and fixed: muted-text contrast (4.38 → 4.96/4.56:1)
  and link distinguishability (WCAG 1.4.1 underline).

---

## 23. Missing Requirements (exact)

1. **Real CI execution** — a GitHub repository with a remote, so the
   `backend` + `frontend` jobs actually run on a GitHub-hosted runner.
2. **Staging host** — compute for the Laravel app and the SPA (no provider
   selected; no `deployment/`, `docker/`, or Dockerfile exist).
3. **Domain + DNS** — a public hostname for staging (frontend and backend
   URLs); `APP_URL` is currently a loopback address.
4. **TLS** — certificate + automatic renewal + HTTPS-only routing.
5. **Managed PostgreSQL 16** — a staging database host with WAL archiving;
   only a local cluster exists.
6. **Secrets store** — env-injected secrets (APP_KEY, DB passwords for both
   roles, signing keys); currently untracked local env files.
7. **Monitoring/alerting** — dashboards and alerts per STAGING.md §10 (5xx
   rate, P95, `/ready`, auth-lockout, RLS-denial spikes).
8. **Automated encrypted backups + offsite copy** — scheduled pg_dump/WAL,
   encryption at rest, restore drills on the real host.
9. **CI credentials / deployment credentials** — none exist (none invented).
10. **Commit the milestone's working-tree changes** — 20 files uncommitted
    (including the CI frontend job and the fixture seeder).

## 24. Blockers

- **No remote repository** → CI cannot run on a real runner; this is the
  single highest-value next action and is blocked only by pushing.
- **No provider/deployment decision** → no host, no DNS, no TLS, no secrets
  store; this is a decision requirement, not a technical one.
- **Uncommitted milestone work** → the CI frontend job and staging artifacts
  are not yet under version control.

## 25. Exact Next Actions

1. Commit the current working-tree changes (CI frontend job,
   `StagingFixtureSeeder`, Playwright staging/CI configs, a11y spec, doc
   updates, `STAGING_DEPLOYMENT_REPORT.md`) on a branch and push to a
   GitHub repository.
2. Watch the `backend` and `frontend` jobs run on a real GitHub-hosted
   runner; fix anything that fails there (first real-runner CI evidence).
3. Select a provider and create the staging infrastructure per STAGING.md
   §2–§9 (host, domain, TLS, managed PG, secrets store) — record the ADR.
4. Deploy the CI artifact, run `migrate --force`, run the post-deploy smoke
   and the full staging acceptance checklist against the real host.
5. Configure automated encrypted backups + WAL archiving and run the
   backup/restore drill on the real staging host; record RPO/RTO.
6. Stand up monitoring/alerting per STAGING.md §10 against the real host.
7. Re-issue this report against the real environment before any next HMS
   module is planned.

## 26. Final Verdict

**NOT READY FOR STAGING**

The engineering is genuine and the local evidence is strong, but **the real
staging environment specified by STAGING.md does not exist**. Per the
classification rule — localhost is never staging — the milestone's runtime
deliverables (real-runner CI, deployed host, TLS, managed PostgreSQL,
secrets store, monitoring, automated backups) are all MISSING. This report
supersedes the earlier draft verdict in `STAGING_DEPLOYMENT_REPORT.md`, which
is corrected accordingly.
