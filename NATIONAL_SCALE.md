# NATIONAL_SCALE.md — Phase 22 National Scale: Measured Evidence Register

> **Status:** Phase 22 evidence register (ROADMAP Phase 22, milestone M5).
> **Owner:** Principal Architect.
> **Honesty rule (read first):** Phase 22's acceptance criteria are
> *"SLOs met at national load (measured, not claimed); restore/failover
> drills green with recorded evidence; compliance claims made only with
> documented verification; national integrations live and contract-tested."*
> Everything in this document is either **measured on the disposable local
> cluster** (raw logs in `docs/national-scale/`) or explicitly marked
> **NOT PROVEN**. Nothing here claims production compliance, capacity,
> availability, or live integrations without evidence.

---

## 0. What Phase 22 delivers

Per `ROADMAP.md` §23, the national-scale commitment is: **availability,
capacity, resilience, localization, national integrations, and verified
compliance** — with measured evidence for every claim. This register records:

| Track | Deliverable | Status |
|---|---|---|
| 1. Measured capacity | `backend/ci/load-benchmark.sh` at 1M patients (~2.9M rows) | **MEASURED** — see §1 |
| 2. Multi-region / failover readiness | `backend/ci/failover-drill.sh` + runbook | **MEASURED (single env)** — production cutover **NOT PROVEN** |
| 3. Restore / DR drills | `backend/ci/backup-restore-drill.sh` at 1M rows | **MEASURED** — see §2 |
| 4. Localization | Nepali/English i18n + Devanagari rendering | **IMPLEMENTED + TESTED** — see §3 |
| 5. National integrations | Registry readiness (Slice 23); none live | **NOT PRESENT** — no fake claims (§4) |
| 6. Compliance | `LEGAL_COMPLIANCE_ASSESSMENT.md` | **DOCUMENTED** — legal review **NOT PROVEN** (§5) |

**Tooling fixes landed in this phase (evidence integrity):** the RLS
benchmark previously set the legacy `app.*` GUCs while policies read
`request.jwt.claims` (rekey `2026_08_13_100200`) — RLS-mode queries
silently returned **zero rows** ("Index Scan … never executed") and the
timings were invalid. Both `load-benchmark.sh` and `backup-restore-drill.sh`
now set the canonical claims payload (`AuthClaims::fromContext` shape,
`TENANCY.md` §7), and the drill's isolation probe uses one patient's own
tenant/facility. Evidence below is from the corrected tooling.

---

## 1. Measured national-capacity load evidence

**Environment:** disposable local PostgreSQL cluster (`.toolchain/pgdata`,
port 54329), synthetic `swasthya_load` database. No production or
patient-derived data. Dataset: 20 orgs × 2 facilities; per row n,
tenant = n%20, facility = n%40.

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
| Database size | **1,235 MB** |

**Re-verified at the current schema (2026-08-17, raw log
`docs/national-scale/load-benchmark-1M-current-schema-2026-08-17.log`):**
the load database was migrated to the current schema (Phases 20–21 added
`rpm_*`, `cdss_*`, `ai_*` — **508 policies**, 1,000,000 patients preserved)
and the 20 benchmark statements re-run in RLS mode with the canonical
claims payload. All 20 statements execute real rows (0 "never executed").
Point lookups 0.19–0.42 ms (RLS folds into the index cond — Q1
patient-by-id: `Index Scan uq_patients_tenant_id_id`, 0.055 ms); the
tenant-scoped name search remains the documented hot spot at **142–187 ms**
(warm/cold variance around the original 147–158 ms — same order, same
plan shape); inserts/updates unchanged. The capacity result is unchanged
by the +5 Phase 20–21 tables: the benchmark queries target the Phase-5/7
hot tables and the new tables are not on any measured path.

**Method:** `backend/ci/load-benchmark.sh 1000000` — 20 benchmark statements
run warm under RLS (`swasthya_app`, canonical `request.jwt.claims`) vs a
controlled owner baseline (RLS disabled, re-enabled immediately; real
application databases never touched). Raw log:
`docs/national-scale/load-benchmark-1M-claims-2026-08-17.log`.

**Results (RLS vs baseline, ms):**

