# PROJECT_STATUS.md — Swasthya Nepal

> **Audit date:** 2026-08-15 · **Author:** lead engineering team (full-repository inspection + measured gates)
> **Method:** every claim below was verified from disk and/or by executing the gate it refers to. Nothing is inferred from documentation alone where a measurement was possible. "Documented" means the repo's design docs/development log state it; "Measured" means I ran it this session.

---

## 1. CURRENT STATE

Swasthya is a **multi-tenant Hospital Management System SaaS** built as a Laravel modular monolith (`backend/`) + React/TypeScript SPA (`frontend/`) + a Supabase-native Edge Function compatibility layer (`supabase/`, preserved in git under commit `5c08531`, **never deployed**) that re-implements the Laravel API read/write contract under GoTrue JWT → `request.jwt.claims` RLS.

What is real today, measured this session:

| Gate | Result (measured 2026-08-15) |
|---|---|
| Node edge-function harness | **855 passed / 0 failed** |
| Edge-function pipeline DB proof (`EdgeFunctionPipelineTest.php`) | **49 passed / 1,030 assertions** |
| Full backend Pest suite (real PostgreSQL) | **358 passed / 2,957 assertions** |
| Frontend unit tests (vitest) | **26 passed / 6 files** |
| Frontend `tsc -b --noEmit` | clean |
| Edge-function harness `tsc` | clean |
| Pint (backend style) | PASS — 308 files |
| `git diff --check` | CLEAN |
| Committed-secret scan (`git grep` private keys/tokens) | 0 matches |
| `_phase*` / temp artifacts | none |

**Git state:** `main` @ `bb4285c`; large uncommitted working tree (see §13). The **entire `supabase/` directory** (41 function adapters, 49 shared TS modules, 13,759-line harness) plus 5 backend support classes, 3 migrations, 6 test files are **untracked** — the Supabase-native migration work (Phases 2–45 of the edge layer) has never been committed.

**What the product covers:** a complete, tested **OPD vertical slice** — registration/MRN → schedule/booking (double-booking-proof) → check-in (race-safe tokens) → queue → encounter → structured clinical note → diagnosis → prescription → sign (immutable) → charges → invoice → idempotent payment — plus hospital-administration catalogs (departments, locations, wards/rooms/beds, staff, services, settings, payers, medications, schedule templates/exceptions) and the Patient Master (identifiers encrypted at rest, contacts, insurance, consents, documents-metadata, timeline). All tenant/facility/branch-isolated with PostgreSQL RLS as the final boundary (37 scoped tables, 144 policies, least-privilege `swasthya_app` NOBYPASSRLS role).

**What is NOT built:** IPD (admission/beds workflow), emergency, pharmacy dispensing, laboratory, radiology, inventory, HR/assets, OT/ICU/blood, analytics, interoperability, telehealth, RPM, CDSS/AI — i.e., the majority of the roadmap (Phases 8–22 of `ROADMAP.md`). Also not built: MFA, Redis/queues/notifications, object storage, payment-gateway integration, monitoring/alerting, tenant lifecycle/entitlements, and any deployed environment.

**Target-customer gap (explicit):** the stated first customer is a **100-bed hospital, 24/7, with departments, pharmacy, lab, billing, ~500 interactions/day**. The IPD, pharmacy, and laboratory surfaces that hospital runs on **do not exist yet**. The current system proves an OPD-only pilot, not that hospital.

---

## 2. COMPLETED AND VERIFIED

Measured or documented-with-evidence, in `DEVELOPMENT_LOG.md`:

