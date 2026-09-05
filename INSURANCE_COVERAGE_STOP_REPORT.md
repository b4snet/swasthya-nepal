# Insurance / Coverage — STOP Report

**Phase:** Insurance / Coverage hardening (internal, backend-first)
**Branch:** `main` (HEAD `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`)
**Date:** 2026-09-03
**Disposition:** STOP — no commit, no push, no deploy. All internal scope complete and regression-verified.

---

## 1. Scope and constraints honored

This phase executed **only internal hardening** of the existing Insurance / Coverage
capability, per the 80-stage discipline.

- **No** production/staging access used.
- **No** real patient, payer, HIB, SSF, or credentials touched.
- **No** fabricated payer APIs or invented HIB/SSF requirements.
- **No** duplicate payer engines or parallel payment ledger introduced.
- **No** RLS bypassed; all new tables are TENANT-tier, RLS enabled + forced.
- **No** silent `denied → patient-debt` conversion.
- **No** simulation represented as live external integration.
- **No** schema change outside repository justification. The one new migration is
  justified by prompt §20/§24/§25/§62 **and** the already-existing-but-unused
  `claims.rejection_reason` column — satisfying STOP 18.
- Work was **not** committed, pushed, or deployed (user decision).

## 2. Repository truth (as found)

| Concept | Verified source of truth |
|---|---|
| Coverage | `InsurancePolicy` (bi-directional patient↔payer) |
| Claim | `InsuranceClaim` (table `claims`) + `InsuranceClaimLine` (table `claim_lines`) |
| Payer master | `Payer` |
| Eligibility / Authorization / Remittance / Reconciliation | **None** — confirmed absent in discovery |

Routes captured: `payer:view`/`payer:manage` (api routes 382-385),
`insurance:view`/`insurance:manage` (434-441), claim lifecycle
`billing:view`/`billing:reconcile`/`insurance:claim`/`insurance:settle`
(826-843), with segregation-of-duties maintained.

Form requests verified: `StorePolicyRequest` (tenant-scoped pre-check, one active
per patient+payer), `UpdatePolicyRequest` (lockVersion required),
`StorePayerRequest` (unique code per tenant, `^[a-z0-9][a-z0-9-]{1,49}$`),
`StoreClaimRequest` (`policyId` required), `SettleClaimRequest`
(partial|paid + settlementMinor), `RecordClaimStatusRequest` (denialReason).

## 3. Internal hardening delivered

### 3.1 Rejection vs. denial distinction (prompt §25)

- **Migration**
  `backend/database/migrations/2026_09_03_100000_harden_insurance_claim_lifecycle.php`:
  - drops and recreates the `claims.status` CHECK to add `'rejected'`;
  - creates `claim_submissions` (see §3.2) with TENANT-tier RLS (enabled +
    forced) and a composite FK to `claims`;
  - unique `(tenant_id, claim_id, submission_number)`.
  - Pint-clean.
- **Model** `backend/app/Models/InsuranceClaim.php`:
  - `STATUS_REJECTED = 'rejected'`;
  - `reopenableStatuses()` → `[denied, rejected]`;
  - `submissions()` HasMany (ordered by `submission_number`).
- **Service** `backend/app/Services/FinanceService.php` `recordClaimStatus`:
  - new `rejectionReason` parameter, kept **distinct** from `denialReason`;
  - allowed transitions now:
    - `submitted → pending | denied | rejected`
    - `pending → partial | paid | denied | rejected`
  - `rejected` requires a non-blank rejection reason (422 otherwise);
    `denied` requires a denial reason (existing).
  - DB update writes each reason into its own column
    (`denial_reason` vs `rejection_reason`), so the two are never collapsed.
- **Request** `backend/app/Http/Requests/Billing/RecordClaimStatusRequest.php`:
  adds `rejectionReason` (nullable, max:1000) and `rejected` to the status `Rule::in`.
- **Controller** `finance/claims` updates: passes `rejectionReason` through,
  surfaces `rejectionReason` and `settlementMinor` in the transition envelope.

### 3.2 Rejection/denial → reopen → resubmission (prompt §24, §62)

- `reopenClaim` now accepts **both** `denied` and `rejected` (CAS-guarded via
  `whereIn` + lockVersion), and clears **both** `denial_reason` and
  `rejection_reason` on the way back to `draft`. Outcome reason is preserved
  in the audit trail, never lost for correction.

### 3.3 Immutable submission snapshot (prompt §20)

- Submitted claims get an atomic snapshot. On `submitClaim` (draft → submitted,
  CAS lock), the service freezes the **actually-submitted** context — claim
  number, invoice, policy, payer, status, billed total, and each frozen line
  `(invoiceLineId, billedMinor)` — into a new `claim_submissions` row in the
  **same transaction**.
- Snapshot `submission_number` = `max + 1` per claim → **append-only**.
  Reopen + resubmit appends the next number; the prior submission is never
  overwritten (proven by test).
- Snapshot derives entirely from invoice truth (`billed_minor`), never a
  fabricated "submission total."
- **Model** `backend/app/Models/InsuranceClaimSubmission.php` (new): casts
  `submitted_snapshot` to array; `submitted_by` is a plain UUID (no staff FK —
  decouples history from staff/churn, and keeps deletions from corrupting
  snapshots).
- **Controller** `showClaim` loads `lines, submissions`; `presentClaim` exposes
  `submissions` for UI detail.

### 3.4 Frontend

