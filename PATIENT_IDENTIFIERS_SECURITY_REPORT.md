# SWASTHYA — PATIENT IDENTIFIERS STOP REPORT

## 1. Baseline

- **Branch:** `main`
- **HEAD:** `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`
- **origin/main:** `9fc3508eb6e848fc92a43d94694819a6c462f1cd` (local HEAD is 8 commits ahead: auth hardening, tenancy V2 lifecycle, backend safety tests, frontend httpOnly-cookie auth, module-aware nav, docs)
- **Working tree:** clean of source changes; only pre-existing build artifacts remain (`node_modules/.vite/.../results.json` tracked artifact, untracked `.vite/`)
- **Baseline tests (identifier scope):** `PatientIdentifierContactTest` (6), `PatientImportTest` (11), `PatientSearchMergeTest` (5) — all **PASS** before any change.

## 2. Identifier Inventory

Audited against the actual schema/domain (`DATABASE.md §3.12`). Located at `patient_identifiers.value_encrypted`.

| Identifier Type | Table | Column | Sensitivity | Encrypted | Searchable | Returned to UI | Logged | Retained |
|---|---|---|---|---|---|---|---|---|
| national_id (e.g. NPRN) | patient_identifiers | value_encrypted | High (patient identity) | Yes (AES-256-CBC) | via deterministic sha256 `value_hash` | Yes — full plaintext on GET identifiers | Never (audit carries type only) | Superseded, never deleted |
| passport | patient_identifiers | value_encrypted | High | Yes | via `value_hash` | Yes | Never | Superseded, never deleted |
| license | patient_identifiers | value_encrypted | High | Yes | via `value_hash` | Yes | Never | Superseded, never deleted |
| other | patient_identifiers | value_encrypted | Medium | Yes | via `value_hash` | Yes | Never | Superseded, never deleted |

No additional identifier types were invented. The portal `login_identifier` on `portal_accounts` is a separate credential-affined field (email/phone), not a civil identity identifier; it is noted but not part of this civil-identifier boundary.

## 3. Existing Encryption Contract

Verified directly in `vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php` and `config/app.php`.

- **Algorithm:** AES-256-CBC (`app.cipher = 'AES-256-CBC'`).
- **Mode:** CBC, **non-AEAD** — integrity is provided separately.
- **Nonce/IV:** one fresh 16-byte IV per encryption, from `random_bytes()`. Reuse not possible under normal flow (cryptographically random per call).
- **Authentication tag:** Encrypt-then-MAC. `mac = hash_hmac('sha256', base64(iv) . base64(ciphertext), key)`. Verified on decrypt **before** decryption; any tampering raises `DecryptException` (fail closed).
- **Ciphertext format:**
  `base64( json{ iv: <16B base64>, value: <AES-256-CBC ciphertext>, mac: <hmac-sha256 base64>, tag: '' } )`
- **Key source:** APP_KEY (base64-encoded 32-byte), from environment; gitignored.
- **Key storage:** Laravel `Encrypter` bound in the container; single application-wide key in memory. **No KMS/HSM/secret-manager integration in the runtime** — env-file supplied.
- **Key versioning:** None in the ciphertext. Laravel's `app.previous_keys` (`APP_PREVIOUS_KEYS`) provides a decryption fallback list for rotation, but produces no per-record key version identifier.
- **Interface:** `App\Casts\EncryptedString` wraps `Crypt::encryptString/decryptString`; `PatientIdentifier::setValueAttribute()` is the single writer (encrypt + derive hash).

## 4. Encryption Implementation

Verified against the source and the running database; one local defect fixed (see §13). No architecture replaced.

