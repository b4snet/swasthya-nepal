# DISASTER_RECOVERY.md — Swasthya Disaster Recovery Strategy

> **Status:** Working baseline · **Owner:** Principal Architect (posture ratified with the team)
> **Version:** 1.0
> **Document chain:** This document deepens `MASTER_RULES.md` §22–23 (DR, backups), `SECURITY.md` §29–31 (backups, DR, incident response), `DEPLOYMENT.md` §16 (backups) and §21 (infrastructure as code), and `TESTING_STRATEGY.md` §3.16–3.17 (restore and DR tests). It is the DR **design** — no infrastructure is implemented here.
>
> **The honesty principle (read first):** This document specifies **targets, designs, and procedures** — it does not claim achieved outcomes. No RPO/RTO value in this document is a *guarantee*; every target becomes a *measured, achieved* value only when a drill produces evidence for it, and a measured value worse than the target is a defect to fix, not a number to defend. The platform claims nothing it has not proven (`PRODUCT_REQUIREMENTS.md` §9 applies to recovery claims too).

---

## 0. Recovery Posture

**What "recovery" means:** the platform can again serve its critical journeys — login, patient identification, clinical record access, booking, billing — with data intact to the achieved point in time, and the audit trail intact and verifiable.

**The three pillars:**

1. **Backups that are real** — continuous WAL archiving, encrypted, isolated, monitored, and *proven by restore* (a backup that has never been restored is a hope, not a backup).
2. **Recovery that is rehearsed** — runbooks that are actually executed on a cadence, with measured RPO/RTO evidence recorded.
3. **Recovery that is reproducible** — everything needed to rebuild (code, infrastructure-as-code, config, secrets path, artifact registry) exists and is versioned, so recovery never depends on a person's memory.

**What recovery depends on (beyond the database):** the artifact registry (images), infrastructure-as-code, the secrets store's availability path, DNS/edge control, and the monitoring that detects the disaster. A DR design that only covers PostgreSQL is not a DR design.

---

## 1. Recovery Objectives (RPO / RTO)

**RPO (Recovery Point Objective)** — how much data loss is acceptable. Targets by data class:

| Data class | RPO target | Why |
|---|---|---|
| Clinical records (patients, encounters, notes, orders, results) | ≤ 15 min | Patient safety and record integrity |
| Financial records (charges, payments, invoices, claims) | ≤ 15 min | Money truth and reconciliation |
| Audit trail | ≤ 15 min | Compliance and tamper-evidence |
| Identity (users, staff, role assignments) | ≤ 15 min | Access continuity |
| Operational (schedules, wards, stock balances) | ≤ 30 min | Recoverable with effort, no clinical harm |
| Object storage (documents, exports) | Near-zero | Versioning + replication |
| Configuration / platform catalogs | Near-zero | Rebuilt from code/versioned config |

**RTO (Recovery Time Objective)** — how long until the platform serves again:

| Service tier | RTO target |
|---|---|
| **Critical journeys** (login, patient lookup, booking, billing) | ≤ 4 h |
| **Full platform** (including heavy reporting, exports) | ≤ 8 h |
| **Audit access** | ≤ 4 h (with critical journeys) |

**Honesty rule (all targets):** each target is validated by the drill cadence (§13). The quarterly restore drill records the *measured* restore time and the *measured* data loss window; the measured numbers are the platform's actual posture. Target vs. measured drift is a defect with an owner.

---

## 2. PostgreSQL Backup Architecture

