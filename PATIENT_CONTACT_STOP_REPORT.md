# Patient Contacts — Security & Integrity STOP Report

Scope: `patient_contacts` (DATABASE.md §3.13) — phone / email / address / emergency-contact,
lifecycle (one active primary per (patient, type), supersede-not-delete), authorization, RLS,
and update-path integrity. Companion to `PATIENT_IDENTIFIERS_SECURITY_REPORT.md` (same Patient Master).

Verdict as of this pass: **no STOP condition.** Two confirmed update-path defects were fixed
(blocked-write paths now return a clean 422 instead of a raw DB-integrity 500, and the emergency
contact person-identity invariant is now held on update). No schema change, no new roles, no
weakening, no removal of documented behavior.

---

## 1. Contact contract (verified against code, DB, and docs)

| Aspect | Contract |
|---|---|
| Types | `phone`, `email`, `address`, `emergency_contact` |
| Status | `active`, `superseded` — **no soft-delete** ("supersede, don't delete") |
| Detail | `value` (text: phone/email/emergency phone) **XOR** `address` (jsonb) — exactly one |
| Person | `contact_person` (jsonb: `name`, `relation`) for emergency contacts |
| Flags | `is_primary`, `status`, `valid_from`/`valid_to` (nullable) |
| Primary | **One active primary per (patient, type)** — partial unique index `(tenant_id, patient_id, type) WHERE is_primary AND status='active'` + `demotePrimaries()` |
| Response | 7-field camelCase `{id, type, value, address, contactPerson, isPrimary, status}` |
| Writes | `patient.contact.added` / `patient.contact.updated` audit + patient timeline; every mutation in `DB::transaction` |
| Authn/z | `GET` → `authorize:patient:view`; `POST`/`PATCH` → `authorize:patient:update`; controller `AccessCheck::scoped` (reads) and `AccessCheck::patientChild` + explicit parent-linkage guard (update) |
| RLS | `patient_contacts` is **TENANT_ONLY** — facility isolation is the application layer (established convention) |

## 2. What was verified (execution gates)

- **Baseline before any change:** `PatientIdentifierContactTest` (7) green; git tree clean except the
  prior identifiers-phase modifications (`PatientIdentifier.php`, `PatientCsvImportService.php`) which
  were left untouched.
- **Fresh read of the real artifact:** model, controller (`PatientContactController`), both form
  requests, migration, factory, routes, `AccessCheck`, `Patient`, the merge path, and the frontend
  types — not a stale view.
- **Security / isolation:** cross-tenant `PATCH` → 403 SCOPE_DENIED; parent-mismatch → 404 NOT_FOUND
  (existence never leaked) — asserted in `CrossTenantApiAttackTest`. RLS TENANT_ONLY enforced and
  covered by `EdgeFunctionPipelineTest` + `TenancyDatabaseInventoryTest`.
- **Primary integrity:** one active primary per (patient, type) via partial unique index + atomic
  demote-then-create / demote-on-promote (store and update both in `DB::transaction`).
- **Merge:** `PatientController::reassignChildren` moves contacts to the survivor inside a transaction,
  demoting a moved primary when the survivor already holds that type (partial-unique-index safe).
- **Provenance:** audit/timeline record references and type, never the raw detail.

## 3. Confirmed defects (empirically reproduced) and fix

### Defect A — blocked-write 500 on update (HTTP integrity error)
**Evidence:** standalone probe — `PATCH {value: null}` on an existing value-contact, and
`PATCH {value: '+977-…', address: {line1:'x'}}` each returned **HTTP 500 SERVER_ERROR** (raw
`chk_contacts_value` violation) instead of a clean 422.

**Root cause:** `UpdateContactRequest` had no exactly-one-of-value/address rule (unlike `StoreContactRequest`),
so the update path bypassed the validation that the DB CHECK normally owns.

**Fix:** `UpdateContactRequest::withValidator` now computes the **resulting** value/address state
(against the current persisted row via the route-bound contact) and rejects a breach with a clean 422,
mirroring `StoreContactRequest` and the `chk_contacts_value` CHECK.

### Defect B — error deterministic white-box, person-identity strippable on update
**Evidence:** probe — `PATCH {contactPerson: null}` on an emergency contact returned **200** and
persisted `contact_person = null` while `type = 'emergency_contact'`, erasing the required
name/relation identity.

**Root cause:** the emergency-name invariant was enforced at store only, not update.

**Fix:** `UpdateContactRequest` now rejects removing the name of an emergency contact (422); a
rename (`contactPerson` with `name`) still succeeds.

## 4. Regression coverage added

`PatientIdentifierContactTest` (7 → 9 tests):
- `rejects a contact update that breaks the exactly-one-of value/address invariant (422, not 500)`
- `rejects stripping the name of an emergency contact on update (invariant held)`

## 5. Verification runs (all green)

| Suite | Result |
|---|---|
| `PatientIdentifierContactTest` | 9 passed (39 assertions) |
| `CrossTenantApiAttackTest` + `EdgeFunctionPipelineTest` | 57 passed (1155 assertions) |
| `PatientRegistrationTest` + `PatientSearchMergeTest` + `PatientImportTest` | 24 passed (105 assertions) |
| Pint (lint) on changed files | clean (formatting applied) |

Note: `PatientSearchMergeTest`/`PatientImportTest` had 5/11 assertions respectively per the prior
report; the contact suites above are the merged-run totals and are green.

## 6. Not changed / deferred (deliberate)

- **`valid_from`/`valid_to`** are defined in the schema but have no API mutation path — a domain
  capability is inert. Adding capture is a **behavior addition** (outside the safe envelope of this
  pass; not a defect). Recommend scoping in a future phase.
- **`type` is immutable** (not patchable) and `destroy`/soft-delete do not exist — consistent with
  `supersede, don't delete`.
- **No dedicated relation-controlled vocabulary beyond `contact_person.name/relation` free text** —
  documented as free-form; not a defect.

## 7. Files changed (this pass)

- `backend/app/Http/Requests/Patient/UpdateContactRequest.php` — hardened update validation
- `backend/tests/Feature/PatientIdentifierContactTest.php` — added 2 regression tests (file also
  carries the prior identifiers-phase addition, untouched)
- `PATIENT_CONTACT_STOP_REPORT.md` — this report

No commit has been made; the working tree retains the prior identifiers-phase changes.