- `frontend/src/api/types.ts`: `InsuranceClaim` gains `rejectionReason` and a
  `submissions` array (with typed `snapshot`). `npm run typecheck` passes.

## 4. Tests

New feature tests in `backend/tests/Feature/FinanceWorkflowTest.php` (all pass):

1. **Rejection vs. denial distinction (§25)** — both transitions record their own
   reason; neither leaks into the other; a `rejected` without a reason is 422.
2. **Immutable submission snapshot + resubmission append (§20/§24/§62)** — the
   frozen snapshot equals the submitted payload, the totals tie to invoice truth,
   editing lines after submission does **not** change the stored snapshot, and a
   reopen+submit appends a new unique `submission_number` instead of overwriting.
3. **Rejected-claim reopen + resubmission** — `rejected → draft → submitted` works
   and clears `rejection_reason`; `reopenClaim`/`submitClaim` remain CAS-safe.
4. **Cross-tenant RLS/IDOR isolation of `claim_submissions`** — another tenant
   cannot read, mutate, or forge snapshots; rows are invisible under RLS.

Existing unrelated suites that must keep passing (all still pass):
`FinanceWorkflowTest`, `BillingVoidTest`, `BillingPaymentTest`,
`InsuranceConsentDocumentTest`, `BillingReturnNotificationTest`,
`ClaimsBasedRlsTest`.

## 5. Regression gate

| Gate | Result |
|---|---|
| Frontend typecheck (`tsc -b --noEmit`) | Pass |
| Pint (all changed files) | Pass |
| `git diff --check` | Clean |
| Directly-related test suites | Pass (all assertions green) |
| Full backend suite | 1327 passed; **no new failures attributable to this phase** |

### 5.1 Pre-existing baseline failures (out of scope, NOT caused by this phase)

The full suite shows `82 failed, 1327 passed`. All 82 are pre-existing and
environmental/baseline; the directly-related suites pass. They cluster as:

- **`SQLSTATE[25P02]` transaction-aborted** across suites this phase never
  touches (during full-suite execution, an earlier failure aborts a shared
  transaction): `EventInfrastructureTest`, `NumberingConfigTest`,
  `PortalActivationTest`, `RoleOnboardingTest`, `DepartmentEnhancementTest`,
  `HospitalBrandingTest`, `CommunicationTemplateTest`, `DoctorScheduleTest`,
  `FormLibraryTest`, `FiscalPeriodWorkflowTest`, `IpdNursingWorkflowTest`.
- **RLS roles INSERT 42501**: `TenancyDatabaseInventoryTest`, `DatabaseRowLevelSecurityTest`.
- **Confirmed pre-existing code bugs in the Nepal finance stack** (reproducible
  and independent of this phase; confirmed present via `git show HEAD`):
  - `NepalFinanceE2ETest` line 655 `$this->ctx()` on a non-method → `Error` across
    its E2E scenarios;
  - `NepalFinanceTest` / `NepalFinanceTaxPipelineTest` tax-resolver null +
    payer-CRUD 500s;
  - `NumberingConfigTest` config/resolver issues.

These are recorded so the next phase can decide whether to fix them; they are
**not** regressions from the Insurance / Coverage hardening and **do not** block
the STOP.

## 6. Deferred / external-dependent (explicitly out of scope)

- **Eligibility / Benefit check** — no model; external/partner-dependent.
- **Authorization / Pre-cert** — no model; external/partner-dependent.
- **Remittance / Payment reconciliation** — no model; external/partner-dependent.
- **Live HIB / SSF / private-payer integrations** — must be built against real
  partner contracts; none fabricated here.
- **Remediation of the §5.1 pre-existing baseline failures** — separate, gated item.

These are intentionally **not** invented in this phase (per constraints). They
should be scheduled against real partner documentation/credentials.

## 7. Files changed (this phase only)

Modified:
- `backend/app/Services/FinanceService.php` (submitClaim snapshot,
  recordClaimStatus reject+reason separation, reopenClaim reopened statuses)
- `backend/app/Http/Controllers/Api/FinanceController.php` (submissions load +
  present, rejectionReason wiring)
- `backend/app/Http/Requests/Billing/RecordClaimStatusRequest.php` (rejectionReason,
  `rejected` status)
- `backend/app/Models/InsuranceClaim.php` (STATUS_REJECTED, reopenableStatuses,
  submissions relation)
- `backend/tests/Feature/FinanceWorkflowTest.php` (four new tests)
- `backend/tests/Feature/NepalFinanceE2ETest.php` (two added `null` args only)
- `frontend/src/api/types.ts` (InsuranceClaim type)

New:
- `backend/app/Models/InsuranceClaimSubmission.php`
- `backend/database/migrations/2026_09_03_100000_harden_insurance_claim_lifecycle.php`

Untouched prior-phase artifacts remain as-is (e.g. `UpdateContactRequest`,
`PatientIdentifier`, `PatientCsvImportService`, prior STOP/security reports).

## 8. Next steps (not executed)

1. (Recommended) Fix the §5.1 pre-existing Nepal finance / transaction-isolation
   baseline failures in their own gated phase before surface-area work.
2. Build Eligibility / Authorization / Remittance models **against real partner
   contracts**.
3. Wire live HIB / SSF / private-payer submissions (needs partner credentials +
   production/staging access).
4. Commit + push this phase once the user authorizes **and** the §5.1 baseline is
   agreed as accepted risk.