| Statement | RLS (ms) | Baseline (ms) | Delta |
|---|---|---|---|
| Patient by id (cold) | 0.31 | 6.22 | −5.91 |
| Patient by id (warm) | 0.29 | 0.23 | ~0 |
| Name search ILIKE (cold) | 158.05 | 0.16 | +157.9 |
| Name search ILIKE (warm) | 147.39 | 11.34 | +136.0 |
| Appointment by id | 4.89 | 9.46 | −4.57 |
| Encounters for patient | 0.27 | 3.97 | −3.70 |
| Invoices for patient | 6.60 | 0.16 | +6.44 |
| Provider-day schedule | 0.27 | 3.40 | −3.13 |
| Invoice + lines join | 3.06 | 0.23 | +2.82 |
| Insert patient ×3 | 0.22–0.49 | 0.14–0.31 | ~0 |
| Update patient | 3.28 | 0.67 | +2.62 |
| Delete ×3 | 86.54 | 0.85 | +85.7 |

**Interpretation (measured):**

- **Point lookups fold RLS into the index conds:** patient-by-id 0.29 ms,
  provider-day schedule 0.27 ms, encounter list 0.27 ms → ≈3,000–4,000 qps
  per connection at 1M patients. The index-cond folding is visible in the
  raw explains (`Index Scan … rows=1 actual time=0.02`).
- **Hot spot — tenant-scoped name search ≈147–158 ms:** the facility-scope
  policy's `(facility_id = GUC OR GUC IS NULL)` predicate defeats a
  facility-prefixed index; the planner bitmaps the tenant's ~50k rows and
  filters (277 matched, 20 returned in the probe). This is the documented
  known hot spot (`STAGING_READINESS_REPORT.md` §9) — no blind index was
  added. Facility-required contexts (dropping the OR-NULL) would enable a
  facility-prefixed trgm index; that is a policy-shape decision, not this
  phase's change.
- **Delete ≈86 ms under RLS** reflects the WITH CHECK re-evaluation of the
  policy on the target row — correct and bounded; the workload's delete
  volume is small.
- **Error rate 0** in both modes; all 20 statements completed (`BENCH_EXIT=0`).
- **Correctness under contention** is proven by the backend CAS suites
  (exactly-one-winner races: bed assignment, refund approval, MAR
  administration, stock deduction, video-session transitions) — not by
  throughput alone (`TESTING_STRATEGY.md` §3.13).

**SLO framing (honest):** the roadmap demands SLOs *met at national load*.
This register records the per-query latency envelope on the reference
single-node dataset. Production SLO targets and their verification against
real cloud infrastructure remain a deployment-phase commitment
(**NOT PROVEN** here — see §6).

---

## 2. Restore / DR drill evidence (national scale)

**Drill:** `DRILL_SRC_DB=swasthya_load bash ci/backup-restore-drill.sh` —
real `pg_dump -Fc` of the 1,235 MB synthetic DB, restore into a clean
database, roles/grants fixup, and verification.

| Metric | Value |
|---|---|
| Backup duration | **34 s** |
| Backup size | **152,282,293 bytes** |
| Restore duration | **104 s** (+ role/grants fixup) |
| Total drill | **140 s** |

**Verified after restore (all green):**

- Schema: **135 base tables**; migrations table: **97 rows**.
- Data: **1,000,000 patients / 500,000 appointments** restored intact.
- RLS: **476 policies source = 476 restored**; `patients`/`audit_events`
  `relrowsecurity=true`.
- Role: `swasthya_app bypass=false super=false`.
- **Isolation re-verified on the restored data** as `swasthya_app` with the
  canonical claims payload: with context **1**, without context **0**, wrong
  tenant **0**.

**RPO/RTO (measured, this environment):**

- **RTO measured:** 104 s restore + role re-creation — this environment,
  not a production claim.
- **RPO:** the local cluster has no WAL archiving (on-demand backups);
  production PITR posture (RPO ≤ 15 min per `DISASTER_RECOVERY.md` §1) is a
  deployment-phase commitment — **NOT PROVEN** here.

**Re-verified at the current schema (2026-08-17, raw log
`docs/national-scale/restore-drill-1M-current-schema-2026-08-17.log`):**
the drill was re-run with the current-schema 1M-row source
(`swasthya_load` migrated to **508 policies**). Restore: backup **33 s** /
restore **110 s** / total **144 s**. Verified on the restored database:
**143 base tables** restored, 1,000,000 patients / 500,000 appointments
intact, **508 policies source = 508 restored**, `patients`/`audit_events`
RLS on, `swasthya_app bypass=false super=false`, and **isolation
re-verified on the restored data: with context 1, without context 0,
wrong tenant 0**. The schema-level dev-DB drill (143 tables / 103
migrations / 508 = 508 policies) also passed; its row probes are skipped
on the empty dev dataset by design.

---

## 3. Failover-readiness drill evidence

**Drill:** `FAILOVER_STANDBY_DB=swasthya_restore_load bash
ci/failover-drill.sh` — simulates primary loss by switching the stateless
application to a pre-verified standby and serving real HTTP. Raw log:
`docs/national-scale/failover-drill-2026-08-17.log`.

