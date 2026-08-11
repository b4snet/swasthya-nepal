# Swasthya Tenancy V2 Hardening Report

> **Scope:** Tenancy V2 — Branch hierarchy, PostgreSQL row-level security, database roles, tenant context, and privileged platform access control. No new HMS business module was built. The complete OPD workflow was re-verified under RLS.
>
> **Verification baseline:** 241 tests / 1,742 assertions green against real PostgreSQL; live HTTP smoke of the full OPD workflow running as the least-privilege `swasthya_app` role with RLS active; Pint clean (293 files).

---

## 1. Executive Summary

Swasthya's tenancy model was hardened from an application-layer-only isolation model to a three-layer defense-in-depth model:

```
Application authorization  +  Server-side tenant context  +  PostgreSQL RLS
```

Three production-blocking gaps identified in the foundation assessment were closed:

1. **PostgreSQL Row-Level Security did not exist.** RLS is now enabled with per-operation policies on every tenant-owned table (36 tables + audit/assignments/support-sessions), enforced by the engine with transaction-local GUCs set from the authenticated principal — never from client input.
2. **Branch was missing from the tenancy hierarchy.** The `branches` table, model, API, authorization, audit, and branch-scoped catalog columns (`departments`, `locations`, `wards`, `rooms`, `beds`) are implemented with a documented ownership decision.
3. **Platform-level access bypassed the tenant boundary.** Platform administration is now confined to the `platform/*` route namespace with platform-scope permissions; tenant data access requires an explicit, time-limited, fully-audited support session. There is no "bypass everything" path.

Two additional defects were found and fixed during live verification:

- **Login returned empty assignments under RLS** (public route queried `role_assignments` without `app.user_id`), and the login payload's facility names were invisible without a tenant GUC. Fixed with a user-scoped transaction plus an authorization-join policy on `facilities`.
- **Every `/{model}` route 404'd under RLS**: Laravel 11's implicit route model binding (`SubstituteBindings`) runs in the framework `api` group *before* the route's tenant-context middleware, so bound models were queried with empty GUCs. Fixed by raising `ResolveTenantContext` above `SubstituteBindings` in the middleware priority; a structural regression test guards it.

The complete OPD workflow — patient → appointment → check-in → queue → encounter → documentation → diagnosis → prescription → sign → invoice → payment → audit — was walked **live over HTTP as `swasthya_app`** (RLS active) and succeeded end-to-end with real database records and a complete audit trail.

**Verdict: READY FOR STAGING.** (Justification in §30.)

---

## 2. Previous Tenancy Architecture

Before V2, isolation was enforced entirely by the application layer:

- **Tenant/facility context** was derived from the authenticated principal's role assignments by `ResolveTenantContext` middleware and stored in an in-memory `TenantContext`.
- **Authorization** was enforced per-endpoint with the `authorize:` gate, `AccessCheck` (404-for-reads / 403-for-writes), and tenant-safe composite foreign keys.
- **Database** was a single shared PostgreSQL 16 database; the application connected as the schema owner (`swasthya` — superuser in dev), which **bypassed any hypothetical RLS**.
- **No RLS, no GUC projection, no non-owner application role, no Branch entity.**
- **Platform admins** (`superadmin` with platform scope) could query tenant tables; the design contract's "platform admins do not automatically access tenant data" rule was not implemented.

That application layer was strong and well-tested (211 tests at the start of V2), but the database itself could not prevent a buggy query, a wrong join, or a privileged user from reading across tenants.

---

## 3. Final Tenancy Architecture

```
PLATFORM
    │
    └── ORGANIZATION                       ← tenant root (no tenant_id; code unique)
            │
            ├── FACILITY                   ← tenant_id required; the tenant's physical site
            │       │
            │       └── BRANCH             ← tenant_id + facility_id required
            │               │
            │               ├── DEPARTMENT ← branch-scoped catalog
            │               ├── LOCATION   ← branch-scoped catalog
            │               ├── WARD       ← branch-scoped catalog
            │               ├── ROOM       ← branch-scoped catalog
            │               └── BED        ← branch-scoped catalog
            │
            └── Staff / Users / Services / clinical + financial records (facility-scoped)
```