- **Backend foundation (Phase 2):** Laravel API, envelope/error/middleware stack, health endpoints, validation, testing foundation on real PostgreSQL 16 (dev) / 17.6 (Supabase live probe).
- **Identity & tenancy (Phase 3 + Tenancy V2):** organizations → facilities → branches → departments; Sanctum access tokens + rotating refresh tokens with reuse detection; account lockout; RBAC (10 seeded roles) with live permission checks; per-request tenant/facility/branch context via transaction-local GUCs; audited platform support sessions; tenant-safe composite FKs.
- **PostgreSQL RLS (verified live on Supabase 2026-08-12, re-verified by tests, hardening applied 2026-08-15):** 144 policies, **`FORCE ROW LEVEL SECURITY` now actually applied** to all 37 tenant tables (migration `2026_08_15_100000_force_row_level_security` — previously documented but not implemented), `swasthya_app` non-owner NOBYPASSRLS runtime role, migration enforces the on/off matrix on any host; re-keyed to `request.jwt.claims` (Supabase-native) by migration `2026_08_13_100200_rekey_rls_to_jwt_claims.php`. Verified: `relforcerowsecurity=true` on 37, `rowsecurity=true` on 37, 144 policies, owner-binding semantics proven with a non-superuser owner.
- **Clinical/billing spine (Phases 6–7):** the full OPD journey above, with guarded state transitions, row-locked token issuance, idempotent financial ops, signed-record immutability — covered by dedicated failure-path and E2E tests.
- **Audit:** append-only, hash-chained `audit_events`; tamper-evidence tests.
- **Encryption at rest (app-layer):** staff license numbers and patient identifier values AES-256-GCM.
- **Frontend:** React SPA for the OPD workflow; Playwright e2e (desktop OPD, mobile receptionist, axe a11y) — CI-run per `DEVELOPMENT_LOG` (real-runner CI green 2026-08-12).
- **CI:** `.github/workflows/ci.yml` — backend (PHP 8.2/8.3 matrix, Pint, disposable postgres:16, full Pest + RLS suites) + frontend (typecheck, unit, build, e2e). Ran green on a real GitHub runner (log 2026-08-12).
- **Backup/restore drill:** `backend/ci/backup-restore-drill.sh` executed once (real `pg_dump` → restore into clean DB → verify schema/data/RLS/role/isolation).
- **RLS load benchmark:** `backend/ci/load-benchmark.sh` — synthetic 1M-patient workload, tenant-scoped queries under RLS vs owner baseline (recorded in `STAGING_READINESS_REPORT.md`).
- **Supabase edge layer (Phases 2–45, local-only):** 41 thin Deno adapters + 49 pure TS handlers + 13,759-line Node harness proving authn → authz → claims → RLS behavior; each endpoint has a real-PostgreSQL proof in `EdgeFunctionPipelineTest.php` (49 proofs, 1,030 assertions). **Simulated — never deployed.**

---

## 3. IMPLEMENTED BUT NOT SUFFICIENTLY TESTED

- **Supabase Edge Function layer:** functionally proven against the disposable cluster + Node harness, but the Deno adapters have **never executed in a real Deno/Supabase runtime**; `supabase/` has **no `config.toml`/deno.json** and is untracked. No GoTrue instance, no live JWT issuance, no live `request.jwt.claims` GUC wiring has been exercised.
- **RLS re-key to `request.jwt.claims`:** tested via `ClaimsBasedRlsTest` + pipeline proofs locally; the live-Supabase re-run after the re-key migration is not recorded.
- **Staging blueprint (`render.yaml` + Dockerfile + `SUPABASE_STAGING.md` runbook):** written and CI-tested locally; the Render provisioning was halted at the user's OAuth-link/billing boundary and **never deployed**.
- **Frontend e2e/a11y:** written and CI-green, but not re-run this session (requires dev servers); counts not re-measured here.
- **Support-session and platform-role surfaces:** implemented with tests, but no operational load/review.

---

## 4. PARTIALLY IMPLEMENTED