- **Point-in-time recovery is the primary mechanism:** periodic base backups **plus continuous WAL archiving** (`ARCHIVE_MODE`, shipped off-box as it's written). Together they restore to any point within WAL coverage (`MASTER_RULES.md` §23.2).
- **Backup frequency:**
  - **WAL:** continuous (each segment archived as it fills).
  - **Base backups:** full nightly (or more frequent as the write volume demands); additional periodic base backups (weekly) for faster recovery points.
- **Retention:**
  - Rolling window of base backups (e.g., 30 days, policy-reviewed) with the WAL that covers them.
  - Monthly archive copies retained per the data-retention classes (`DATABASE.md` §4) — clinical and financial classes longer, operational shorter.
  - Audit-class backups retained per compliance policy.
  - Retention is reviewed with legal counsel before go-live and adjusted — this document sets the classes, not invented numbers.
- **Encrypted backups:** every backup artifact is encrypted with **dedicated backup keys** (KMS), separate from the live environment's key hierarchy where feasible; keys are recoverable in a disaster without exposing live credentials (`SECURITY.md` §12, §29).
- **Cross-region copy:** backups (base + WAL) replicate to a second region so a regional event cannot destroy both the system and its recovery data (`MASTER_RULES.md` §22.5).
- **Monitoring:** backup completion/failure alerts within the hour; **WAL-gap monitoring** (archiving is keeping up — a silent archiving stall is a data-loss event waiting to happen); integrity checks (`pg_checksums`, base-backup spot verification).
- **Isolation:** the backup store is isolated from production credentials, access-controlled, and never publicly reachable; backup credentials are least-privilege (`SECURITY.md` §29).

---

## 3. Point-in-Time Recovery (PITR)

- **Procedure:** restore the latest base backup covering the target time, then replay WAL up to the target (`RECOVERY_TARGET_TIME`). Precision is bounded by WAL segment granularity — the design targets minute-level windows, validated in drills.
- **Uses:**
  - **Tenant-initiated recovery:** accidental deletion or corruption *within a tenant* → tenant-scoped restore from PITR (extract that tenant's rows and objects to the target time) under audit — recovery is a platform operation with a reason and evidence (`TENANCY.md` §16).
  - **Platform-level recovery:** corruption, misconfiguration, or malicious data damage (Section 11).
  - **Forensic reconstruction:** recover the exact state at an incident time for analysis.
- **RLS integrity is part of every restore:** policies and the app role are re-applied and verified *before* the restored environment serves traffic — a restored database with broken policies would be a data-leak event (`SECURITY.md` §29). The drill asserts this every quarter.

---

## 4. Restore Testing

- **Cadence:** a **quarterly full restore drill** — restore into a clean environment from the latest backup and verify:
  - Data integrity (row counts, checksums, spot clinical checks);
  - **RLS policies active** and the tenant-leakage probe passing on the restored data;
  - **Audit hash-chain integrity** (the append-only trail survives restore and still verifies);
  - **Critical journeys run** against the restored data (login, patient lookup, booking, billing);
  - **Measured restore time and data-loss window** recorded as the platform's actual RPO/RTO evidence.
- **Automated where feasible; manual-drill evidence where not.** A drill that fails is an **incident** — not a rescheduled drill (`MASTER_RULES.md` §23.4).
- Every release that changes the schema or the backup pipeline re-runs the drill on the new shape before it is trusted.

---

## 5. Application Recovery

- **The application is stateless** (`DEPLOYMENT.md` §5): recovery is *redeploy*, not *reconstruct*. The immutable artifact is re-promoted from the artifact registry; config comes from versioned environment definitions; secrets come from the secrets store's recovery path.
- **Recovery steps:** re-provision the app fleet from code (Section 7) → health-gated rollout (readiness includes DB/Redis/S3) → traffic shifts.
- **Workers:** jobs are idempotent and tenant-tagged (`MASTER_RULES.md` §4.6, §14); after app recovery, workers drain re-queued jobs; dead-letter queues are reviewed before replay.
- **Scheduler:** exactly one scheduler instance after recovery (no duplicate scheduled work).
- **The secrets path is in the runbook:** the DR plan includes how the secrets store itself is accessed/restored — a recovered app that cannot resolve its credentials is not recovered.

---

## 6. Object Storage Recovery

- **Versioning is the recovery mechanism:** every object is versioned (overwrite never destroys the prior version); object retention lifecycle mirrors document metadata (`DATABASE.md` §3.38).
- **Cross-region replication** of the bucket (or backup copy) so document loss in a regional event is recoverable.
- **Recovery procedure:** point-in-time version restore for a deleted/corrupted object or prefix; tenant-scoped restores for tenant events; full-bucket restore for region events (from the replicated copy).
- **Access after recovery:** signed URLs work once the app is serving; document access audit continues to record post-restore reads.
- **Ransomware hardening (recommended):** immutable/object-lock retention on the backup copy so even a compromised credential cannot encrypt or delete the recovery point (Section 9).

---

## 7. Infrastructure Recovery

- **Infrastructure as code** (`DEPLOYMENT.md` §21): networks, data plane, app fleet, edge, observability — all provisioned from versioned modules. "Rebuild the environment" is a parameterized instantiation, not tribal knowledge.
- **Managed services reduce the rebuild surface:** the platform operates the product, and the managed data plane (PostgreSQL, Redis, object storage) is re-provisioned by restoring/re-creating the service — with the same network isolation and encryption posture.
- **DNS/edge:** DNS failover and edge configuration are code-defined and included in drills (a region cutover that nobody can point at DNS is not a cutover).
- **Reproducibility check:** a fresh environment built purely from code + backups must pass the same verification as a restore drill — this is what makes "recovery" a procedure instead of a project.

---

## 8. Regional Failure

**Design stance:** production is multi-AZ today; a **cross-region backup copy** exists (Section 2). The platform is *recoverable* in a second region — it is not claimed to be *already running* there (active-active multi-region is a documented future step, `ARCHITECTURE.md` §28.8, not a current claim).

- **Scenario:** primary region unavailable (provider outage, regional network event).
- **Recovery path:** provision the environment in the secondary region from IaC → restore the cross-region backup copy (base + WAL) → verify RLS/audit/integrity → DNS cutover to the secondary region → run the verification suite (Section 4).
- **Expected posture (to be validated by the annual drill):** RTO within the critical-journey target; data loss within the RPO target (bounded by the last replicated WAL).
- **Regional recovery is a separate drill** from quarterly restore — it exercises IaC, DNS, and the cross-region copy together, not just the database.

---

## 9. Ransomware Scenario

**Honest framing:** prevention reduces likelihood; this design's contribution is a **recovery path that does not depend on the compromised estate**.

- **Detection signals:** backup failure/write anomalies, mass-encryption patterns (unusual write/delete volume on object storage), audit anomalies, credential misuse alerts (`SECURITY.md` §31).
- **Containment:** revoke/invalidate credentials and sessions, disable writes to the affected stores, preserve evidence (logs, audit, snapshots) — containment before remediation (`SECURITY.md` §31.3).
- **Recovery:** restore from the **isolated, immutable backup copy** (object-lock / network-isolated backup store, Section 6) — never from a store the attacker could reach; verify integrity and RLS before serving; rotate all credentials and keys after restore.
- **Design hardening (recommended):** immutable backup retention (object-lock), network isolation of the backup store, no standing production credentials (SECURITY.md §7, §13 recommended paths), least-privilege everywhere, and the backup store's own monitoring.
- **Drill:** the ransomware scenario is a **tabletop exercise** on the drill calendar (§13) — the team walks the runbook end-to-end so the first time it is used is not the first time it is seen.

---

## 10. Accidental Deletion

Different deletion classes have different recovery paths — the right response depends on the class:

| Class | Example | Recovery path | RPO |
|---|---|---|---|
| **Soft-deletable record** | A patient record soft-deleted in error | In-app status reversal by an authorized operator (audited) — the record was never hard-deleted (`DATABASE.md` §0.11) | Zero (no loss) |
| **Tenant data loss** | A tenant's rows destroyed by a bad migration or operator error | Tenant-scoped PITR restore to the last-known-good point (Section 3) | ≤ RPO target (bounded by WAL) |
| **Object deletion** | A document deleted from the bucket | Version restore (Section 6) | Near-zero (versioning) |
| **Schema/data mistake** | A bad migration corrupted data semantics | Forward corrective migration first; PITR only if the migration is unrecoverable forward | ≤ RPO target |
| **Whole-database incident** | Operator drop/wipe | Full PITR restore from base + WAL (Section 3) | ≤ RPO target |

**Rule:** the audit trail is the first place an accidental deletion is investigated — the event, the actor, and the before-state are on record; recovery is performed under audit and verified.

---

## 11. Data Corruption

- **Detection:** `pg_checksums` and backup integrity checks, replica checksum validation, replica-lag monitoring, application anomaly alerts (impossible states), and audit hash-chain breaks (a tamper or corruption signal).
- **Recovery:** PITR to the **last-known-good** point (before the corruption was introduced, bounded by detection latency); if a replica is healthy, promote the replica after verification instead of restoring from backup — the faster path when it exists.
- **Verification before serving:** the restored/re-promoted data passes integrity checks, RLS probes, and critical-journey verification (Section 4) — a corrupted database that serves traffic is a second incident.
- **Drill coverage:** corruption is a drill scenario (restore to last-known-good, verify); the measured detection-to-recovery time is the platform's real corruption-RTO evidence.

---

## 12. Incident Response (DR Flavored)

DR events are incidents and follow the incident framework (`SECURITY.md` §31) with a DR-specific decision path:

1. **Declare** — severity per impact (patients impacted? money? audit?); the incident commander is named.
2. **Assess** — what is the RPO/RTO impact? Which recovery path fits: restore (PITR), failover (replica/region), or forward-fix (migration/correction)? Choose the *fastest safe* path, not the most dramatic.
3. **Contain** — stop the bleed (revoke creds, disable writes) before recovery, per scenario (Sections 9–11).
4. **Execute the runbook** — the scenario runbook (Section 14), not improvisation; measured RPO/RTO recorded as evidence.
5. **Verify** — the Section 4 verification suite before serving traffic.
6. **Communicate** — internal, tenant-facing, and legal where applicable (`SECURITY.md` §31.3 — obligations assessed in advance, not during the fire).
7. **Postmortem** — blameless, with actions tracked; a recovery that worked but took longer than target is a finding, not a win.

---

## 12a. First Real Restore Drill — Staging (2026-08-12, recorded)

A **real** backup/restore drill was executed against the staging database
(`swasthya_staging`, local mirror — never production data). Full evidence
and the exact steps are in `STAGING_DEPLOYMENT_REPORT.md` §10–§11; the
measured facts belong here:

- **Backup:** `pg_dump -Fc` (custom format, schema + data + RLS policies +
  functions + triggers). Start 02:40:51 UTC, complete 02:40:52 UTC
  (~1 s), size **304,440 bytes**. Verified: 50 table-data sections,
  144 RLS `POLICY` entries present, `ROLE` entries absent (expected —
  roles/grants are cluster-level and NOT carried by `pg_dump`).
- **Restore:** into a disposable `swasthya_staging_restore` database,
  `pg_restore --no-owner --no-privileges`, exit 0, ~1 s.
- **Post-restore verification (all passed):** 50 tables, 47 migrations,
  144 policies, 37 RLS-enabled tables, both tenants present (smoke-group +
  apex-care), 6 users, 2 medications, 123 audit events restored intact.
- **RLS on restored data:** as `swasthya_app_staging` — tenant A context →
  9 patients visible; foreign/no context → 0. Isolation held on the
  restored copy.
- **Roles/grants fixup:** `pg_dump` does not carry cluster-level roles or
  table grants. The app-role grants were re-applied on the restored DB
  (the `database/security/grants.sql` pattern, idempotent) before the RLS
  probe — this is the documented post-restore step every recovery must run
  (`grants.sql` header).
- **RPO/RTO:** the local drill measured restore wall-time ~1 s for a 300 KB
  dump, but **no RPO/RTO is claimed from a local drill** — real targets
  depend on WAL archiving cadence and the actual staging host (§1 targets
  remain targets).

---

## 13. Disaster Recovery Drills

| Drill | Cadence | What it proves | Evidence recorded |
|---|---|---|---|
| **Restore drill** | Quarterly | Backups restore cleanly; RLS + audit + journeys verified | Restore time, data-loss window, verification results |
| **Regional failover exercise** | Annually | IaC + DNS + cross-region copy recover the platform in the secondary region | RTO for critical journeys, RPO at cutover |
| **Ransomware tabletop** | Annually (and after security reviews) | The response runbook is executable end-to-end by the actual team | Walkthrough findings → actions |
| **Corruption scenario** | With restore drill | Restore to last-known-good, verify, serve | Detection-to-recovery time |
| **Backup pipeline check** | Continuous (automated) | Backups completing, WAL keeping up, no silent gaps | Alert history, gap reports |

- **Evidence is the point:** every drill records measured numbers and findings; findings become tracked actions; a drill without recorded evidence did not happen (`MASTER_RULES.md` §23.4).
- **New risk triggers a drill:** schema rework, a new data store, a region change, or a recovery-path change re-runs the relevant drill before the change is trusted.

---

## 14. Recovery Runbook Inventory

Each scenario has a **written, current, rehearsed runbook** (owned, with a contact tree — DR is not one person's private knowledge, `MASTER_RULES.md` §22.6):

1. **Whole-database restore** (PITR) — Section 3.
2. **Tenant-scoped restore** — Section 3.
3. **Object-storage version restore** — Section 6.
4. **Infrastructure rebuild from IaC** — Section 7.
5. **Regional failover** — Section 8.
6. **Ransomware response** — Section 9.
7. **Corruption recovery** — Section 11.
8. **Application redeploy recovery** — Section 5.

Each runbook includes: prerequisites (credentials path, artifact access), step order, verification gates (Section 4), rollback of the recovery itself (a restore that makes things worse must be undoable or re-runnable), and the evidence to record.

---

## 15. What This Document Claims — and What It Does Not

**Claims made here (design commitments):**

- The platform is designed so that clinical/financial/audit data is recoverable to within the stated RPO **targets**, via continuous WAL archiving, encrypted isolated backups, and a cross-region copy.
- Recovery paths exist for every scenario in this document, as written runbooks.
- RLS and audit integrity are part of every restore's verification.
- A drill cadence (quarterly restore, annual region exercise, tabletop scenarios) measures and records the platform's actual posture.

**Claims deliberately NOT made:**

- No RPO/RTO value is claimed as *achieved* — those are targets validated by drills, and the measured values are the truth.
- No guarantee against data loss, corruption, or attack is implied anywhere in this document.
- Nothing here states that the platform "is compliant," "is certified," or "meets a standard" — recovery claims follow the same rule as every other claim in the foundation documents: verified or not claimed.

---

*This document is the DR contract for Swasthya: backups that are proven by restore, recovery that is rehearsed and measured, infrastructure that rebuilds from code, and a posture that reports its targets as targets and its measurements as measurements. When the platform eventually reports an achieved RPO of 12 minutes, it will be because a drill measured 12 minutes — not because this document said so.*