**Canonical terminology (decision recorded in TENANCY.md §0/V2 §4):** `organization → facility → branch`. A *facility* is the tenant's physical site; a *branch* is an operational sub-unit under a facility. No duplicate concepts (`hospital`, `site`, `location-as-site`) were introduced — `locations` remains a catalog resource (exam rooms, nursing stations) now optionally branch-scoped.

**Ownership decision (TENANCY.md V2 §5):** `branch_id` was added only where the domain treats branch as an operational grouping: the five catalog tables. It was **not** added to clinical or financial records (appointments, encounters, diagnoses, prescriptions, invoices, payments) — branch is a grouping, not a hard data boundary; patient/clinical data belongs to the facility. `role_assignments` already carried `branch_id` (used for branch-scoped roles); the `branch_manager` role scope was corrected to facility in the seeder.

---

## 4. Organization Model

| Aspect | Design |
|---|---|
| Purpose | The tenant root. One organization owns multiple facilities. |
| PK / tenant ownership | UUID; **no `tenant_id`** (it is the tenant). |
| Key fields | `id`, `name`, `code` (unique), `status` (`active` / `suspended` / `closed` / `offboarded`), timestamps, soft-delete. |
| RLS | **Intentionally not RLS-scoped**: the tenant boundary itself must be resolvable before a tenant context exists (login, context resolution). Authorization stays in the application layer (`AccessCheck::organization`). |
| Lifecycle | Never hard-deleted; `offboarded` terminal status; suspension → `TENANT_SUSPENDED` 403 on every request (isolation never weakens at any status). |

## 5. Facility Model

| Aspect | Design |
|---|---|
| Purpose | The tenant's physical site; the granularity of most operational workflows. |
| PK / tenant ownership | UUID; `tenant_id` required (composite FK to `organizations`). |
| Key fields | `id`, `tenant_id`, `name`, `code` (partial-unique `(tenant_id, code)`), `status`, `timezone`, `address`, `settings` (JSONB), soft-delete. |
| RLS | Tenant-scoped (`tenant_id = app.tenant_id`) **plus an authorization join**: a principal can resolve facilities it has an active assignment to via `EXISTS (role_assignments where user_id = app.user_id and facility_id = facilities.id)` — required so the login payload / facility picker work before any tenant GUC exists (TENANCY.md V2 §7: the client *proposes* `X-Swasthya-Facility`; it never asserts it). |
| Isolation | Facility-scoped principals see exactly their facility (404 reads / 403 writes cross-facility); org-level context sees all facilities of the org. |

## 6. Branch Model

| Aspect | Design |
|---|---|
| Purpose | Operational sub-unit under a facility (e.g., "Main Building — Ground Floor OPD"). |
| PK / tenant ownership | UUID; `tenant_id` + `facility_id` both required; composite FK makes a cross-tenant/cross-facility branch structurally impossible. |
| Key fields | `id`, `tenant_id`, `facility_id`, `name`, `code` (partial-unique `(tenant_id, facility_id, code)`), `status`, timestamps. |
| RLS | Tenant + facility scoped (TENANT_FACILITY category). |
| API | `GET/POST /organizations/{organization}/branches`, `GET/PATCH/DELETE /branches/{branch}` under `authorize:branch:*`; `X-Swasthya-Branch` proposal header validated against the resolved facility → 403 `BRANCH_DENIED` outside scope. |
| Audit | `branch.created/updated/…` events with actor + facility + correlation id. |
| Scope in catalogs | `departments`, `locations`, `wards`, `rooms`, `beds` carry optional `branch_id`; their RLS policy adds a branch clause (`branch_id IS NULL OR branch_id = app.branch_id OR app.branch_id IS NULL`) — a branch-scoped context sees its branch, a facility context sees all branches, a cross-branch attempt is invisible. |

## 7. Tenant Context Architecture

```
AUTHENTICATED USER (Sanctum bearer token, hashed at rest)
        │
        ▼
ACTIVE ROLE ASSIGNMENTS (server-side, RLS lets the principal read its own rows via app.user_id)
        │
        ▼
CONTEXT RESOLUTION (platform? support session? tenant+facility+optional branch?)
        │
        ▼
DATABASE PROJECTION — one transaction per request:
        beginTransaction → set_config('app.tenant_id'/'app.facility_id'/'app.branch_id'/
                                       'app.user_id'/'app.is_platform', …, is_local=true)
        → handle request (all queries run under RLS) → commit/rollback → resetAll()
```