| Step | Result |
|---|---|
| Standby preconditions | 135 tables, 1,000,000 patients, 476 policies (original); **143 tables, 508 policies (current-schema re-run, 2026-08-17 — raw log `docs/national-scale/failover-drill-1M-current-schema-2026-08-17.log`)** |
| Config switch + schema verify | **1 s** |
| `GET /api/v1/health/live` against standby | `{"status":"ok"}` |
| `GET /api/v1/health/ready` against standby | `{"checks":[{"name":"database","status":"ok"}]}` |
| RLS on standby (swasthya_app, claims) | with context **1**, wrong tenant **0** |

**Honest limits:** this proves the application serves from a standby
database in one environment with RLS intact. A production multi-region
cutover — WAL promotion, read replicas, DNS/edge switch, health-gated
traffic shift (`DEPLOYMENT.md` §8, `DISASTER_RECOVERY.md` §7) — requires
real infrastructure and the annual failover exercise; it is
**NOT PROVEN** and is not simulated here.

---

## 4. Localization — Nepali/English + Devanagari rendering

Implemented (frontend, zero-dependency):

- `frontend/src/i18n/I18nProvider.tsx` — locale context, `t()`, persistence
  (`localStorage 'swasthya.locale'`), `document.documentElement.lang`
  sync; `useI18n()` falls back to English when unmounted so isolated
  component tests stay valid.
- `frontend/src/i18n/locales/en.ts` (34 keys) + `ne.ts` (full Devanagari
  mirror, key-for-key parity enforced by test).
- Wired into `App.tsx` (facility chooser, spinners), `AppShell.tsx`
  (nav labels, sign out, skip link, context switcher, language toggle),
  `LoginPage.tsx`, `main.tsx` (provider).
- `frontend/src/styles/tokens.css` — `html[lang='ne']` Devanagari-first
  font stacks (`Noto Sans Devanagari` leads `--font-body`/`--font-display`).
- `frontend/scripts/verify-devanagari.mjs` — static gate reading the shipped
  tokens.css (Vitest stubs CSS, so the real file is checked at gate time).

**Proof:**

- Vitest: **32/32 passed** (26 baseline + 6 new: 4 provider + 2
  localized-shell). New tests prove: toggle, Devanagari rendering, `lang`
  attribute, persistence + restore, catalog parity (no silent English
  fallback in Nepali), and the real shell switching नेपाली ⇄ English.
- `node scripts/verify-devanagari.mjs`: **DEVANAGARI GATE PASS**.
- TypeScript: **PASS** (`tsc -b --noEmit`).

**Scope note (honest):** localized surface = app shell, login, facility
chooser, shared chrome, and nav. Full page-content localization (patients,
appointments, queue, billing, audit, encounters) is incremental
localization work, not part of this slice.

---

## 5. National integrations

The roadmap permits national integrations *"only when they exist and are
specified."* No national system is specified in this repository.

- **NOT PRESENT** — no live national integration exists, and none is
  simulated, stubbed, or claimed.
- The readiness layer from Slice 23 (Interoperability) stands:
  `integrations` registry, egress allowlist, OAuth2 partner surface,
  signed webhooks, FHIR R4 projections, HL7 mappers — all fixture-tested
  and consent-gated (`INTEROPERABILITY.md`).
- When a real national system is specified (e.g., a named national
  identity/health-number, e-prescription, or lab-exchange system), it
  becomes a contract-tested integration with recorded evidence — the
  registry already measures integration status.

---

## 6. Compliance / legal assessment

`LEGAL_COMPLIANCE_ASSESSMENT.md` records what is verified (technical
controls, with evidence) versus what requires legal review. **No
compliance claim is made** — Nepal-specific data-protection and health-data
compliance is **NOT PROVEN** pending legal assessment.

---

## 7. NOT PROVEN (Phase 22 standing commitments)

Recorded here so nothing in this document reads as a production claim:

1. **Production national-load capacity / SLOs** — measured on the local
   reference cluster only; production verification requires the deployment
   environment.
2. **Production multi-region failover / read-replica cutover** — single-
   environment readiness proven; annual failover drill on real
   infrastructure pending.
3. **WAL-archiving PITR posture (RPO ≤ 15 min)** — local cluster has no
   archiving; production PITR is a deployment-phase commitment.
4. **Compliance with applicable law (e.g., Nepal Privacy Act 2075, health-
   data rules)** — legal assessment not yet performed; no claims made.
5. **Live national integrations** — none specified; none claimed.

---

## 8. File inventory (Phase 22)