- Authenticated (Encrypt-then-MAC), randomized encryption — **confirms confidentiality + integrity**. Tamper/wrong-key/malformed-payload all fail closed with a generic safe error.
- Nonce uniqueness: random 16-byte IV per value; collision probability negligible; no hardcoded/timestamp/nonce reuse path exists.
- At-rest layout is ciphertext + separate deterministic hash; **no plaintext identifier column exists**.
- **Fixed in this pass (CSV import bypass):** `PatientCsvImportService` previously wrote identifiers via `create(['value' => ..., 'value_hash' => ...])`. Because `value` was not `$fillable`, mass assignment dropped it, so `setValueAttribute()` never ran, `value_encrypted` was left `NULL` (violating NOT NULL), and the error was swallowed into the import row-error detail. Imported identifiers were therefore **never encrypted-or-persisted-correctly**. Fix: added `value` to `$fillable` so the mutator runs and derives ciphertext + hash; removed the redundant manual `value_hash` write. Regression test added (see §18).

## 5. Key Management

| Key | Purpose | Env | Source | Storage | Rotation | Versioning | Access Control | Verified |
|---|---|---|---|---|---|---|---|---|
| APP_KEY | AES-256-CBC for encrypted fields (identifiers, MFA seeds, staff/vendor tax/bank) + JWT fallback | dev/staging/prod | environment secret | Laravel Encrypter in-memory | `APP_PREVIOUS_KEYS` decryption fallback only (no active re-encryption workflow) | None in ciphertext | Whoever holds APP_KEY in the app process / deploy env | Local crypto yes; production retrieval **not verified** |

Key-separation (§8): the same APP_KEY also protects MFA seeds and staff/vendor tax/bank fields and is the JWT fallback source. **No purpose-specific key material** exists. This is a **REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT / design clearance** item, not a local defect to silently fix.

Secret storage: `.env`/`*.env.*` gitignored; `APP_KEY=` is empty in `.env.example` (never committed). No key in source, fixtures, frontend bundle, docs, or Git history. **No production key was exposed during this audit.**

## 6. Key Rotation

- **Partially implemented (framework capability), NOT operated.** Laravel `previous_keys` permits decrypting old payloads after APP_KEY changes, but there is **no re-encryption command, no per-version marker, no retirement workflow** in this codebase.
- A safe rotation design (K1 → K2, dual-read, background re-encrypt, verify, retire K1) is **documented as required** (`MASTER_RULES.md §29`, `DEPLOYMENT.md §key-rotation`) but **not implemented**.
- **Rotation tests:** not performed against the running system because no rotation tooling exists; rotation is `REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT`.

## 7. Searchability

- Exact duplicate detection/lookup uses **deterministic, non-keyed `value_hash = sha256(normalized value)`** (`PatientIdentifier::hashValue`), scoped by `(tenant_id, type)` in every query.
- **Finding (evidence-backed):** the digest is **unkeyed/un-prefixed** sha256 over low-entropy identifiers. An actor holding DB read access (no key) can offline-brute-force candidate national/passport values to recover plaintext by re-computing sha256 over a candidate space and matching the stored hash. Ciphertext itself is randomized and tamper-evident, so it does not leak, but the hash index is an equality-and-guessing oracle.
- **Recommended hardening (deferred — requires schema/migration + re-index; not done in this pass to avoid touching stored data without a rotation plan):** keyed HMAC-SHA-256 digest with a purpose-specific key and key version, mirroring the search architecture in §22/§23 of the brief. This is `DESIGNED / REQUIRES PRODUCTION-LIKE KEY MANAGEMENT`.
- No RLS-bypassing global identifier index exists; all hash queries join/filter on `tenant_id`.

## 8. Decryption Boundary

- Decryption occurs via the `EncryptedString` cast **wherever the attribute is read** — the model auto-decrypts `value_encrypted` into plaintext in application memory on every access. This is a broad boundary, NOT the narrowest `Service → decrypt → controlled response` (§27 target).
- API serialization returns decrypted plaintext `value` in both the list (`index`) and create (`store`) responses for any authorized role.
- This is consistent with the existing contract and tests; narrowing it (e.g., a dedicated identifier read-service + response masking) is a **future hardening** item, not required to conclude the local crypto validation.

## 9. Authorization