- **Tenancy V2 → Supabase-native migration:** 2 of 3 wiring layers done (re-key migration, identity bridge support classes, edge pipeline harness). The **third layer — actually running on Supabase** — is not done.
- **Identity bridge:** `AuthClaims`, `JwtClaims`, `IdentityProvisioner`, `CredentialMigration` exist and are tested; the `auth_subject_id` bind + rebind-guard migrations exist; nothing routes through them yet (Sanctum is still the live auth).
- **SaaS tenant lifecycle:** org provisioning endpoint + support sessions + platform role gates exist; **no subscription/plan/entitlement/status state machine** (ACTIVE/TRIAL/PAST_DUE/SUSPENDED/CANCELLED) is implemented (`BILLING.md` is design-only).
- **Scheduling:** templates/exceptions + derived availability + booking are complete; there is no calendar UI beyond booking, and no recurring-template management UI.
- **Billing:** charges/invoices/payments are complete and idempotent **in-app**; no gateway, no receipts/export, no refund/void workflow, no insurance claim flow.
- **Patient documents:** metadata-only by explicit design (no object storage yet); `objectKey` deliberately not presented.
- **Staging:** `STAGING.md`/`RENDER_STAGING.md`/`SUPABASE_STAGING.md`/`STAGING_*_REPORT.md` exist; the environment does not exist.
- **`docs/`:** a generated HTML dashboard (`build.py` + `index.html`) — static preview, not a product surface.

---

## 5. MISSING

**Product (roadmap Phases 8–22, none implemented):** IPD/admissions, bed-assignment workflow, emergency, pharmacy (stock/dispensing), laboratory, radiology, inventory/procurement, HR/assets, OT/ICU/blood bank, analytics/reporting, interoperability (FHIR/HL7/DICOM), telehealth, RPM, CDSS/AI.

**Platform/ops:**
- MFA TOTP (only a `mfa_readiness` column exists) — a **[REQUIRED]** control in `SECURITY.md` for staff before go-live.
- Redis (cache/queues/realtime) — not configured (app uses `database` cache/queue drivers); **no Jobs, no Notifications, nothing is dispatched**.
- Object storage (S3-compatible) + signed URLs + file scanning — not implemented.
- Email/SMS/push delivery — not implemented (no Notifications, no adapters, no providers).
- Payment-gateway integration — not implemented.
- Monitoring/alerting/error tracking (Sentry-class), dashboards, synthetic checks — `OBSERVABILITY.md` is design-only.
- Tenant lifecycle management (plans, subscriptions, suspension behavior, export, purge) — designed only.
- Rate limiting beyond auth endpoints — only `throttle:auth` on login/refresh.
- Breached-password checking, passkeys, step-up auth, CSP enforcement detail — designed only.

**Delivery:** any deployed environment (no live staging/production); automated/managed backups (PITR needs a paid Supabase plan — billing decision pending); a full-stack load test; a real restore of a *managed* database; a compliance assessment / penetration test (none claimed anywhere — correctly).

---

## 6. BROKEN

- **Nothing fails its gates.** All measured gates pass (harness 855/855, pipeline 49/49, backend 358/2957, frontend 26/26, tsc, Pint, diff-check).
- **Operational irritant (not a code bug):** `backend/storage/logs/laravel.log` grows until `LoggingTest` (which reads it in full) exhausts PHP's 128 MB test memory — the documented remedy (clear the log) was applied again this session; CI is unaffected because checkouts are fresh. It is a standing local-dev footgun, not a production defect.
- **Terminal tooling:** the configured shell (`C:\Program Files\Git\bin\bash.exe`) could not be spawned for several sessions (environmental); restored/working at audit time. Environmental, not repository.

---

## 7. SECURITY RISKS