- **Client-supplied IDs are proposals, never authority.** `X-Swasthya-Facility` must match an active assignment (else 403 `FACILITY_DENIED`); `X-Swasthya-Branch` must match the resolved facility (else 403 `BRANCH_DENIED`). The tenant is always derived server-side from the facility's organization.
- **Connection-pool safety:** every GUC is set with `set_config(..., is_local=true)` — scoped to the current transaction, which the middleware owns and closes on every path (commit or rollback), followed by an explicit `resetAll()`. A reused connection, a pooled worker, or a queued job can never observe another request's context (TENANCY.md V2 §7). Proven by a two-connection concurrent test in the RLS suite.
- **Platform context** (superadmin without a support session) runs with an *empty tenant GUC*: `is_platform=true`, no tenant — the database refuses tenant rows. Platform admins manage the platform, not tenant data.
- **Support context** (platform principal with an active session) runs as the session's organization/facility with the read-only `support_agent` role; every operation carries the session id and reason into audit.

## 8. PostgreSQL RLS Architecture

- **Mechanism:** `ALTER TABLE … ENABLE ROW LEVEL SECURITY` + per-operation policies reading the `app.*` GUCs. The application connects as `swasthya_app` (no `BYPASSRLS`), so the engine itself enforces isolation — even for a query that forgets `WHERE tenant_id = …`, a wrong join, or a future bug.
- **Policy categories** (see matrix in §9):
  - *Tenant-only* tables: `tenant_id = app.tenant_id`.
  - *Tenant+facility* tables: `tenant_id = app.tenant_id AND (facility_id = app.facility_id OR app.facility_id IS NULL)` — an org-wide context (no facility GUC) sees every facility of the tenant.
  - *Tenant+facility+branch* tables: adds `(branch_id IS NULL OR branch_id = app.branch_id OR app.branch_id IS NULL)`.
  - *INSERT* is deliberately permissive (`WITH CHECK true`) — the app sets tenant_id server-side from context, and provisioning/fixtures must create rows before a tenant GUC exists. The isolation guarantee is READ/UPDATE/DELETE.
  - *UPDATE `WITH CHECK`* pins the row to the current tenant AND facility: a context can neither move a row into another tenant nor another facility (cross-facility moves are org-level decisions).
  - *`audit_events`*: append-only — SELECT is platform/tenant-split, INSERT allowed, **no UPDATE/DELETE policy**, so the app role cannot edit or erase history even with table-level grants.
  - *`role_assignments`*: dual policy — a principal reads its own rows (`user_id = app.user_id`), tenant admins manage their tenant's rows, platform context sees platform rows only.
  - *`support_sessions`*: visible to the owning user or platform context only.
- **Not RLS-scoped (documented, deliberate):** `users`, `roles`, `permissions`, `role_permissions`, `personal_access_tokens`, `refresh_tokens`, `organizations` — identity, the role catalog, and the tenant boundary must resolve *before* a tenant context exists; authorization stays in the application layer.

## 9. RLS Policy Coverage