- Route + resource gating via `AccessCheck::scoped($patient, write)` and RBAC permissions (`tenant_id`/`facility` enforced by the middleware chain and `ResolveTenantContext`).
- Full plaintext is returned to any role with authorised patient-scope read; no masking layer exists yet for list endpoints (§20/§21 target). No role distinction for full vs masked value today.
- Cross-tenant IDOR/BOLA is denied — verified by `CrossTenantApiAttackTest` and `BackendSafetyTest` (patient/facility IDOR sweep).

## 10. Tenant / Facility Isolation

- Enforced at two independent layers:
  1. **Application authorization** — `AccessCheck::scoped` + tenant context.
  2. **Database RLS** — `pgsql_rls`/`FORCED` PostgreSQL row-level security (runtime non-owner role), verifiable by `CrossTenantApiAttackTest` and `TenancyDatabaseInventoryTest`.
- Identifier duplicate lookup is tenant-scoped by `tenant_id + type + value_hash`; Tenant A cannot use Tenant B's identifier to retrieve a patient.
- Facility isolation is enforced through the wider patient-scope facility model (verified by `PatientSearchMergeTest`: facility roles scoped to their facility).

## 11. IDOR Results

Substitute-patient / substitute-identifier / cross-tenant object swaps are denied (404/403 via RLS) — covered by:
- `CrossTenantApiAttackTest` (IDOR/BOLA sweep, cross-tenant patient/contact UPDATE, forged references inert).
- `BackendSafetyTest` (patient/facility read and update IDOR).
Identifier records are keyed to a specific patient via `patient_id` + parent-linkage RLS; a known identifier-record UUID does not bypass patient-level authorization.

## 12. Logging / Telemetry

- **Identifiers are never logged in plaintext.** Audit records carry `type` and references only (verified: `PatientIdentifierContactTest` asserts audit payload does not contain the value). Safe logs may contain event category, patient UUID, identifier-type, action, outcome, correlation ID — never the value.
- `PatientController` duplicate path stores only hashes; no raw request payloads with identifier values reach application logs.
- No frontend telemetry/analytics collects identifier values (frontend receives value only in a single API response for the current screen; not placed in global state/localStorage/URLs).

## 13. Export / Migration

- **Tenant data export** (`DataGovernanceController::export` / `ExportService`) records **facts only** (layers, record counts) — it does not emit identifier plaintext.
- **CSV import** previously failed to persist identifier ciphertext (see §4/§18); now fixed so imported identifiers are encrypted at rest via the standard mutator. Source/temp files remain on the configured import disk; no real hospital exports were used (synthetic/`NID-IMPORT-001` only). Rejected rows are captured in `import_errors` without identifier values.

## 14. Backup / Restore

- **Not executed against real encrypted backups.** The documented contract (`SECURITY.md §12/§fin, DEPLOYMENT`) requires encrypted backups whose keys are separate from the live hierarchy. Because the runtime encryption key is APP_KEY (env), a backup restore in the **same** deployment is decryptable (verified in principle: ciphertext decrypts under the current key). Restoring under a **different** APP_KEY is a documented recovery/rotation problem, not a bare "silently unreadable" case, but **no backup/restore drill was run** — this is `REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT`.

## 15. Production Key-Management Validation

Explicit separation:

- **PROVEN LOCALLY (this pass):**
  - AES-256-CBC + Encrypt-then-MAC correctness; random-IV uniqueness; tamper/wrong-key/malformed detection (framework-verified).
  - Encrypted-at-rest persistence and decrypt-on-read round trip.
  - No plaintext identifier column; no value in audit/logs.
  - Import mass-assignment now routes through the encrypting mutator (defect fixed + regression test).
  - RLS + RBAC + tenant/facility isolation for identifier access (IDOR/BOLA sweep green).

- **CONTRACT-TESTED / SIMULATED:**
  - `value_hash` exact lookup semantics (whitespace/case normalization via `hashValue`); duplicate detection correctness (exact match surfaced as 409) — verified.