1. **Edge-function migration is committed but undeployed** — preserved under commit `5c08531` (92 supabase files) but **never deployed**; the native Supabase runtime path remains simulated. Two RLS-era support models (GUC vs claims) exist in code; the claims path is the live one and the GUC path is the compatibility bridge.
2. **MFA is not implemented** while `SECURITY.md` marks it **[REQUIRED]** for staff before any production operation — a release blocker for the stated go-live.
3. **Rate limiting covers only auth endpoints.** The 500-interactions/day hospital with concurrent staff (and the public-facing surfaces planned) has no per-route/per-account limits on most reads/writes.
4. **No monitoring/alerting:** a breach, a cross-tenant probe, or an anomaly spike (which the audit pipeline is *designed* to alert on) has no detection path today.
5. **No object storage means no file-scanning/signed-URL story** — when documents become real, the crypto boundary is still unwritten.
6. **RLS INSERT is deliberately permissive** (`WITH CHECK true`) — the documented isolation guarantee is READ/UPDATE/DELETE; any future code path that sets `tenant_id` from client input would slip through INSERT. Mitigated today by app-layer derivation, but the policy is load-bearing.
7. **`audit_events` and `facilities` special policies** (authorization joins) are more complex than the standard matrix — they are tested, but they are the most likely place a future edit breaks isolation.
8. **Session/token surface:** access tokens in memory, refresh in httpOnly cookie — implemented correctly; no MFA gate on refresh of sensitive scopes yet (designed).
9. **`docs/` and logs:** `storage/logs/laravel.log` contains request/audit-adjacent data locally (no PHI per the no-PHI logging rule — verified by LoggingTest); not shipped, but log hygiene needs a rotation policy before any host.

---

## 8. SCALABILITY RISKS

1. **No measured full-stack capacity.** The only load evidence is the RLS query benchmark (synthetic 1M rows); there is **no request-level load test** (the target ~500 interactions/day with 25–200 concurrent users has never been exercised). Claiming the 100-bed/500-interaction target is safe on the current evidence would be fabrication.