| Table | Owner | Org Scope | Facility Scope | Branch Scope | RLS | Policy |
|---|---|---|---|---|---|---|
| organizations | tenant root | — (it is the tenant) | — | — | no | app-layer only (resolvable pre-context) |
| facilities | tenant | tenant_id = GUC | + authz join (own assignment) | — | yes | select/update w/ join; delete tenant-only |
| branches | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| departments | tenant+facility+branch | tenant_id | facility_id | branch_id | yes | tenant ∧ facility ∧ branch |
| locations | tenant+facility+branch | tenant_id | facility_id | branch_id | yes | tenant ∧ facility ∧ branch |
| wards | tenant+facility+branch | tenant_id | facility_id | branch_id | yes | tenant ∧ facility ∧ branch |
| rooms | tenant+facility+branch | tenant_id | facility_id | branch_id | yes | tenant ∧ facility ∧ branch |
| beds | tenant+facility+branch | tenant_id | facility_id | branch_id | yes | tenant ∧ facility ∧ branch |
| staff | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| services | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| facility_settings | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| schedule_templates | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| schedule_exceptions | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| appointments | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| token_counters | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| encounters | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| patients | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| medications | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| charges | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| invoices | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| payments | tenant+facility | tenant_id | facility_id | — | yes | tenant ∧ facility |
| payers | tenant | tenant_id | — | — | yes | tenant |
| mrn_counters | tenant | tenant_id | — | — | yes | tenant |
| patient_identifiers | tenant | tenant_id | — | — | yes | tenant |
| patient_contacts | tenant | tenant_id | — | — | yes | tenant |
| insurance_policies | tenant | tenant_id | — | — | yes | tenant |
| patient_documents | tenant | tenant_id | — | — | yes | tenant |
| consents | tenant | tenant_id | — | — | yes | tenant |
| patient_timeline_entries | tenant | tenant_id | — | — | yes | tenant |
| diagnoses | tenant | tenant_id | — | — | yes | tenant |
| clinical_notes | tenant | tenant_id | — | — | yes | tenant |
| prescriptions | tenant | tenant_id | — | — | yes | tenant |
| prescription_lines | tenant | tenant_id | — | — | yes | tenant |
| invoice_lines | tenant | tenant_id | — | — | yes | tenant |
| payment_allocations | tenant | tenant_id | — | — | yes | tenant |
| audit_events | platform/tenant split | tenant_id (tenant rows) / NULL (platform rows) | facility | — | yes | append-only; select split, insert only |
| role_assignments | platform/tenant split | own rows via user_id; tenant rows; platform rows | facility clause | branch | yes | dual policy |
| support_sessions | platform artifact | — | — | — | yes | owner or platform only |
| users | identity | — | — | — | no | app-layer only (pre-context) |
| roles / permissions / role_permissions | platform catalogs | — | — | — | no | app-layer only (pre-context) |
| personal_access_tokens / refresh_tokens | identity | — | — | — | no | app-layer only (pre-context) |

*(Source: `database/migrations/2026_08_11_100100_enable_row_level_security.php`; verified live in `pg_policies` for all 37 RLS tables.)*

## 10. Database Roles

`database/security/roles.sql` (cluster-wide; non-transactional, run before the RLS migration):

| Role | Purpose | Privileges |
|---|---|---|
| `swasthya` (owner / migration) | Schema ownership, migrations, admin | superuser in dev (documented: production uses a scoped migration role with no runtime privileges) |
| `swasthya_app` (runtime) | The application connects as this role | LOGIN, **NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOREPLICATION, NOBYPASSRLS**; DML + sequence grants on every public table (with `ALTER DEFAULT PRIVILEGES` so future migrations need no grant bumps); CONNECT + USAGE |

The runtime role **cannot**: own tables, run DDL, bypass RLS, read outside its GUC context, or modify/delete audit rows. A future read/reporting role is documented but not created (no justified need yet — `SECURITY.md` §14).

## 11. Runtime Database Permissions

- Grants are DML-only per table; sequences get USAGE/SELECT.
- `ALTER DEFAULT PRIVILEGES FOR ROLE <owner>` keeps the app role grantable for tables created by future phases — no per-migration grant bumps.
- The app role's RLS behavior is tested directly (`DatabaseRowLevelSecurityTest` connects as `swasthya_app` on a dedicated `pgsql_rls` connection and asserts `rolbypassrls=false`, `rolsuper=false`).

## 12. Authentication

Unchanged from Phase 3 (verified working under RLS): argon2id password hashes, short-lived Sanctum access tokens (hashed at rest, revoked on logout), rotating refresh tokens with reuse detection and family revocation, per-account lockout, per-IP auth throttle, every auth event audited. **V2 change:** login/refresh (public routes) resolve the principal's own assignments and facilities inside a user-scoped transaction that sets `app.user_id` — the RLS policy on `role_assignments` reveals exactly the principal's own rows, so the login payload (facility picker) works before any tenant context exists.

## 13. RBAC

Unchanged from Phase 3 (verified under RLS): seeded roles/permissions with scope types (`platform` / `organization` / `facility` / `branch`), `authorize:` gate on every route, live permission checks, matrix-tested per role × action, grant/revoke audited, role changes take effect immediately. The `RolePermissionMatrixTest` was updated for the new platform-scope semantics (platform permissions confined to platform routes).

## 14. Platform Administration

- Platform admins (`superadmin`, platform scope) **manage the platform**: provisioning (`POST /organizations/{org}/provision`), platform role assignment (`PlatformAssignmentController`), support-session management (`PlatformSupportController`).
- Platform-scope permissions are restricted to the `api/v1/platform/*` namespace; on any other route a platform principal without a support session runs with an **empty tenant GUC** — the database returns nothing.
- Platform admins get **no automatic access to patient/business data**.