- **REQUIRES REAL PRODUCTION-LIKE SECRET/KMS INFRASTRUCTURE:**
  - Secret-manager/KMS-backed key retrieval (runtime currently reads env APP_KEY).
  - Production key rotation + re-encryption + retirement workflows (tooling does not exist).
  - Environment key separation verification (dev/staging/prod keys share no automated proof).
  - RLS "keys never in DB/app code" (§12 contract) operational enforcement.
  - Backup/restore with production key management; real alerting.

- **REQUIRES INDEPENDENT SECURITY ASSESSMENT:**
  - Formal validation of the KMS integration, IAM/secret access, and operational key-revocation runbook once a production key store is wired.

## 16. Security Assessment

- **Cryptographic:** Sound. AES-256-CBC + Encrypt-then-MAC, random IV, fail-closed on tamper/wrong-key. Locally proven.
- **Authorization:** Sound within the tenant/facility model; no username-visible full-value masking yet.
- **RLS:** Enforced (`FORCED`), verified by cross-tenant attacks; encryption is applied **in addition to**, never instead of, RLS.
- **IDOR:** Closed (verified).
- **Logging:** Safe — no plaintext identifier values in logs/audit.
- **Migration/Import:** Import identifier persistence defect **fixed**; hash remains unkeyed (see §7).
- **Backup:** Design documented; production drill not performed.
- **Browser:** Frontend keeps value in a single screen response; no global state/localStorage/URL/metadata exposure observed.
- **Operational:** Single shared APP_KEY (no purpose-separation, no runtime KMS); rotation/compromise-response tooling absent — this is the principal production gap.

## 17. Test Results

| Suite | Baseline | Final | Delta |
|---|---|---|---|
| PatientIdentifierContactTest | 6 pass | 7 pass | +1 (import-parity encryption regression) |
| PatientImportTest | 11 pass | 11 pass | 0 |
| PatientSearchMergeTest | 5 pass | 5 pass | 0 |
| CrossTenantApiAttackTest | pass | pass | 0 |
| BackendSafetyTest (patient IDOR) | pass | pass | 0 |

2 pre-existing, unrelated failures in `TenancyDatabaseInventoryTest` (`roles`-table RLS INSERT boundary tests under the disposable `pgsql_rls` connection) were observed before and after this change; they are independent of patients/identifiers and my edits (`git diff` on that file is empty).

## 18. Real PostgreSQL Proof

Using the disposable local PostgreSQL (`swasthya_test` @ 127.0.0.1:5433, schema built from migrations on each run), proven:

- Row at rest holds **ciphertext** in `value_encrypted` and a **derived sha256** in `value_hash`, never the plaintext.
- API list returns decrypted plaintext through the `EncryptedString` cast.
- Cross-tenant SELECT/UPDATE fail under RLS.
- Import/mass-assignment path now persists encrypted ciphertext + derived hash (new regression test).

## 19. Rotation / Re-encryption Proof

- **Not performed.** No rotation/re-encryption tooling exists; performing rotation against the running system would be unsafe without a version marker and re-encrypt workflow. Proven only that framework-level decrypt-under-previous-key is available. Classified **requires production-like key management**.

## 20. Files Created

- `PATIENT_IDENTIFIERS_SECURITY_REPORT.md` (this report)

## 21. Files Modified

- `backend/app/Models/PatientIdentifier.php` — added `value` to `$fillable` so the encrypting mutator runs on mass assignment.
- `backend/app/Services/PatientCsvImportService.php` — route identifier creation through the `value` mutator (remove manual `value_hash`, persist ciphertext).
- `backend/tests/Feature/PatientIdentifierContactTest.php` — added import-parity at-rest encryption regression test.

## 22. Database / Migration Changes

`NONE` (no schema/migration change; no stored-data rewrite; no re-index).

## 23. Production / Staging Changes

`NONE` (no commit, push, or deploy; no Supabase/staging changes).

## 24. Documentation