**Tenancy hardening (2026-08-15, Program Phase 1 close-out):** deliberate cross-tenant attack suites added at the API layer (`CrossTenantApiAttackTest`, 8 tests) and the database layer (`TenancyDatabaseInventoryTest`, 5 tests — all 37 tables, two-sided, under `swasthya_app`); FORCE RLS applied to the 37 tables; `organizations/{org}/patients` contract fixed (URL org now gated like every sibling read — was returning the caller's own list for any selector; no leak existed, now consistent). Isolation between tenants is PROVEN at both layers; findings and the local-superuser nuance are recorded in `SECURITY_AUDIT.md`.
2. **Queue/cache are the `database` drivers with no workers and no jobs** — the moment notifications/reports/exports exist, they will block request handling; Redis design is documented but not built.
3. **No connection pooling** in front of PostgreSQL (PgBouncer is designed, not deployed); session-mode pooling is a stated production requirement.
4. **No read replicas, no partitioning, no PgBouncer** — all designed, none present.
5. **RLS GUC-per-transaction model** imposes per-request context setup; the 1M-row benchmark suggests acceptable overhead at that scale, but this must be re-measured on the real workload.
6. **Single deployable monolith** — by design (correct for this stage); scale paths are documented, not exercised.
7. **Unbounded local log growth** (see §6) is a small but real ops hygiene issue.

---

## 9. PRODUCTION RISKS

1. **Nothing is deployed.** No environment, no TLS-terminated host, no DNS, no managed DB/Redis/object storage, no secrets store in use. The Render/Supabase blueprint is unprovisioned (waiting on user OAuth link + Supabase paid-plan decision + secret entry).
2. **Backups exist only as a local drill.** No automated/managed backups, no PITR, no retention policy, no cross-region copy; `DISASTER_RECOVERY.md` targets (RPO ≤ 15 min / RTO ≤ 4 h) are **targets, not guarantees** — correctly stated.
3. **`APP_KEY`/secrets hygiene is designed, not operated:** no secrets store; env templates are placeholder-only and clean, but the operational rotation/leak-revocation runbook is unwritten.
4. **Clinical-safety for the *target* hospital is unmet** because IPD/pharmacy/lab (where most safety-critical workflows live: medication administration, dispensing, results verification) do not exist.
5. **No on-call/incident-response runbook, no DR runbook for a live environment** (`SECURITY.md` §30–31 require both).
6. **The PHP test-memory/log-growth footgun** would also bite any long-running local/CI process that accumulates logs before `LoggingTest`.

---

## 10. DATA-ISOLATION RISKS

The isolation model is the strongest part of this codebase — but it is not risk-free:

1. **Facility/branch isolation is application-policy, not RLS**, for all tenant-owned data (only tenant is hard-enforced at the DB). A facility-scoped role's queries rely on the policy layer being correct; the RLS facility clause only applies to the 14 TENANT_FACILITY and 5 TENANT_FACILITY_BRANCH tables, and even there an org-level context legitimately sees all facilities — the hard wall is tenant only. This matches the documented design, but the stated customer's "hospital/facility/branch isolation" expectation is softer at the DB than the tenant wall.
2. **TENANT_ONLY tables** (payers, identifiers, contacts, consents, documents, diagnoses, notes, prescriptions, invoice lines, payment allocations) have **no facility clause at all** — facility isolation for those is *entirely* the application layer.
3. **`request.jwt.claims` re-key is proven locally only** — the live-Supabase behavior of the untracked re-key migration is not re-verified post-change.
4. **INSERT-permissive RLS** (see §7.6) — the write-side of the DB backstop is weaker than the read-side by design.
5. **Cross-tenant tests are extensive and green** (TenantIsolation, ClinicalIsolation, PatientIsolation, FacilityIsolation, BranchIsolation, ClaimsBasedRls, EdgeFunctionPipeline proofs) — this is the *mitigation*, and it is real; the residual risks above are design characteristics, not observed leaks.

---

## 11. DEPLOYMENT RISKS

1. **Blueprint unprovisioned:** `render.yaml` + Dockerfile + entrypoint exist; provisioning needs the user's Render OAuth link, the Supabase paid-plan decision (backups/PITR), and Dashboard secret entry. Any of these pending = no environment.
2. **First real deploy is untested end-to-end:** the predeploy bootstrap (roles.sql → migrate --force → grants.sql) was exercised against live Supabase once in a read-only fashion; the *deploy path itself* has never run.
3. **Session-pooler constraints** (session mode, prepared statements, single `DB_PASSWORD` invariant, `<role>.<project-ref>` username) are documented and probed but never operated under load.
4. **No rolling-deploy/rollback rehearsal** on a real host; zero-downtime is a design, not a demonstrated capability.
5. **Migration risk:** forward-only discipline is documented; the only production-adjacent migration run was manual `migrate:rollback --step=1` on Supabase (drops policies only, verified).
6. **CI real-runner green is recorded (2026-08-12)** — good; but the untracked working tree means CI today tests *committed* code that is **behind** the actual edge-function work.

---

## 12. BACKUP / DISASTER-RECOVERY RISKS

1. **One local drill exists and passed** (pg_dump → restore → verify schema/data/RLS/role/isolation). That proves the *procedure* on the dev cluster.
2. **No managed/automated backups, no WAL/PITR** (requires paid Supabase — decision pending), no retention policy, no off-site/cross-region copy, no monitoring of backup success/failure.
3. **No DR for Redis/object storage** (they don't exist yet — so no risk today, but the DR plan assumes them).
4. **RPO/RTO targets are unvalidated** for any real environment; quarterly restore drills and annual failover tests are documented requirements with zero executions.
5. **`DISASTER_RECOVERY.md` is a design document** — the repository's honest position is "targets, not guarantees," and that remains true.

---

## 13. WHAT MUST BE FIXED BEFORE FIRST HOSPITAL

**Release blockers (evidence-based):**
1. **Commit/preserve the uncommitted Supabase migration work** (`supabase/` + the 5 support classes, 3 migrations, 6 test files) — a working tree is not a deliverable.
2. **Build the target hospital's clinical surfaces: IPD (admission → bed assignment → nursing → rounds → orders → discharge), pharmacy (dispensing + inventory), laboratory (order → result → verify → report)** — the stated customer cannot run on OPD alone. This is the biggest gap by far.
3. **MFA TOTP for all staff/admins** ([REQUIRED], `SECURITY.md` §3) with enrollment/recovery/audit.
4. **Object storage** for patient documents with signed/expiring audited URLs, tenant-prefixed keys, upload limits/MIME validation — replacing the metadata-only placeholder.
5. **Deploy a real staging environment** (Render + Supabase per blueprint) and prove the bootstrap, RLS, OPD journey, and backups on it.
6. **Automated managed backups + PITR + retention + a real restore test on the managed DB** (paid Supabase decision).
7. **Monitoring/alerting for app + DB + auth anomalies** (cross-tenant probe detection, 5xx, backup failure, job failure) — even minimal (error tracking + uptime + backup alerts) before any real tenant.
8. **Full-stack load test at the target profile** (25/50/100/200 concurrent; ~500 interactions/day peak-hour profile) with measured p50/p95/p99 — no capacity claims without it.
9. **Rate limiting beyond auth endpoints** (per-route/per-account, Redis-backed once Redis exists).
10. **Secrets store + rotation/incident runbook + no-credentials-on-dev-machines** enforcement in the deployed env.
11. **Penetration test / independent security review** before real patient data (positioned-for-assessment is not assessed).
12. **Log rotation** (the 16 MB local log that breaks `LoggingTest` will break a host too).

**Strongly advised before first hospital (not strictly blockers):**
13. Tenant status/lifecycle model (suspend/past-due behavior — "never delete data on subscription end" is already the documented rule).
14. Email/SMS notification delivery (reminders, results — designed, not built).
15. In-app payment capture exists; a real payment gateway is a business decision but will be needed.
16. `DEVELOPMENT_LOG.md` is stale (last entry 2026-08-12) — the entire edge-function phase program (Phases 2–45) is unrecorded there; re-anchor it.

---

## 14. WHAT CAN WAIT UNTIL LATER

- Telehealth, RPM, CDSS/AI, interoperability (FHIR/HL7/DICOM), national-scale features — roadmap Phases 13–22; the architecture documents their arrival paths.
- Analytics/reporting beyond the OPD read surfaces (star-schema/marts designed, not needed for first hospital day-to-day beyond basic lists).
- Read replicas / partitioning / PgBouncer / Octane — scale stages, gated on measured triggers (`ARCHITECTURE.md` §26); do not build now.
- Kubernetes/microservices — explicitly deferred unless measured need (`ARCHITECTURE.md` §24, §28).
- Second-region DR, active-active — documented future.
- Schema-per-tenant escalation — documented enterprise option; not for the first hospital.
- Passkeys/WebAuthn, step-up auth, ABAC, DLP, SBOM — recommended/future tiers.
- Patient portal beyond the shared SPA's role-gated routes — same codebase, gated on consent/scope work.

---

## 15. FINAL PRODUCTION READINESS SCORE

**25 / 100** — for the stated first customer (100-bed, 24/7, IPD+pharmacy+lab, ~500 interactions/day).

Scoring basis (do not read the number without the breakdown):

| Dimension | Weight | Score | Why |
|---|---|---|---|
| Foundation & tenancy (RLS, auth, RBAC, audit) | 25% | 85% | Real, tested, partially verified live; MFA missing, insert-policy weaker |
| Target clinical surface (OPD ✓; IPD/pharmacy/lab ✗) | 25% | 20% | Only the OPD vertical exists; the hospital's IPD/pharmacy/lab workflows are absent |
| Data & integration (files, queue, notifications, payments) | 15% | 5% | Object storage/Redis/queues/notifications/gateway all designed-only |
| Ops (deploy, backups, monitoring, DR) | 20% | 5% | No deployed env, no managed backups, no monitoring, local drill only |
| Verification (tests, load, security assessment) | 15% | 30% | Excellent unit/integration/RLS coverage; **no full-stack load test**, no pentest, no compliance assessment |

Overall ≈ **25%**. This is not a statement that the foundation is weak — it is strong and unusually well-tested. It is a statement that **the product surface for the target hospital and the operational shell around it are mostly missing**. Any claim above ~30% would require the missing surfaces, a deployed environment with managed backups/monitoring, and measured load — none of which exist today.