## 15. Privileged Support Access

`PlatformSupportController` + `support_sessions` table:

- **Explicit:** opening a session requires the target organization (and optional facility), a **required reason**, and a duration (default 60 minutes).
- **Authenticated + authorized:** only a principal holding a platform-scope role may open/close sessions (audited).
- **Time-limited:** `expires_at`; an expired session is `expired` and grants nothing.
- **Audited + attributable:** every operation during a session records `support_session_id` and the reason into `audit_events`; opening/closing/expiring sessions are themselves audited.
- **Least privilege:** during a session the principal acts as `support_agent` (read-only in the tenant).
- No `bypass_all_tenants` flag, no permanent backdoor, no "view everything" SQL path.

## 16. Audit Architecture

- Every mutation writes a contextual `audit_events` row: actor (user id + email), tenant, facility, action, resource type + id, correlation id, IP, payload (facts and references — never PHI), hash-chained (serialized by an advisory lock), append-only.
- **Under RLS:** `audit_events` has a SELECT policy split platform vs tenant rows and **no UPDATE/DELETE policy** — the application role cannot modify or erase history even with table-level grants (tested: update/delete → 0 rows). Audit access itself requires `audit:view`; there is no edit/delete path in the API.
- Support sessions record privileged-access context; the RLS suite proves a tenant cannot read another tenant's (or the platform's) audit rows.

## 17. Background Job Isolation

- **No queues/jobs/notifications exist yet** (verified by inventory). The pattern is documented (`TENANCY.md` §4, V2 §12): every tenant-sensitive job carries its tenant/facility/branch context explicitly; per-job transaction with `set_config(..., is_local=true)`; no context inferred from global or mutable state. The `DatabaseTenantContext` helper is the single writer and is job-safe by construction (LOCAL GUCs die with the job's transaction).
- The two-connection concurrency test proves the mechanism works under genuinely independent connections (the same mechanism a worker pool uses).

## 18. API Security

- Every endpoint: `auth:sanctum` → `ResolveTenantContext` (tenant/facility/branch derived, GUCs set, one transaction) → `authorize:` permission gate → controller `AccessCheck` (resource ownership) → database RLS (engine-level backstop).
- **Middleware ordering fixed:** `ResolveTenantContext` now runs before Laravel's `SubstituteBindings`, so route-bound tenant-scoped models resolve inside the RLS context (this was the bug that 404'd every `/{model}` route under the app role).
- No existence leaks: unknown/out-of-scope resources → 404 for reads; in-scope-but-unauthorized → 403; facility/branch proposals outside scope → 403 `FACILITY_DENIED`/`BRANCH_DENIED`; malformed input → structured 422; expired/invalid tokens → 401.

## 19. Frontend Security

The frontend (React SPA) is designed but not built — **there is no frontend, so nothing in the browser can act as the security boundary**. The API contract for the future client is defined (`API_CONTRACTS.md`): `X-Swasthya-Facility` / `X-Swasthya-Branch` are proposals; the login payload lists only the principal's own assignments; context is echoed in every response envelope; authorization errors are structured; the backend and database remain authoritative.

## 20. Mobile Security

No frontend exists to verify mobile behavior; the design rules are recorded (`DESIGN_SYSTEM.md`): context switching must never trap the user in an invalid context after logout/expiry/revocation, cached data must not expose unauthorized resources, and errors must be clear. Backend rejection is authoritative (403/401) regardless of client state.

## 21. Migration Changes

| Migration | Change |
|---|---|
| `2026_08_11_100000_tenancy_v2_schema.php` | Permission `scope` values + new platform permissions; `branches` table; `support_sessions` table; `audit_events.support_session_id`; `role_assignments.branch_id` support; composite-FK support indexes |
| `2026_08_11_100100_enable_row_level_security.php` | RLS on all tenant tables + audit/assignments/support sessions (see §8–9); grants to `swasthya_app`; default privileges |
| `database/security/roles.sql` | `swasthya_app` role (run before the RLS migration — CREATE ROLE is non-transactional) |