- Updated: `PATIENT_IDENTIFIERS_SECURITY_REPORT.md` (this report).
- Referenced contract docs (unchanged): `SECURITY.md §12/§fin`, `MASTER_RULES.md §29`, `DEPLOYMENT.md`, `DATABASE.md §3.12`.

## 25. Remaining Production Requirements

Evidence-backed only:

- Wire a managed secret store / KMS backing for the encryption key (`SECURITY.md §12`, `DEPLOYMENT.md`), keeping the provider behind an application boundary.
- Implement and operate documented key rotation + background re-encryption + verification + retirement, with a per-record key/format version marker.
- Close the key-reuse gap (identifiers vs MFA seeds vs staff/vendor vs JWT fallback) with purpose-specific material.
- Replace the unkeyed `value_hash` with a keyed HMAC digest + re-index for safe exact lookup (deferred: schema + migration + re-index need a rotation plan).
- Operational compromise-response runbook (contain → disable → re-encrypt → identify affected → audit → downstream backups/exports/snapshots).
- Backup/restore drill against production key management; establish environment key isolation (dev/staging/prod).

## 26. Validation Tier (per capability)

| Capability | Tier |
|---|---|
| Field encryption (AES-256-CBC ciphertext at rest) | **PROVEN LOCALLY** |
| Authenticated integrity (Encrypt-then-MAC, tamper/wrong-key detection) | **PROVEN LOCALLY** |
| Nonce/IV safety (random per value) | **PROVEN LOCALLY** |
| Key storage (runtime) | **REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT** |
| Key versioning in ciphertext | **REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT** (absent) |
| Key rotation / re-encryption / retirement | **DESIGNED ONLY — REQUIRES REAL PRODUCTION INFRASTRUCTURE** |
| Compromise response | **REQUIRES REAL PRODUCTION INFRASTRUCTURE** (runbook only) |
| Keyed search digest | **CONTRACT-TESTED (semantics) — keyed form requires schema+rotation, deferred** |
| RLS | **PROVEN LOCALLY** |
| Authorization | **PROVEN LOCALLY** |
| Log/Audit protection | **PROVEN LOCALLY** |
| Export protection | **PROVEN LOCALLY** (facts-only bundle) |
| Import encryption | **PROVEN LOCALLY** (defect fixed + regression test) |
| Backup/restore | **REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT** |
| Production secret retrieval | **REQUIRES REAL PRODUCTION-LIKE KEY MANAGEMENT** |
| KMS/HSM | **NOT IMPLEMENTED — REQUIRES REAL PRODUCTION INFRASTRUCTURE** |
| Overall | **COMPLETE WITH DOCUMENTED LIMITATIONS** |

## 27. Final Git State

- **Branch:** `main`
- **HEAD:** `4448a2c...` (unchanged; no commit made)
- **Working tree:** only the 3 modified files above + 1 new report; pre-existing build artifacts remain untracked/modified.
- **Modified files:** `PatientIdentifier.php`, `PatientCsvImportService.php`, `PatientIdentifierContactTest.php`, `PATIENT_IDENTIFIERS_SECURITY_REPORT.md`.
- **Untracked:** `PATIENT_IDENTIFIERS_SECURITY_REPORT.md`, `.vite/`.

## 28. Final Status

**COMPLETE WITH DOCUMENTED LIMITATIONS**

Local cryptographic correctness is proven and one concrete encryption-boundary defect (CSV-import identifier persistence) was fixed. Production key-management readiness (KMS-backed retrieval, rotation, environment separation, backup/restore drill) is not yet implemented and cannot be honestly claimed. Full production validation requires real production-like secret/KMS infrastructure and independent security review.

## 29. Next Remaining Feature

Per the authoritative `ROADMAP.md`, the active work stream is hardening toward pilot go-live grade readiness. The next scoped Patient Master / security hardening surface after this phase is the remaining **Patient Master** lifecycle: identifier/contact verification workflows and the keyed search-digest + secret-manager wiring documented above, sequenced within the ongoing Phases 29+ planning.

**DO NOT IMPLEMENT IT.** Stop here.