| File | Purpose |
|---|---|
| `frontend/src/i18n/I18nProvider.tsx` | Locale context/provider/hook |
| `frontend/src/i18n/locales/en.ts`, `ne.ts` | Catalogs (parity-enforced) |
| `frontend/src/i18n/I18nProvider.test.tsx`, `localized-shell.test.tsx` | Localization tests |
| `frontend/scripts/verify-devanagari.mjs` | Devanagari static gate |
| `frontend/src/main.tsx`, `App.tsx`, `layout/AppShell.tsx`, `pages/LoginPage.tsx` | Localization wiring |
| `frontend/src/styles/tokens.css`, `layout/shell.css` | Devanagari stacks + toggle styling |
| `backend/ci/load-benchmark.sh` | **Fixed** claims wiring (measurement integrity) |
| `backend/ci/backup-restore-drill.sh` | **Fixed** isolation probe + claims wiring |
| `backend/ci/failover-drill.sh` | New failover-readiness drill |
| `docs/national-scale/*.log` | Raw measured evidence (incl. `*-1M-current-schema-2026-08-17.log` re-verification logs) |
| `NATIONAL_SCALE.md`, `LEGAL_COMPLIANCE_ASSESSMENT.md` | Evidence register + compliance assessment |
| `DEPLOYMENT.md`, `DISASTER_RECOVERY.md`, `OBSERVABILITY.md`, `MASTER_RULES.md`, `INTEROPERABILITY.md`, `DEVELOPMENT_LOG.md` | Updated contracts/evidence |
| `frontend/src/pages/OperationsPage.tsx` | Operations Command Center (Phase 121) |
| `frontend/src/pages/operations.css` | Operations page styles (Phase 121) |

---

## 9. Phase 121 — Operations Command Center

**Commit:** `perf/ops: validate swasthya national scale readiness`

Added the Operations Command Center — a premium frontend workspace that
consolidates all infrastructure-readiness evidence into a single
operational dashboard.

### 9.1 System Health

- 8 health checks: Database, Cache, Queue, Storage, Auth/MFA, Realtime, FHIR, Service Worker
- Live/ready endpoint integration (`/health/live`, `/health/ready`)
- Check-all button with async status feedback

### 9.2 Capacity & Scale Evidence

- Scale targets with measured values from `backend/ci/load-benchmark.sh`
- Hot query performance table (all 20 benchmark statements)
- Known hot spot documented: tenant-scoped ILIKE name search at 147-158ms
- Point lookups at 0.29ms under RLS at 1M patients

### 9.3 Disaster Recovery & Backups

- RPO/RTO targets with measured drill results
- Backup configuration: base backup, WAL, cross-region, object storage
- Drill results: 144s restore (33s backup + 110s restore + role fixup)
- Failover drill: 1s config switch + HTTP check + RLS verify
- Run-restore-drill button for interactive verification

### 9.4 Observability

- Three pillars: structured logging, metrics (RED/USE), distributed tracing
- PHI-safe logging: no patient names, no secrets, no financial identifiers
- Structured log schema: 12 fields with correlation IDs
- Alert routing design (PagerDuty/Slack integration)

### 9.5 Incidents & Runbooks

- 4 severity levels (P1-P4) with response time targets
- 8 runbooks: Deployment, Rollback, Backup/Restore, Failover, Incident Response, Security Incident, Integration Failure, Capacity Alert
- Active incident tracking (currently none)

### 9.6 Security Ops

- Rate limits: 6 endpoint groups with per-IP and per-account throttling
- 8 security controls: RLS (508 policies), Auth throttle, CAS locking, IDOR protection, tenant isolation, MFA, password policy, audit trail

### 9.7 Honest Classification

| Capability | Status |
|---|---|
| Health checks (liveness + readiness) | ✅ IMPLEMENTED (backend) |
| Load testing (1M patients) | ✅ MEASURED (local cluster) |
| Backup/restore drill | ✅ MEASURED (144s at 1M rows) |
| Failover drill | ✅ MEASURED (single env) |
| Observability design | ✅ DOCUMENTED (21KB spec) |
| DR design | ✅ DOCUMENTED (22KB spec) |
| National scale ops dashboard | ✅ IMPLEMENTED (frontend) |
| Production SLOs | ⬜ NOT PROVEN (deployment-phase) |
| Production multi-region failover | ⬜ NOT PROVEN (annual drill pending) |
| Production PITR (RPO ≤ 15min) | ⬜ NOT PROVEN (WAL archiving pending) |
| Production monitoring stack | ⬜ DESIGNED (not deployed) |
| Live alert routing | ⬜ DESIGNED (not deployed) |