- **Existing data preserved:** no tables dropped, no IDs regenerated, no audit history destroyed; `branch_id` is nullable (existing rows remain valid with NULL branch); `down()` drops policies and disables RLS (documented; the migration's `down` is safe to run in dev).
- **Migration safety:** every migration is tracked and applied against both dev and test databases; the RLS migration asserts the app role exists before proceeding.

## 22. Performance Analysis

- RLS policies reference `tenant_id`, `facility_id`, `branch_id` — all columns already indexed (primary keys + composite FK support indexes + partial uniques). Policies are simple equality/`IS NULL` predicates against `current_setting`, index-usable; no full-table-scan patterns introduced.
- The `facilities` authorization join is an `EXISTS` on `role_assignments` (itself filtered by `user_id = app.user_id`) — bounded by the principal's own rows.
- No premature optimization: no new indexes were added beyond what the FKs/constraints require. A full load test remains a CI/load-testing item (`TESTING_STRATEGY.md`); this pass makes no performance claims beyond "no new scan patterns".

## 23. Security Test Results

| Area | Tests |
|---|---|
| Authentication | unauth → 401, expired token → 401, revoked session, malformed request → 422 (`WorkflowFailurePathTest`, `AuthTest`, `TenantIsolationTest`) |
| Organization | valid org, wrong org (403), manipulated org id (`OrganizationFacilityUserTest`, `TenantIsolationTest`) |
| Facility | authorized, unauthorized (403/404), facility proposal outside scope → 403 `FACILITY_DENIED` (live-probed) |
| Branch | authorized, unauthorized, branch proposal outside scope → 403 `BRANCH_DENIED` (live-probed; `BranchTest`) |
| RBAC | role × action matrix, missing permission, scope semantics (`RolePermissionMatrixTest`, `Phase4AuthorizationTest`, `PlatformAccessControlTest`) |
| Privileged access | no support session → no tenant data; valid session → support context; expired session; missing reason → 422; audited operations (`PlatformAccessControlTest`) |
| Audit | append-only at engine level; platform/tenant split; no edit/delete path (`DatabaseRowLevelSecurityTest`) |
| Concurrency | two independent connections, different tenants, no leakage (`DatabaseRowLevelSecurityTest`) |

## 24. RLS Test Results

`DatabaseRowLevelSecurityTest` (connects as `swasthya_app` — no RLS bypass):

- cross-tenant SELECT → invisible; UPDATE → 0 rows; DELETE → 0 rows (row untouched after)
- tenant escape via UPDATE `tenant_id` → rejected (WITH CHECK); facility escape → rejected
- facility isolation within a tenant (facility A cannot see facility A-2's patient; org-wide context sees all)
- branch isolation on branch-scoped catalogs (branch 1 sees its department; branch 2 does not; no-branch context sees all)
- no tenant context → zero access (safe failure)
- audit append-only + platform/tenant row split
- role_assignments: own-row resolution with only `app.user_id` (login) — **regression for the login bug**
- facilities authorization join: assigned facility resolvable pre-context, unassigned/cross-tenant facilities invisible — **regression for the login-picker bug**
- two concurrent connections with different tenants — no context leakage
- support sessions: owner or platform only
- middleware priority: `ResolveTenantContext` before `SubstituteBindings` — **regression for the binding bug**

## 25. Tenant Isolation Test Results

- `TenantIsolationTest`, `PatientIsolationTest`, `ClinicalIsolationTest`, `FacilityIsolationTest` — cross-tenant reads/writes denied at the application layer.
- `DatabaseRowLevelSecurityTest` — the same guarantees enforced at the engine (see §24).
- Live probes as `swasthya_app`: cross-facility proposal → 403, cross-branch proposal → 403, no token → 401, in-scope requests → 200.

## 26. OPD Regression Results

The complete workflow was re-run **live over HTTP as the least-privilege application role with RLS active**, with real database records:

| Step | Result |
|---|---|
| Login (assignments payload incl. facility name) | 200 ✓ |
| Patient registration (MRN issued) | 200 ✓ |
| Doctor availability (derived slots) | 200 ✓ |
| Appointment booking (idempotency key) | 200 ✓ |
| Check-in (queue token) | 200 ✓ (token 1) |
| Encounter start | 200 ✓ |
| Clinical note → signed | 200 ✓ |
| Diagnosis | 200 ✓ |
| Prescription (line item) | 200 ✓ |
| Encounter sign | 200 ✓ |
| Invoice (5000 consultation + 3000 medication = 8000 minor) | 200 ✓ |
| Payment (idempotent, captured; invoice → paid) | 200 ✓ |
| Audit trail (12 events, actor + facility) | ✓ |
| Cross-facility proposal | 403 FACILITY_DENIED ✓ |
| Cross-branch proposal | 403 BRANCH_DENIED ✓ |
| No token | 401 INVALID_TOKEN ✓ |

Full automated suite: **241 tests / 1,742 assertions green** (includes the RLS, branch, platform-access, and tenant-context suites).

## 27. Bugs Found

1. **Login returned `assignments: []` under RLS** — the public login route queried `role_assignments` with no `app.user_id` GUC, so RLS filtered everything (worked only because the server previously ran as the superuser).
2. **Login payload facility names were null under RLS** — `facilities` is tenant-scoped; the eager-loaded facility relation was invisible without a tenant GUC (chicken-and-egg with the pre-context facility picker).
3. **Every `/{model}` route returned 404 under RLS** — Laravel 11's `SubstituteBindings` (in the framework `api` group) ran before the route's `ResolveTenantContext`, so route-bound models were queried with empty GUCs. Feature tests (schema owner, RLS bypassed) never exercised this path.

## 28. Bugs Fixed

| # | Fix | Where |
|---|---|---|
| 1 | Login resolves its own assignments in a user-scoped transaction that sets `app.user_id` (`withUserDbContext`) | `AuthController` |
| 2 | `facilities` SELECT policy gains an authorization join (`EXISTS` on the principal's own active assignments) | RLS migration |
| 3 | `ResolveTenantContext` raised above `SubstituteBindings` in middleware priority (+ structural regression test) | `bootstrap/app.php`, `DatabaseRowLevelSecurityTest` |

All three were discovered through live verification as the app role — exactly the class of defect the hardening task exists to catch, and all three have regression tests.

## 29. Remaining Risks

1. **Production database roles not deployed.** The split (migration role vs `swasthya_app`) is implemented and tested locally; the production PostgreSQL deployment and role provisioning do not exist yet.
2. **No CI/CD.** The RLS leakage suite and the whole 241-test suite are a gate only if CI exists (`DEPLOYMENT.md`).
3. **No load test** of RLS under production-sized data (policy predicates are index-usable by construction; not yet measured).
4. **Frontend absent.** The SPA is designed but not built; mobile behavior cannot be verified until it ships.
5. **MFA, breach-list checking, secrets store** remain designed-not-implemented (`SECURITY.md`).
6. **Compliance** (privacy law 2075, clinical certification) is explicitly not claimed; legal counsel engagement pending.
7. **Dev DB holds a smoke organization** created through the real provisioning API during live verification (1 org, 1 facility, 4 users, 1 staff) — dev-only fixture, not production data.

## 30. Production Readiness

**READY FOR STAGING.**

Justification — the tenancy/database security layer is complete, engine-enforced, and tested:

- [x] Branch exists and is correct in the hierarchy (org → facility → branch → catalogs)
- [x] Organization, facility, and branch isolation work (application + engine, tested and live-probed)
- [x] PostgreSQL RLS exists on every tenant-owned table with tested policies
- [x] Application runtime does not use the DB superuser (`swasthya_app`, no `BYPASSRLS`)
- [x] Tenant context is server-controlled; client IDs are proposals only
- [x] Connection pooling cannot leak context (transaction-local GUCs + reset; two-connection test)
- [x] Platform admin cannot automatically access tenant clinical data (empty-GUC platform context)
- [x] Privileged support access is explicit, time-limited, reason-required, fully audited
- [x] Audit records are append-only at the engine level; no edit/delete path
- [x] API authorization remains active on every endpoint; RLS is the backstop, not a replacement
- [x] OPD workflow re-verified end-to-end live under RLS as the least-privilege role
- [x] 241 tests / 1,742 assertions green; RLS, branch, platform-access, isolation suites included
- [x] No fake data, no hardcoded tenant IDs, no unrelated modules

**Not yet PRODUCTION READY** because the surrounding deployment controls are absent: no CI/CD, no managed PostgreSQL deployment with the documented role split, no load test, no backup/restore drill validation, no MFA, no compliance assessment. Those are `DEPLOYMENT.md` / `ROADMAP.md` next steps — recorded, not silent gaps.
