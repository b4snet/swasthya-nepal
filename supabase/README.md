# Swasthya Nepal — Supabase-native Edge Function layer (Phases 4–35)

The infrastructure Edge Functions and the shared secure pipeline, built as
part of the Supabase-native migration (Phases 2–5: RLS re-key → auth
foundation → edge layer → identity bridge). Phases 6–16 add the first
domain-safe functions on the same pipeline: `me` (identity/context),
`patients:list` + `patients:show` (read spine), and the eight WRITE
endpoints `appointments:create` (booking), `appointments:checkin`
(queue-token handoff), `encounters:create` (encounter start / queue
handoff), `encounter-notes:draft` (clinical documentation),
`encounter-notes:sign` (note signing), `encounters:sign` (encounter
signing / appointment handoff), `encounters:invoice` (billing), and
`invoices:pay` (payment capture — the M1 billing spine's final WRITE), and
`invoices:show` (the claims-scoped single-invoice read) and
`invoices:payments` (the claims-scoped payment list for one invoice) — the
billing read spine, and `encounters:charges` (the claims-scoped charge list
for one encounter — the encounter billing read), and `encounters:show` (the
claims-scoped single-encounter read — completing the encounter read
surface), and `appointments:show` (the claims-scoped single-appointment
read — completing the booking read surface), and `appointments:index` (the
claims-scoped appointment LIST with the exact Laravel date/provider filters
and ordering), and `patients:search` (the candidate patient SEARCH with the
exact Laravel `q` validation, pg_trgm similarity ordering, and the
`patient.searched` audit), and `encounters:notes` (the claims-scoped
clinical-notes LIST for one encounter — the exact Laravel `orderBy('created_at')`
and the 6-field note map with the claims-scoped author ref), and
`patients:timeline` (the claims-scoped patient timeline read — the exact
Laravel `occurred_at DESC / id DESC` ordering and the 4-field entry map
with the structured jsonb `summary`), and `appointments:queue` (the live
front-desk queue read — the checked_in/in_consultation status filter, the
exact Laravel `token_no` ordering, the default-today `date`, and the
patient/encounter refs), and `patients:identifiers` (the claims-scoped
patient identity-document read — the exact Laravel `created_at DESC`
ordering, the 6-field identifier map with the encrypted-at-rest value
semantics, and NO status filter — active and superseded identifiers both
return), and `patients:contacts` (the claims-scoped patient contact read —
the exact Laravel `is_primary DESC / created_at ASC` ordering, the 7-field
contact map with the decoded jsonb `address`/`contactPerson` payloads, and
NO status filter — active and superseded contacts both return), and
`patients:insurance-policies` (the claims-scoped patient insurance-policy
read — the exact Laravel `created_at DESC` ordering, the 11-field policy map
with the eager `payer` ref, the decoded jsonb `benefits` payload, andNO status filter — active, expired and cancelled policies all return, under the
distinct `insurance:view` gate), and `patients:consents` (the claims-scoped
patient consent read — the exact Laravel `version DESC` ordering, the 9-field
consent map with the decoded jsonb `scope` payload and nullable
`revokedAt`/`revocationReason`, and NO status filter — active, expired and
revoked consents all return, under the distinct `consent:view` gate), and
`patients:documents` (the claims-scoped patient document-metadata read — the
exact Laravel `created_at DESC` ordering, the 10-field document map with
nullable metadata fields, and NO status filter — staged, available, archived
and purged documents all return, under the distinct `document:view` gate;
the storage pointer `objectKey` is DELIBERATELY not presented — object
storage does not exist yet, so no crypto boundary exists), and
`organizations:departments` (the organization-scoped department read — the
exact Laravel `name` ASC ordering, the 7-field department map with nullable
`facilityId`/`branchId`/`parentDepartmentId`, and NO status filter — active
and inactive departments all return, under the distinct `department:view`
gate; the FIRST edge read on the TENANT_FACILITY_BRANCH RLS tier — the
facility filter applies only for facility-scoped callers and the branch
clause narrows exactly like the RLS policy), and
`facilities:branches` (the facility-scoped branch read — the exact Laravel
`name` ASC ordering, the 5-field branch map where **`facilityId` renders
null** — the Laravel index query hydrates only id/name/code/status, so the
literal index output never carries the facility id — and NO status filter —
active and inactive branches all return, under the distinct `branch:view`
gate; branches is TENANT_ONLY, so the facility scoping is the query, not
RLS, exactly like `AccessCheck::facility`), and
`organizations:locations` (the organization-scoped location read — the
exact Laravel `name` ASC ordering, the 7-field location map with nullable
`facilityId`/`branchId` (HYDRATED real values — the index select includes
them) and the `type` field (store/waiting_area/nursing_station/
procedure_area/other), and NO status filter — active and inactive locations
all return, under the distinct `location:view` gate; the second edge read on
 the TENANT_FACILITY_BRANCH RLS tier — the facility filter applies only for
facility-scoped callers and the branch clause narrows exactly like the RLS
policy), and `organizations:wards` (the organization-scoped ward read — the
exact Laravel `name` ASC ordering, the 7-field ward map with nullable
`facilityId`/`branchId` (HYDRATED real values — the index select includes
them) and the `wardType` field (general/surgery/pediatric/icu/maternity/
other), and NO status filter — active and inactive wards all return, under
the distinct `ward:view` gate; the third edge read on the
TENANT_FACILITY_BRANCH RLS tier — the facility filter applies only for
facility-scoped callers and the branch clause narrows exactly like the RLS
policy). **The live
application is still guarded by
Sanctum**; these functions are the proven migration layer, not yet deployed.

## Layout

```
supabase/functions/
  health-auth/index.ts        # infrastructure: Deno adapter (thin; not run locally)
  me/index.ts                 # domain-safe identity/context: Deno adapter (thin)
  patients-contacts/index.ts    # patient contacts read: Deno adapter (thin)
  patients-consents/index.ts    # patient consents read: Deno adapter (thin)
  patients-documents/index.ts   # patient document metadata read: Deno adapter (thin)
  patients-identifiers/index.ts # patient identity documents read: Deno adapter (thin)
  patients-insurance-policies/index.ts # patient insurance policies read: Deno adapter (thin)
  organizations-departments/index.ts # organization-scoped departments read: Deno adapter (thin)
  facilities-branches/index.ts    # facility-scoped branches read: Deno adapter (thin)
  organizations-locations/index.ts # organization-scoped locations read: Deno adapter (thin)
  organizations-wards/index.ts    # organization-scoped wards read: Deno adapter (thin)
  organizations-rooms/index.ts    # organization-scoped rooms read: Deno adapter (thin)
  organizations-beds/index.ts     # organization-scoped beds read: Deno adapter (thin)
  organizations-staff/index.ts    # organization-scoped staff read: Deno adapter (thin)
  organizations-services/index.ts # organization-scoped services read: Deno adapter (thin)
  organizations-payers/index.ts  # organization-scoped payers read: Deno adapter (thin)
  organizations-medications/index.ts # organization-scoped formulary read: Deno adapter (thin)
  organizations-schedule-templates/index.ts # organization-scoped schedule-template read: Deno adapter (thin)
  organizations-schedule-exceptions/index.ts # organization-scoped schedule-exception read: Deno adapter (thin)
  facilities-settings/index.ts      # facility-scoped configuration read: Deno adapter (thin)
  patients-list/index.ts      # first read-only domain endpoint: Deno adapter (thin)
  patients-show/index.ts      # single-patient read endpoint: Deno adapter (thin)
  patients-search/index.ts    # candidate patient search: Deno adapter (thin)
  patients-timeline/index.ts  # patient timeline read: Deno adapter (thin)
  appointments-create/index.ts # first write endpoint: Deno adapter (thin)
  appointments-checkin/index.ts # second write endpoint: Deno adapter (thin)
  appointments-show/index.ts    # single-appointment read: Deno adapter (thin)
  appointments-index/index.ts   # appointment list: Deno adapter (thin)
  appointments-queue/index.ts   # live front-desk queue: Deno adapter (thin)
  encounters-create/index.ts    # encounter start / queue handoff: Deno adapter (thin)
  encounters-notes/index.ts     # clinical notes list: Deno adapter (thin)
  encounters-notes-draft/index.ts # clinical note draft: Deno adapter (thin)
  encounters-notes-sign/index.ts  # note signing: Deno adapter (thin)
  encounters-sign/index.ts        # encounter signing: Deno adapter (thin)
  encounters-invoice/index.ts     # invoice issue: Deno adapter (thin)
  encounters-charges/index.ts     # encounter charges read: Deno adapter (thin)
  encounters-show/index.ts        # single-encounter read: Deno adapter (thin)
  invoices-pay/index.ts           # payment capture: Deno adapter (thin)
  invoices-show/index.ts          # single-invoice read: Deno adapter (thin)
  invoices-payments/index.ts      # invoice payment list: Deno adapter (thin)
  _shared/
    errors.ts                 # EdgeError / JwtError / stable error codes
    jwt.ts                    # HS256 verify (WebCrypto) + local-only sign
    types.ts                  # server-side domain shapes (mirrors App\Models)
    identity.ts               # GoTrue sub → application user contract (UUID-checked)
    context.ts                # pure server-side context resolution
    claims.ts                 # the five authoritative RLS claims
    authorize.ts              # permission check (mirror of TenantContext::can)
    envelope.ts               # success/error envelope + correlation/request ids
    pipeline.ts               # THE shared authenticate→resolve→claims pipeline
    health_auth.ts            # pure health-auth handler on the pipeline (TESTED)
    me.ts                     # pure me/my-context handler on the pipeline (TESTED)
    patients_list.ts          # pure patients:list handler on the pipeline (TESTED)
    patients_show.ts          # pure patients:show handler on the pipeline (TESTED)
    patients_search.ts        # pure patients:search handler on the pipeline (TESTED)
    patients_timeline.ts      # pure patients:timeline handler on the pipeline (TESTED)
    appointments_create.ts    # pure appointments:create handler on the pipeline (TESTED)
    appointments_checkin.ts   # pure appointments:checkin handler on the pipeline (TESTED)
    appointments_show.ts      # pure appointments:show handler on the pipeline (TESTED)
    appointments_index.ts     # pure appointments:index handler on the pipeline (TESTED)
    appointments_queue.ts     # pure appointments:queue handler on the pipeline (TESTED)
    encounters_create.ts      # pure encounters:create handler on the pipeline (TESTED)
    encounter_notes_draft.ts  # pure encounter-notes:draft handler on the pipeline (TESTED)
    encounter_notes_sign.ts   # pure encounter-notes:sign handler on the pipeline (TESTED)
    encounters_sign.ts        # pure encounters:sign handler on the pipeline (TESTED)
    encounters_invoice.ts     # pure encounters:invoice handler on the pipeline (TESTED)
    encounters_charges.ts     # pure encounters:charges handler on the pipeline (TESTED)
    encounters_show.ts        # pure encounters:show handler on the pipeline (TESTED)
    encounter_notes_list.ts   # pure encounters:notes handler on the pipeline (TESTED)
    invoices_pay.ts           # pure invoices:pay handler on the pipeline (TESTED)
    invoices_show.ts          # pure invoices:show handler on the pipeline (TESTED)
    invoices_payments.ts      # pure invoices:payments handler on the pipeline (TESTED)
    harness/run.mjs           # dependency-free Node harness executing the TS
```

## The security contract (every function)

Request: `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id`
(optional); `X-Swasthya-Facility` / `X-Swasthya-Branch` are **proposals only**.

1. verify the JWT (alg pinned to HS256, constant-time signature check,
   expiry/issuer/audience);
2. extract `sub` (GoTrue user);
3. resolve `users.auth_subject_id = sub` → application user;
4. reject non-active identities (pending/locked/disabled → 403);
5. load ACTIVE role assignments, resolve context server-side
   (platform / support / tenant);
6. reject suspended organizations (403 TENANT_SUSPENDED);
7. validate facility/branch proposals against the assignments;
8. build the five claims: `app_user_id`, `app_tenant_id`, `app_facility_id`,
   `app_branch_id`, `app_is_platform`;
9. set `request.jwt.claims` on the least-privilege connection
   (`swasthya_app`, NOBYPASSRLS) — RLS is the final boundary;
10. authorize with the application permission model;
11. audit where required; return the standard Swasthya envelope.

**Client-supplied tenant/facility/branch/platform values NEVER become
claims.** The token's own `app_*` claims are ignored; only `sub` is read.

## `patients:list` — the first read-only domain endpoint (Phase 7)

`GET /functions/v1/patients-list`

| Aspect | Contract |
|---|---|
| **Request** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. No tenant/facility/branch scope is ever asserted by the client. |
| **Pipeline** | shared `authenticateRequest` → verified JWT → UUID `sub` → `users.auth_subject_id` → status gate → server-side context → five claims. Forged `app_*` claims and forged proposals are inert. |
| **Authorization** | `can(context, 'patient:view')` — the same capability as the Laravel route gate (`authorize:patient:view`); denial is `403 SCOPE_DENIED`. |
| **Data access** | `listPatients(claims)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set from the server-derived claims. The `p_rls_patients_select` policy (`tenant_id = claims.tenant AND facility_id = claims.facility`) is the FINAL boundary; the explicit WHERE is defense-in-depth only. An org-scoped context (no facility claim) sees zero rows — documented fail-closed behavior. |
| **Response** | Standard envelope: `data` = array of `{id, mrn, facilityId, fullName, dateOfBirth, sex, bloodGroup, status, createdAt, updatedAt}` (the existing `PatientController::present` contract); `meta = {context, count, claimsIssued}`; `links = {}`. |
| **Never returned** | JWT, JWT secret, passwords/hashes, service-role keys, permissions, assignments, identifiers/contacts/consents (raw PHI beyond the established list fields), DB credentials. |
| **Errors** | `401 INVALID_TOKEN/TOKEN_EXPIRED`, `403 FORBIDDEN` (inactive account), `403 TENANT_SUSPENDED`, `403 SCOPE_DENIED`, `403 FACILITY_DENIED` / `BRANCH_DENIED` (proposals), `500 SERVER_ERROR`. |

## `patients:show` — the single-patient read (Phase 8)

`GET /functions/v1/patients-show/<patientId>`

| Aspect | Contract |
|---|---|
| **Request** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. The patient id is a **resource selector only** — never authorization scope (mirror of Laravel `patients/{patient}`). |
| **Pipeline** | shared `authenticateRequest` → verified JWT → UUID `sub` → `users.auth_subject_id` → status gate → server-side context → five claims. Forged `app_*` claims and forged proposals are inert. |
| **Authorization** | `can(context, 'patient:view')` (same capability as `patients:list` and the Laravel gate); denial is `403 SCOPE_DENIED` before any lookup. |
| **Not-found semantics** | **Established project convention preserved** (`AccessCheck::scoped`, reads): an out-of-scope patient and a nonexistent patient are BOTH `404 NOT_FOUND` 'Resource not found.' — existence is never leaked, and a malformed/non-UUID id is also 404 (Laravel implicit-binding parity). |
| **Data access** | `showPatient(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set; `p_rls_patients_select` is the FINAL boundary (an out-of-scope row is filtered out → null → 404); the explicit scope WHERE is defense-in-depth. |
| **Response** | Standard envelope; `data` = the single `PatientRow` `{id, mrn, facilityId, fullName, dateOfBirth, sex, bloodGroup, status, createdAt, updatedAt}`; `meta = {context, claimsIssued}`; `links = {}`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit data, identifiers/contacts/consents, internal security fields. |
| **Errors** | `401 INVALID_TOKEN/TOKEN_EXPIRED`, `403 FORBIDDEN` (inactive/missing context), `403 TENANT_SUSPENDED`, `403 SCOPE_DENIED`, `403 FACILITY_DENIED`/`BRANCH_DENIED`, `404 NOT_FOUND` (missing/out-of-scope/malformed), `500 SERVER_ERROR`. |

## `appointments:create` — the first WRITE endpoint (Phase 9)

`POST /functions/v1/appointments-create`

Mirrors the established Laravel booking contract exactly (`AppointmentController::store`
+ `BookAppointmentRequest` + `SlotService` + the `uq_appointments_tenant_provider_start`
partial unique index). No second scheduling/slot/authorization/audit model — the
Laravel behavior is the source of truth; this function executes it through the
secure pipeline + the same RLS-scoped database.

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/appointments-create` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (never authoritative). |
| **Request JSON** | `{patientId: uuid*, providerStaffId: uuid*, serviceId?: uuid, startsAt: ISO-8601*, endsAt: ISO-8601* (after startsAt), appointmentType?: opd|follow_up|procedure|teleconsult (default opd), source?: counter|portal|walk_in (default counter)}` — strict: unknown fields → 422. |
| **Authorization** | `can(context, 'appointment:book')` (tenant-scoped, same as the Laravel gate); denial → `403 SCOPE_DENIED`. |
| **Context** | tenant/facility/branch come ONLY from the authoritative context/claims; `facility_id = context facility ?? provider.facility_id` (Laravel parity). |
| **Resource resolution** | patient + provider resolved through the RLS-scoped read path under the claims; not found OR out-of-scope → `404` ('Patient not found.' / 'Staff record not found.' — the runtime RLS behavior). |
| **Availability** | slot derived from `schedule_templates` (day, valid range, grid) minus `schedule_exceptions` minus holding appointments (booked/checked_in/in_consultation) — `SlotService` mirror; unavailable → `409 CONFLICT 'This slot is not available for booking — choose an open slot from availability.'` |
| **Race protection (final)** | the SAME partial unique index `uq_appointments_tenant_provider_start` (one live booking per tenant+provider+start). The transaction INSERT races on it; a unique violation → `409 CONFLICT 'This slot was just booked by someone else — choose another slot.'` The DB constraint is the arbiter — never a JS check. |
| **Transaction** | one `db.begin`: `set_config('request.jwt.claims', …)` → INSERT (status `booked`, `lock_version` 0, `created_by` = context user) → COMMIT; any failure rolls back (no partial record). |
| **Audit** | append-only `audit_events` row `appointment.booked` `{patientId, providerStaffId, startsAt}` attributed to the authenticated actor + authoritative tenant/facility + correlation id. |
| **Response (201)** | standard envelope; `data` = `{id, facilityId, patientId, patient{id,mrn,fullName}, providerStaffId, provider{id,fullName}, serviceId, appointmentType, startsAt, endsAt, status, tokenNo, source, cancelReason, lockVersion}` (the exact `AppointmentController::present` shape). |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` · `409 CONFLICT` (unavailable/raced slot) · `422 VALIDATION_ERROR` (Laravel-style `{field, code, message}` details) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, audit internals, internal security fields, unnecessary PHI. |

## `appointments:checkin` — the second WRITE endpoint (Phase 10)

`POST /functions/v1/appointments-checkin/<appointmentId>`

Mirrors `AppointmentController::checkIn` + `TokenIssuer` exactly — the patient
arrives, a queue token is issued by ROW-LOCKING the `token_counters` row per
(tenant, facility, provider, date), and the appointment moves to `checked_in`
via a GUARDED transition. No parallel queue workflow.

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/appointments-checkin/<appointmentId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | NONE — strict: any non-empty JSON body with fields → `422` (the client has nothing authoritative to send); malformed JSON → `400`. |
| **Identifier** | the appointment UUID is a resource selector only (mirror of `appointments/{appointment}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'appointment:checkin')` (same capability as the Laravel gate `authorize:appointment:checkin`); denial → `403 SCOPE_DENIED` before any mutation. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope appointment ≡ nonexistent → `404 NOT_FOUND` (AccessCheck::scoped parity — existence never leaked). |
| **Eligibility** | ONLY status `booked` may be checked in; every other status (checked_in, in_consultation, completed, cancelled, no_show) → `409 CONFLICT 'Only a booked appointment can be checked in (current status: X).'` — the exact Laravel message. |
| **Queue token** | `TokenIssuer` parity: `token_counters` row keyed by (tenant, facility, provider, date) created `ON CONFLICT DO NOTHING`, then locked `FOR UPDATE`; token = `last_token + 1`; counter updated. The row lock serializes parallel check-ins — no duplicate tokens possible. |
| **Race protection (final)** | the status transition is GUARDED (`UPDATE … SET status='checked_in', token_no=?, checked_in_by=?, checked_in_at=now(), lock_version=lock_version+1 WHERE id=? AND status='booked'`) in the SAME transaction as the token lock. Two concurrent check-ins of ONE appointment → exactly one success; the loser's guarded update matches zero rows → whole transaction rolls back (no token wasted) → `409 CONFLICT`. |
| **Transaction** | one `db.begin`: claims GUC → counter lock/mint → guarded appointment update → COMMIT; any failure rolls back (no partial mutation). |
| **Audit** | append-only `audit_events` row `appointment.checked_in` `{patientId, tokenNo, providerStaffId}` attributed to the authenticated actor + authoritative tenant/facility + correlation id. |
| **Response (200)** | standard envelope; `data` = the same `AppointmentController::present` shape as `appointments:create`, now with `status = 'checked_in'`, `tokenNo` populated, `lockVersion` incremented. |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed) · `409 CONFLICT` (ineligible status / raced check-in) · `422 VALIDATION_ERROR` (non-empty body fields) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, audit internals, internal claims, unnecessary PHI. |

## `encounters:create` — the third WRITE endpoint / encounter start (Phase 11)

`POST /functions/v1/encounters-create/<appointmentId>`

Mirrors `EncounterController::start` exactly — the doctor calls the patient
in; the encounter is created from the checked-in appointment (one encounter
per appointment, partial unique), and the appointment moves to
`in_consultation` via a GUARDED transition in the same transaction.

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/encounters-create/<appointmentId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | NONE — strict: any non-empty JSON body with fields → `422` (the client has nothing authoritative to send); malformed JSON → `400`. |
| **Identifier** | the appointment UUID is a resource selector only (mirror of `appointments/{appointment}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'encounter:create')` (same capability as the Laravel gate `authorize:encounter:create` — held by the doctor and org-admin roles); denial → `403 SCOPE_DENIED` before any lookup or mutation. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope appointment ≡ nonexistent → `404 NOT_FOUND` (AccessCheck::scoped parity — existence never leaked). |
| **Eligibility** | ONLY status `checked_in` may start an encounter; every other status (booked, in_consultation, completed, cancelled, no_show) → `409 CONFLICT 'An encounter can only be started from a checked-in appointment (current status: X).'` — the exact Laravel message. |
| **State machine** | `checked_in → in_consultation` (appointment) + `open` encounter (type `opd`) — EncounterController::start parity. Signed/amended/closed encounter states are later phases; this function never touches them. |
| **Encounter creation** | `tenant_id`, `facility_id`, `patient_id`, `appointment_id`, `provider_staff_id` ALL derived from the RLS-visible appointment; `type = 'opd'`, `status = 'open'`, `started_at = now()`, `lock_version = 0`, `created_by` = context user. |
| **Race protection (final)** | the appointment transition is GUARDED (`UPDATE … SET status='in_consultation', lock_version=lock_version+1 WHERE id=? AND tenant_id=? AND facility_id=? AND status='checked_in'`) and the encounter INSERT run in ONE transaction. Two concurrent starts of ONE appointment → exactly one success; the loser's guarded update matches zero rows → whole transaction rolls back (no orphan encounter) → `409 CONFLICT`. The partial unique index `uq_encounters_tenant_appointment` (one encounter per appointment) is the fail-closed DB backstop (mapped to 409, never 500). |
| **Transaction** | one `db.begin`: claims GUC → guarded appointment transition → encounter INSERT → COMMIT; any failure rolls back — NO partial encounter + appointment state. |
| **Audit** | append-only `audit_events` row `encounter.started` `{patientId, appointmentId, providerStaffId}` attributed to the authenticated actor + authoritative tenant/facility + correlation id. |
| **Response (201)** | standard envelope; `data` = `{id, facilityId, patientId, appointmentId, providerStaffId, type, status, startedAt, endedAt, signedAt, lockVersion}` — the exact `EncounterController::present` shape (ids only, no embedded refs). |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed) · `409 CONFLICT` (ineligible status / raced start) · `422 VALIDATION_ERROR` (non-empty body fields) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, audit internals, internal claims, unnecessary PHI. |

## `encounter-notes:draft` — the first clinical-documentation write (Phase 12)

`POST /functions/v1/encounters-notes-draft/<encounterId>`

Mirrors `EncounterController::storeNote` + `StoreClinicalNoteRequest`
exactly — the assigned clinician documents the visit as a structured draft.
Clinical-safety invariants preserved: **author_staff_id is ALWAYS the
encounter's provider staff row** (derived from the authenticated identity,
never from the client), and the signed/finalized immutability boundary is
enforced (drafts only on `open` encounters).

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/encounters-notes-draft/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request JSON** | `{noteType?: consultation|nursing|procedure|progress|discharge|other (default consultation), content: {<section>: string|null, ...}}` — `content` REQUIRED, a non-empty OBJECT of structured sections (never free-form blob), each value a string ≤10000 chars or null. Strict: unknown fields → `422` (`author_staff_id`/`tenant_id`/`facility_id` etc. are NOT_ALLOWED). |
| **Authorization** | `can(context, 'encounter:document')` (same capability as the Laravel gate `authorize:encounter:document`); denial → `403 SCOPE_DENIED`. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404 NOT_FOUND` (AccessCheck::scoped parity — existence never leaked). |
| **Clinical author rule** | `currentProvider` parity: the actor's ACTIVE staff record (staff.user_id = actor, tenant = encounter tenant, status ≠ departed) must BE the encounter's `provider_staff_id` — otherwise `403 SCOPE_DENIED 'Only the encounter provider can document this visit.'`. `author_staff_id` is derived server-side and can never be supplied by the client. |
| **Draft/finalized boundary** | drafts only: the encounter must be `open` (`guardNotSigned` parity) — any non-open status (signed/amended/closed) → `409 CONFLICT 'Clinical content cannot be added to a signed encounter — amendment is the only path (later phase).'`. Signed notes are immutable; amendments are new audited versions (later phase). |
| **Note creation** | `tenant_id`/`encounter_id` from the RLS-visible encounter; `note_type` validated against `chk_clinical_notes_type`; `content` jsonb; `status = 'draft'`, `lock_version = 0`, `created_by` = context user. Multiple drafts per encounter are PERMITTED by the schema (no unique index) — a plain INSERT, no race. |
| **Transaction** | one `db.begin`: claims GUC → draft INSERT → COMMIT; any failure rolls back (no partial note). |
| **RLS** | `clinical_notes` is a TENANT-ONLY claims table (`tenant_id = claims.tenant`); facility isolation for notes is enforced at the ENCOUNTER-lookup path (the note can only be attached to a facility-scoped, RLS-visible encounter). The composite FK `(tenant_id, author_staff_id) → staff` is the DB backstop against forged authors (23503 → 500, never a leak). |
| **Audit** | append-only `audit_events` row `note.drafted` `{encounterId, noteType, authorStaffId}` attributed to the authenticated actor + authoritative tenant/facility + correlation id. |
| **Response (201)** | standard envelope; `data` = `{id, noteType, author: {id, fullName}, content, status}` — the exact `EncounterController::storeNote` shape. |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability or author rule) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed) · `409 CONFLICT` (non-open encounter) · `422 VALIDATION_ERROR` (missing/invalid content, invalid noteType, unknown fields — Laravel-style `{field, code, message}` details) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, unnecessary PHI. |

## `encounter-notes:sign` — the note signing endpoint / immutability boundary (Phase 13)

`POST /functions/v1/encounters-notes-sign/<encounterId>/<noteId>`

Mirrors `EncounterController::signNote` exactly — the assigned provider signs
their OWN draft note; signed notes are immutable (a guarded transition is the
final arbiter).

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/encounters-notes-sign/<encounterId>/<noteId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | NONE — strict: any non-empty JSON body with fields → `422` (NOT_ALLOWED — the client can never forge `signed_at`, `status`, or `lock_version`); malformed JSON → `400`. |
| **Identifier** | encounter id + note id are resource selectors only (route parity with `encounters/{encounter}/notes/{note}/sign`); malformed/missing → `404`. |
| **Authorization** | `can(context, 'encounter:sign')` (same capability as the Laravel gate `authorize:encounter:sign`); denial → `403 SCOPE_DENIED`. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404`; the note must BELONG to the encounter — a note of a different encounter, an out-of-scope note, or a nonexistent note are ALL `404 NOT_FOUND 'Note not found on this encounter.'` (existence never leaked). |
| **Signer safety rules** | (1) the actor's ACTIVE staff record must BE the encounter's provider → else `403 SCOPE_DENIED 'Only the encounter provider can document this visit.'`; (2) the note's `author_staff_id` must be that provider → else `403 SCOPE_DENIED 'Only the note author can sign it.'` (both `currentProvider` + note-author parity, derived server-side). |
| **Eligibility / immutability** | ONLY a `draft` note may be signed → else `409 CONFLICT 'Only a draft note can be signed.'`. A signed note is immutable: the guarded transition matches zero rows thereafter. Note: the SIGN path deliberately has NO encounter-status guard in the Laravel implementation (unlike the draft path) — a draft note may be signed regardless of encounter status; preserved as-is. |
| **Signing transition** | GUARDED atomic UPDATE: `status = 'signed', signed_at = now(), lock_version = lock_version + 1, updated_at = now() WHERE id = … AND tenant_id = … AND encounter_id = … AND status = 'draft'` — `signed_at` is generated SERVER-SIDE by `now()`; the DB decides the state; a duplicate/concurrent sign matches zero rows and the whole transaction rolls back → `409` (never a JS check). |
| **Transaction** | one `db.begin`: claims GUC → guarded UPDATE → COMMIT; any failure rolls back (no partial mutation). |
| **RLS** | `clinical_notes` is a TENANT-ONLY claims table (see Phase 12); the note is only reachable through the facility-scoped, RLS-visible encounter. |
| **Audit** | append-only `audit_events` row `note.signed` `{encounterId, authorStaffId}` attributed to the authenticated actor + authoritative tenant/facility + correlation id. |
| **Response (200)** | standard envelope; `data` = `{id, status, signedAt}` — the exact `EncounterController::signNote` shape. |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability or signer rule) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed ids, note-not-on-encounter) · `409 CONFLICT` (non-draft note) · `422 VALIDATION_ERROR` (non-empty body fields) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, unnecessary PHI. |

## `encounters:sign` — the encounter signing endpoint / appointment handoff (Phase 14)

`POST /functions/v1/encounters-sign/<encounterId>`

Mirrors `EncounterController::sign` exactly — the assigned provider signs the
completed encounter; signed encounters are immutable history, and the linked
`in_consultation` appointment completes (M1: encounter → invoice readiness).

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/encounters-sign/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | NONE — strict: any non-empty JSON body with fields → `422` (NOT_ALLOWED — the client can never forge `ended_at`, `signed_at`, `signed_by`, `status`, or `lock_version`); malformed JSON → `400`. |
| **Identifier** | the encounter UUID is a resource selector only (mirror of `encounters/{encounter}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'encounter:sign')` (same capability as the Laravel gate `authorize:encounter:sign`); denial → `403 SCOPE_DENIED`. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404 NOT_FOUND` (existence never leaked). |
| **Signer safety rule** | `currentProvider` parity: the actor's ACTIVE staff record must BE the encounter's provider → else `403 SCOPE_DENIED 'Only the encounter provider can document this visit.'` (derived server-side). |
| **Eligibility** | ONLY an `open` encounter may be signed → else `409 CONFLICT 'Only an open encounter can be signed (current status: X).'`. The encounter must contain AT LEAST ONE SIGNED clinical note → else `409 CONFLICT 'An encounter must contain at least one signed note before signing.'` (note existence only — the note-author rule was enforced at note-sign time; the signed-note set can only grow, never shrink, so the check has no race). |
| **State machine** | encounter `open → signed` with `ended_at = now()`, `signed_at = now()`, `signed_by` = context user, `lock_version + 1` — all server-derived. Signed encounters are immutable: the guarded transition matches zero rows thereafter. |
| **Appointment handoff** | Laravel parity: IF the encounter has an appointment AND it is `in_consultation` → `status = 'completed'`, `lock_version + 1` (GUARDED `WHERE status = 'in_consultation'`); ANY other appointment state (already completed, null) is a SILENT SKIP — never an error. |
| **Race protection (final)** | the encounter transition is GUARDED (`WHERE id = … AND tenant_id = … AND facility_id = … AND status = 'open'`) in ONE transaction with the handoff — two concurrent signs of one encounter yield exactly one success; the loser's guarded update matches zero rows and the WHOLE transaction rolls back (no partial encounter/appointment state) → `409`. The DB, never JS, decides. |
| **Transaction** | one `db.begin`: claims GUC → guarded encounter update → guarded appointment handoff → COMMIT; any failure rolls back completely. |
| **RLS** | encounters + appointments are claims-scoped (tenant+facility); clinical_notes is tenant-only but only reachable via the RLS-visible encounter. All mutations run as `swasthya_app` (NOBYPASSRLS). |
| **Audit** | append-only `audit_events` row `encounter.signed` `{patientId, providerStaffId, appointmentId}` attributed to the authenticated actor + authoritative tenant/facility + correlation id; recorded exactly once on success, never on a failed/rolled-back sign. |
| **Response (200)** | standard envelope; `data` = `{id, facilityId, patientId, appointmentId, providerStaffId, type, status, startedAt, endedAt, signedAt, lockVersion}` — the exact `EncounterController::present` shape. |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability or signer rule) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed) · `409 CONFLICT` (non-open encounter / missing signed note / raced sign) · `422 VALIDATION_ERROR` (non-empty body fields) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, unnecessary PHI. |

**Deployed-wiring note:** every data dependency (including the Phase 7/8 read functions) runs its `set_config('request.jwt.claims', …)` and its query inside ONE transaction (`db.begin`). In autocommit mode a `SET LOCAL`-style GUC dies with the statement; the claims GUC must live for the whole operation or RLS fail-closes to zero rows. The PHP DB tier proves the in-transaction behavior; the harness never executes this wiring.

## `encounters:invoice` — the invoice issue endpoint / billing (Phase 15)

`POST /functions/v1/encounters-invoice/<encounterId>`

Mirrors `EncounterController::invoice` + `BillingService::issueInvoice`
exactly — the bill is built from posted charges of a SIGNED encounter
(consultation from the appointment's service rate + prescription-line
charges) and issued atomically with a server-generated invoice number
(M1: the first financial WRITE; invoice → payment is the next M1 step).

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/encounters-invoice/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | NONE — strict: any non-empty JSON body with fields → `422` (NOT_ALLOWED — the client can never forge `invoiceNumber`, amounts, totals, `status`, or scope fields); malformed JSON → `400`. |
| **Identifier** | the encounter UUID is a resource selector only (mirror of `encounters/{encounter}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'billing:invoice')` (same capability as the Laravel gate `authorize:billing:invoice`); denial → `403 SCOPE_DENIED`. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404 NOT_FOUND` (existence never leaked). |
| **Eligibility** | ONLY a `signed` encounter may be billed → else `409 CONFLICT 'Only a signed encounter can be billed.'` (exact Laravel message; signed is a terminal state, so the pre-check has no race). |
| **Charge derivation (server-side)** | 1) consultation charge from the appointment's service `default_charge_minor` (integer minor units — never floats), inserted ONLY when no encounter-source charge exists (idempotent); 2) prescription-line charges from ORDERED lines × medication `price_minor`, quantity = `max(1, quantity_minor ?? 1)`, inserted ONLY when the encounter's first prescription is not yet charged (Laravel parity). Cancelled lines and lines without a medication are excluded. |
| **Invoice construction** | built ONLY from posted charges; none → `409 CONFLICT 'This encounter has no charges to bill.'`. Totals: `total_minor` = Σ posted `amount_minor`; `total_tax_minor` = Σ `round(amount_minor × tax_rate_bps / 10000)` (0 for every auto-generated charge). Lines are frozen snapshots (description/amount/tax at issue time). |
| **Invoice number** | server-generated `INV-YYYYMMDD-XXXXX` (random 5 digits per day, retried while it exists — `BillingService::nextNumber` parity). |
| **Race protection (final)** | the whole issue is ONE transaction; `uq_invoices_tenant_number` (per-tenant unique invoice number) and the partial unique index `uq_invoice_lines_tenant_charge` (one charge → one invoice) are the DB-enforced backstops. A racing duplicate issue violates the charge index → `409 'One or more charges have already been invoiced.'` (the pre-check returns the same conflict sequentially); a racing number collision violates the number index → retryable `409 'The invoice number collided with a concurrent issue. Retry.'`. No duplicate invoice, no double-billing — the DB, never JS, decides. |
| **Transaction** | one `db.begin`: claims GUC → re-verify encounter still signed → derive + insert charges → load posted charge ids → already-invoiced pre-check → number generation → invoice + frozen lines INSERT → COMMIT; any failure rolls back completely (no partial charges/invoice/lines). |
| **RLS** | encounters + services + medications + charges + invoices are claims-scoped (tenant+facility); prescriptions/prescription_lines/invoice_lines are tenant-only. All mutations run as `swasthya_app` (NOBYPASSRLS). |
| **Audit** | append-only `audit_events` row `invoice.issued` `{patientId, encounterId, totalMinor, lineCount}` attributed to the authenticated actor + authoritative tenant/facility + correlation id; recorded exactly once on success, never on a failed/rolled-back issue. |
| **Response (201)** | standard envelope; `data` = `{id, invoiceNumber, status, totalMinor, totalTaxMinor, paidMinor, lines: [{description, amountMinor, taxMinor}]}` — the exact `EncounterController::invoice` shape (`status` always `issued`, `paidMinor` 0, `lock_version` never exposed). |
| **Errors** | `400 INVALID_REQUEST` (malformed body) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed) · `409 CONFLICT` (non-signed encounter / no charges / already invoiced / number collision) · `422 VALIDATION_ERROR` (non-empty body fields) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tax_rate_bps`, charge ids, or any line-level PHI beyond the frozen descriptions. |

**Billing-deviation (documented, safe):** Laravel surfaces a raw 500 when a
concurrent issue hits the unique-index races above (the `nextNumber` retry
loop is sequential-only). The edge function maps both races to clean
retryable/conflict 409s BEFORE any partial state — a strictly safer mapping
that keeps the DB as the final arbiter. The charge derivation and totals
are byte-for-byte Laravel-parity (integer minor units, `round(amount ×
bps / 10000)` tax math).

## `invoices:pay` — the payment capture endpoint / idempotent money (Phase 16)

`POST /functions/v1/invoices-pay/<invoiceId>`

Mirrors `BillingController::pay` + `BillingService::capturePayment` exactly —
a payment is captured against an issued invoice and allocated to it, with
idempotency-key replay protection and the invoice `lock_version`
optimistic-lock race resolved by the database (M1 billing spine complete:
issue → capture).

| Aspect | Contract |
|---|---|
| **Method / path** | `POST /functions/v1/invoices-pay/<invoiceId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body (STRICT — CapturePaymentRequest parity)** | `{method: required ∈ cash|card|wallet|bank|insurance, amountMinor: required integer ≥ 1, idempotencyKey: required string 8..100, providerRef?: optional string ≤ 100}`. Unknown fields → `422` (NOT_ALLOWED — the client can never forge `lockVersion`, `invoiceStatus`, `tenantId`, `facilityId`, `receivedBy`, or allocation ownership); missing required → `422` REQUIRED; invalid values → `422` INVALID_VALUE (exact Laravel-style messages); empty body → `422`; malformed JSON → `400`. |
| **Identifier** | the invoice UUID is a resource selector only (mirror of `invoices/{invoice}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'billing:collect')` — the exact Laravel gate `authorize:billing:collect` (NOT `billing:pay`, which does not exist in the seeded permission set); denial → `403 SCOPE_DENIED`. |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; out-of-scope invoice ≡ nonexistent → `404 NOT_FOUND` (existence never leaked). |
| **Idempotency (FIRST — Laravel parity)** | a request whose (tenant, `idempotencyKey`) already produced a payment REPLAYS that payment: `200` with `replayed: true`, the SAME `paymentId`, NO new money, NO eligibility checks (proven even against a now-paid invoice). The unique index `uq_payments_tenant_idempotency` is the concurrent backstop → retryable `409`. |
| **Eligibility (after idempotency)** | `voided` invoice → `409 'A voided invoice cannot be paid.'`; `paid_minor ≥ total_minor` → `409 'This invoice is already paid.'`; `amountMinor ≤ 0` → `422 'Payment amount must be positive.'`; `amountMinor > remaining` → `422 'Payment of X exceeds the outstanding balance of Y.'` — exact Laravel messages. |
| **Optimistic-lock race (final)** | the capture is ONE transaction: payment INSERT (currency `NPR`, status `captured`, `received_at`/`created_by` server-derived) + `payment_allocations` INSERT + the GUARDED update `paid_minor = paid_minor + amount, status = paid | partially_paid, lock_version = lock_version + 1 WHERE id = … AND tenant_id = … AND lock_version = <expected>` (the version the pre-read observed — Laravel parity). Two concurrent captures serialize here: exactly one wins; the loser matches zero rows and the WHOLE transaction rolls back (no orphan payment/allocation) → `409 LOCK_CONFLICT 'This invoice was changed by another payment. Reload and retry.'` — the DB, never JS, decides. |
| **Transaction** | one `db.begin`: claims GUC → idempotency lookup → eligibility → payment + allocation INSERT → guarded lock_version update → COMMIT; any failure (including the lock race) rolls back completely. |
| **RLS** | invoices + payments are claims-scoped (tenant+facility); payment_allocations is tenant-only. All mutations run as `swasthya_app` (NOBYPASSRLS). |
| **Audit** | append-only `audit_events` row `payment.captured` (new) or `payment.replayed` (replay) with the exact Laravel payload `{invoiceId, method, amountMinor, replayed}`, attributed to the authenticated actor + authoritative tenant/facility + correlation id; exactly once per request. |
| **Response** | `201` (new capture) / `200` (replay): `data` = `{paymentId, status, amountMinor, method, replayed, invoice: {id, invoiceNumber, status, totalMinor, paidMinor}}` — the exact `BillingController::pay` shape. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed invoice) · `409 CONFLICT` (voided / already paid / idempotency race) · `409 LOCK_CONFLICT` (optimistic-lock race) · `422 VALIDATION_ERROR` (request body) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `lock_version`, the `idempotency_key`, or allocation ownership. |

**Deviations (documented, safe):** 1) Laravel surfaces a raw 500 on the
concurrent same-key insert race (`uq_payments_tenant_idempotency`); the edge
maps it to a retryable 409 — a retry then REPLAYS the winner's payment.
2) The prompt-target capability was `billing:pay`, but the seeded permission
set names it `billing:collect` — the repository is authoritative and the
edge uses the real gate. Everything else is byte-for-byte Laravel parity
(including the idempotency-before-eligibility order and the exact conflict
messages).

## `invoices:show` — the single-invoice read / billing read spine (Phase 17)

`GET /functions/v1/invoices-show/<invoiceId>`

Mirrors `BillingController::showInvoice` + `presentInvoice` exactly — a
claims-scoped single-invoice READ returning the invoice header + its lines
ordered by `line_no`. The Laravel show contract carries NO payments or
allocations (those live on the separate `invoices/{invoice}/payments` route);
nothing is invented here.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/invoices-show/<invoiceId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Identifier** | the invoice UUID is a resource selector only (mirror of `invoices/{invoice}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'billing:view')` — the exact Laravel gate `authorize:billing:view`; denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; out-of-scope invoice ≡ nonexistent → `404 NOT_FOUND` 'Resource not found.' (AccessCheck::scoped, reads — existence is never leaked). |
| **Data access** | `showInvoice(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set; the claims-scoped `invoices` SELECT is the FINAL boundary (out-of-scope row → null → 404); the explicit scope WHERE is defense-in-depth. Lines are read under the same claims and ordered by `line_no`. |
| **Audit** | append-only `audit_events` row `invoice.viewed` with the exact Laravel payload `{patientId}` (BillingController::showInvoice parity — the Laravel contract audits reads too), attributed to the authenticated actor + authoritative tenant/facility + correlation id; exactly once per request. |
| **Response** | `200`: `data` = `{id, invoiceNumber, facilityId, patientId, status, totalMinor, totalTaxMinor, paidMinor, issuedAt, lockVersion, lines: [{id, description, amountMinor, taxMinor}]}` — the exact `presentInvoice` shape (header + ordered lines only). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed invoice) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, charge/catalog internals — and, per the Laravel show contract, NO payments/allocations. |
| **Mutation** | none — a pure read; no invoice/line/payment state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `invoices:payments` — the payment list for one invoice / billing read spine (Phase 18)

`GET /functions/v1/invoices-payments/<invoiceId>`

Mirrors `BillingController::payments` (the `invoices/{invoice}/payments`
route) exactly — the payments allocated to one invoice, presented as a
bare list and ordered by `allocated_at` ascending. This is the read
companion the Phase 17 show contract deliberately left on the separate
route.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/invoices-payments/<invoiceId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Identifier** | the invoice UUID is a resource selector only (mirror of `invoices/{invoice}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'billing:view')` — the exact Laravel gate `authorize:billing:view` (the same gate as `invoices:show`); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; out-of-scope invoice ≡ nonexistent → `404 NOT_FOUND` 'Resource not found.' (AccessCheck::scoped, reads — existence is never leaked). |
| **Data access** | `listInvoicePayments(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The claims-scoped `invoices` SELECT is the gate (out-of-scope → null → 404); the allocations read (payment_allocations is TENANT_ONLY) is bound to the verified invoice id and ordered by `allocated_at` ascending (`->orderBy('allocated_at')`); the payment `method` resolves under the SAME claims (payments is TENANT_FACILITY) via the LEFT JOIN — an allocation whose payment lives in another facility of the same tenant (or is missing) renders `method: null`, exactly like Laravel's `payment?->method`. |
| **Audit** | **none** — BillingController::payments records no audit event (unlike showInvoice's `invoice.viewed`); adding one would invent behavior. Proven: zero audit rows on success. |
| **Response** | `200`: `data` = the bare allocation list `[{paymentId, method, amountMinor, allocatedAt}]` — the exact `BillingController::payments` map — ordered by `allocatedAt`. `provider_ref` and `received_at` are loaded by Laravel but never presented. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed invoice) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `providerRef`, `receivedAt`, `receivedBy`, payment `status`, `idempotencyKey`, tenant/facility ids, charge/catalog internals. |
| **Mutation** | none — a pure read; no invoice/payment/allocation state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `encounters:charges` — the posted charges of one encounter (Phase 19)

`GET /functions/v1/encounters-charges/<encounterId>`

Mirrors `EncounterController::charges` (the `encounters/{encounter}/charges`
route) exactly — the charges posted on one encounter, presented as a bare
list and ordered by `charged_at` ascending. Completes the encounter billing
read surface that pairs with the Phase 15 invoice issue. (Phase 19 was
re-targeted from `invoices:list`, which does NOT exist in the Laravel source
of truth — the billing surface is exactly show / payments / pay.)

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/encounters-charges/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Identifier** | the encounter UUID is a resource selector only (mirror of `encounters/{encounter}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'billing:view')` — the exact Laravel gate `authorize:billing:view` (the same gate as `invoices:show` / `invoices:payments`); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404 NOT_FOUND` 'Resource not found.' (AccessCheck::scoped, reads — existence is never leaked). |
| **Data access** | `listEncounterCharges(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The claims-scoped `encounters` SELECT is the gate (out-of-scope → null → 404); the charges read (charges is TENANT_FACILITY) is bound to the verified encounter id and ordered by `charged_at` ascending (`->orderBy('charged_at')`, NULLS LAST). **All statuses return — including voided** (the Laravel hasMany applies no status filter; the presented status lets the client see them). |
| **Audit** | **none** — EncounterController::charges records no audit event; adding one would invent behavior. Proven: zero audit rows on success. |
| **Response** | `200`: `data` = the bare charge list `[{id, sourceType, description, amountMinor, currency, status, chargedAt}]` — the exact `EncounterController::charges` map — ordered by `chargedAt`. No invoice/patient/related data (the contract includes none). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed encounter) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, prescription/encounter/tenant/facility/patient ids, `taxRateBps`, attribution fields, charge catalog internals. |
| **Mutation** | none — a pure read; no charge/encounter state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `encounters:show` — the single-encounter read / encounter read spine (Phase 20)

`GET /functions/v1/encounters-show/<encounterId>`

Mirrors `EncounterController::show` + `present` (the `encounters/{encounter}`
route) exactly — the single-encounter READ returning the 11-field header
shape. Completes the encounter read surface (show, notes, charges).

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/encounters-show/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Identifier** | the encounter UUID is a resource selector only (mirror of `encounters/{encounter}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'encounter:view')` — the exact Laravel gate `authorize:encounter:view` (the same gate as the encounter notes read); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; out-of-scope encounter ≡ nonexistent → `404 NOT_FOUND` 'Resource not found.' (AccessCheck::scoped, reads — existence is never leaked). |
| **Data access** | `showEncounter(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set; the claims-scoped `encounters` SELECT is the FINAL boundary (out-of-scope row → null → 404); the explicit scope WHERE is defense-in-depth. The 11 `present()` columns are selected — NO related data (the Laravel show contract returns none). |
| **Audit** | append-only `audit_events` row `encounter.viewed` with the exact Laravel payload `{patientId}` (EncounterController::show parity — the Laravel contract audits reads), attributed to the authenticated actor + authoritative tenant/facility + correlation id; exactly once per request; none on failure. |
| **Response** | `200`: `data` = `{id, facilityId, patientId, appointmentId, providerStaffId, type, status, startedAt, endedAt, signedAt, lockVersion}` — the exact `present()` shape; the three ISO timestamps nullable (`?->toIso8601String()`), `appointmentId` nullable per schema. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed encounter) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `signedBy`, `createdBy`/`updatedBy`, notes/charges/invoice/patient objects. |
| **Mutation** | none — a pure read; no encounter/appointment/note/charge state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `appointments:show` — the single-appointment read / booking read spine (Phase 21)

`GET /functions/v1/appointments-show/<appointmentId>`

Mirrors `AppointmentController::show` + `present` (the
`appointments/{appointment}` route) exactly — the single-appointment READ
returning the 15-field `present()` shape (header + the nullable patient/provider
refs). Completes the booking read surface alongside the existing booking
writes.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/appointments-show/<appointmentId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Identifier** | the appointment UUID is a resource selector only (mirror of `appointments/{appointment}` implicit binding); missing/malformed → `404`. |
| **Authorization** | `can(context, 'appointment:view')` — the exact Laravel gate `authorize:appointment:view` (the same gate as the appointment index); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; out-of-scope appointment ≡ nonexistent → `404 NOT_FOUND` 'Resource not found.' (AccessCheck::scoped, reads — existence is never leaked). |
| **Data access** | `findAppointmentByScope(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set; the claims-scoped `appointments` SELECT is the FINAL boundary (out-of-scope row → null → 404); the explicit scope WHERE is defense-in-depth. The 15 `present()` columns are selected; the patient + provider REFS resolve under the SAME claims (`patients`/`staff`, both TENANT_FACILITY) — a related row outside the caller's scope renders `null` (established Phase 18 `payment?->method` parity), never an error and never a leak. |
| **Audit** | NONE — `AppointmentController::show` records no audit event (unlike `encounters:show`); a pure read with no audit chain (proven: zero audit rows on success). |
| **Response** | `200`: `data` = `{id, facilityId, patientId, patient, providerStaffId, provider, serviceId, appointmentType, startsAt, endsAt, status, tokenNo, source, cancelReason, lockVersion}` — the exact `present()` shape; `patient`/`provider` are `{id, mrn, fullName}` / `{id, fullName}` refs or `null`; `serviceId`/`tokenNo`/`cancelReason` nullable per schema; `startsAt`/`endsAt` non-null ISO timestamps. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/out-of-scope/malformed appointment) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `checkedInAt`/`checkedInBy`, `createdBy`/`updatedBy`, `createdAt`/`updatedAt`. |
| **Mutation** | none — a pure read; no appointment/patient/staff state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `appointments:index` — the claims-scoped appointment list (Phase 22)

`GET /functions/v1/appointments-index`

Mirrors `AppointmentController::index` (the `GET appointments` route)
exactly — the claims-scoped appointment LIST with the exact filters,
ordering, refs, and NO pagination.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/appointments-index` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Query parameters** | ONLY `date` (whereDate on `starts_at`, canonical `YYYY-MM-DD`) and `providerStaffId` (exact match), each applied only when present; absent = no filter; unknown parameters are IGNORED (the Laravel controller has no validation). |
| **Authorization** | `can(context, 'appointment:view')` — the exact Laravel gate `authorize:appointment:view` (the same gate as the appointment show); denial → `403 SCOPE_DENIED` before any query. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listAppointments(claims, filters)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The scope WHERE mirrors the RLS facilityClause exactly: a facility claim narrows to one facility; an org-level claim (facility NULL) sees EVERY facility of the tenant. The explicit WHERE is defense-in-depth — the claims-scoped policy is the FINAL boundary. |
| **Ordering** | `starts_at` ascending — the ONLY Laravel ordering key (`orderBy('starts_at')`); no secondary ordering. |
| **Pagination** | NONE — the controller uses a plain `->get()` and returns a bare array (`.values()` re-indexed); no page/per-page params, no total/count metadata. |
| **Related data** | the patient + provider refs resolve under the SAME claims (mirror of the eager-loaded `patient:id,mrn,full_name` / `provider:id,full_name` relations, implemented as LEFT JOINs in the same query) — a related row outside the caller's scope renders `null` (established Phase 18/21 parity), never a leak. |
| **Audit** | NONE — `AppointmentController::index` records no audit event; a pure read (proven: zero audit rows on success). |
| **Response** | `200`: `data` = a bare array of the exact 15-field `present()` items (same shape as `appointments:show`) ordered by `startsAt` ascending — `{id, facilityId, patientId, patient, providerStaffId, provider, serviceId, appointmentType, startsAt, endsAt, status, tokenNo, source, cancelReason, lockVersion}`. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `500 SERVER_ERROR` (a PRESENT-but-malformed `date`/`providerStaffId` — Laravel parity: the value reaches Postgres and fails the date/uuid column cast in whereDate()/where(), an unhandled 500; the edge fails closed deterministically; `date` accepted strictly as `YYYY-MM-DD`, a documented fail-closed subset of PG's lenient input). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `checkedInAt`/`checkedInBy`, `createdBy`/`updatedBy`, `createdAt`/`updatedAt`. |
| **Mutation** | none — a pure read; no appointment/patient/staff state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:search` — the candidate patient search (Phase 23)

`GET /functions/v1/patients-search?q=<term>`

Mirrors `PatientController::search` + `SearchPatientRequest` (the
`GET patients/search` route) exactly — the candidate patient SEARCH with
strict `q` validation, pg_trgm similarity ordering, a hard `limit(20)`, the
`patient.searched` audit, and the custom `meta.search.hint`.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-search?q=<term>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Query parameters** | ONLY `q` is accepted (SearchPatientRequest + ApiRequest strict mode — an unknown parameter is `422` `Field "…" is not allowed.`). `q` is `required` + `string` + `min:2` + `max:255` — validated on the RAW value, then TRIM-med server-side (`trim((string) validated('q'))`); failures are `422 VALIDATION_ERROR` with the exact ApiExceptionMapper detail codes (`REQUIRED`, `OUT_OF_RANGE`). |
| **Authorization** | `can(context, 'patient:search')` — the exact Laravel gate `authorize:patient:search` (a DISTINCT capability from `patient:view`); denial → `403 SCOPE_DENIED` before any query. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `searchPatients(claims, q)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The scope WHERE mirrors the RLS facilityClause exactly (`coalesce(?, facility_id) = facility_id`): a facility claim narrows to one facility; an org-level claim (facility NULL) searches the whole tenant — Laravel's `if (facilityId() !== null)` parity. The explicit WHERE is defense-in-depth — the claims-scoped policy is the FINAL boundary. |
| **Search semantics** | `status = 'active'` always; `lower(full_name) LIKE '%q%' OR lower(mrn) LIKE 'q%'` (case-insensitive substring / prefix; LIKE wildcards `%`/`_` intentionally UNESCAPED — Laravel parity, proven at the DB tier); score = pg_trgm `similarity(lower(full_name), q)` (the extension is created by the patients migration; the harness simulates the trigram formula, the real DB proves it); `ORDER BY score DESC`; hard `LIMIT 20` — NO pagination (no page/per-page, no total/count). |
| **Audit** | append-only `audit_events` row `patient.searched` with the exact Laravel payload `{resultCount}` — recorded on EVERY search (even empty results), resourceType `patient_search`, resourceId `null`, attributed to the actor + authoritative tenant/facility + correlation id. |
| **Response** | `200`: `data` = a bare array of the exact 7-field items `{id, mrn, fullName, dateOfBirth, sex, facilityId, score}` — the six identifier fields as strings, `score` rounded to 4 decimals; `meta.search.hint` = the exact Laravel strings (`N candidate(s) found — confirm identity before opening.` / `No candidates found.`). `status` is FILTERED but NEVER presented. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `422 VALIDATION_ERROR` (missing/short/over-long `q`, unknown query parameters — with `{field, code, message}` details) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, patient `status`, `bloodGroup`, `createdAt`/`updatedAt`, `email`. |
| **Mutation** | none — a pure read; no patient state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `encounters:notes` — the clinical notes of one encounter (Phase 25)

`GET /functions/v1/encounters-notes/<encounterId>`

Mirrors `EncounterController::notes` (the `GET encounters/{encounter}/notes`
route) exactly — the notes LIST of one encounter under the SAME gate as
`encounters:show`, with the exact Laravel `created_at` ordering, the exact
6-field note map, and the claims-scoped author ref.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/encounters-notes/<encounterId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the encounter UUID is a RESOURCE SELECTOR only — never authorization scope. A missing/malformed id is indistinguishable from a missing resource → `404` (Laravel implicit binding → ModelNotFoundException parity). |
| **Authorization** | `can(context, 'encounter:view')` — the exact Laravel gate `authorize:encounter:view` (the same gate as `encounters:show`); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listEncounterNotes(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The encounter gate SELECT is claims-scoped (encounters is TENANT_FACILITY) and decides 404; the notes SELECT is bound to the VERIFIED encounter id + the tenant claim (clinical_notes is TENANT_ONLY — the encounter gate already scoped the caller to this facility). Out-of-scope encounter ≡ nonexistent → `404 'Resource not found.'` (existence never leaked). |
| **Notes** | ALL notes of the encounter return — draft, signed, amended (the Laravel hasMany applies no status filter); ordered by `created_at` ASCENDING (the exact `->orderBy('created_at')`); the author REF resolves under the same claims (staff is TENANT_FACILITY) — an out-of-scope author renders `null`, never a leak (the established Phase 18/21 ref parity). |
| **Audit** | NONE — `EncounterController::notes` records no audit event (proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 6-field notes `{id, noteType, author, content, status, signedAt}` — `author` is the `{id, fullName}` ref (nullable), `content` is the decoded jsonb structured sections, `signedAt` nullable ISO. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND 'Resource not found.'` (malformed/nonexistent/out-of-scope encounter) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, `encounterId`, `authorStaffId`, `lockVersion`, `createdAt`/`updatedAt`, `parentNoteId`. |
| **Mutation** | none — a pure read; no encounter or note state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:timeline` — the patient-scoped timeline read (Phase 26)

`GET /functions/v1/patients-timeline/<patientId>`

Mirrors `PatientController::timeline` (the `GET patients/{patient}/timeline`
route) exactly — the patient timeline read under the SAME gate as
`patients:show`, with the exact Laravel `occurred_at DESC / id DESC`
ordering and the exact 4-field entry map.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-timeline/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope. A missing/malformed id is indistinguishable from a missing resource → `404` (Laravel implicit binding → ModelNotFoundException parity). |
| **Authorization** | `can(context, 'patient:view')` — the exact Laravel gate `authorize:patient:view` (the same gate as `patients:show`); denial → `403 SCOPE_DENIED` before any lookup. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientTimeline(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT is claims-scoped (patients is TENANT_FACILITY) and decides 404; the entries SELECT is bound to the VERIFIED patient id + the tenant claim (patient_timeline_entries is TENANT_ONLY — the patient gate already scoped the caller to this facility). Out-of-scope patient ≡ nonexistent → `404 'Resource not found.'` (existence never leaked). |
| **Entries** | ALL of the patient's timeline entries return, ordered by `occurred_at` DESCENDING then `id` DESCENDING (the exact `->orderByDesc('occurred_at')->orderByDesc('id')` — PostgreSQL's DESC default NULLS FIRST applies, proven in the harness); the structured `summary` is the decoded jsonb (the PatientTimelineEntry 'array' cast — the timeline carries facts/references, NEVER clinical content per the no-PHI rule). |
| **Audit** | NONE — `PatientController::timeline` records no audit event (proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 4-field entries `{id, occurredAt, eventType, summary}` — `occurredAt` nullable ISO, `summary` the structured jsonb payload. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND 'Resource not found.'` (malformed/nonexistent/out-of-scope patient) · `500 SERVER_ERROR`. |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, `patientId`, `actorId`, `correlationId`, `createdAt`/`updatedAt`. |
| **Mutation** | none — a pure read; no patient or timeline state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `appointments:queue` — the live front-desk queue read (Phase 27)

`GET /functions/v1/appointments-queue`

Mirrors `AppointmentController::queue` (the `GET appointments/queue` route)
exactly — the LIVE queue read (checked_in / in_consultation visits of one
date), with the exact Laravel `token_no` ordering, the default-today `date`,
and the patient/encounter refs under the `queue:view` gate.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/appointments-queue` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Query parameters** | `date` (whereDate on `starts_at`, DEFAULTING to the server-side today when absent — the exact `$request->query('date', today()->toDateString())`, resolved via the injected `todayIso` dependency) and `providerStaffId` (exact match, applied only when present — `$request->has(...)` parity). Unknown parameters are IGNORED (no validation in the controller). A PRESENT-but-malformed `date` / `providerStaffId` → `500 SERVER_ERROR` (the PG column-cast failure Laravel produces — the established appointments:index parity). |
| **Authorization** | `can(context, 'queue:view')` — the exact Laravel gate `authorize:queue:view`; denial → `403 SCOPE_DENIED` before any query. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listAppointmentQueue(claims, filters)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The scope WHERE mirrors the RLS facilityClause exactly (`coalesce(?, facility_id) = facility_id`): a facility claim narrows to one facility; an org-level claim (facility NULL) sees every facility of the tenant — Laravel's `if (facilityId() !== null)` parity. |
| **Status filter** | ALWAYS applied: `whereIn('status', ['checked_in', 'in_consultation'])` — booked/cancelled/completed/no_show visits never appear (the queue shows only LIVE visits). |
| **Ordering** | `orderBy('token_no')` ASCENDING — the exact Laravel order (PostgreSQL's ASC default NULLS LAST — proven in the harness and the DB tier). |
| **Entries** | ALL live visits of the date return; the patient ref `{id, mrn, fullName}` and the `encounterId` resolve under the SAME claims (mirror of the eager-loaded `patient:id,mrn,full_name,date_of_birth,sex` and `encounter:id,appointment_id` relations — only the presented fields leave the handler) — an out-of-scope related row renders `null` (established Phase 18/21 parity), never a leak. |
| **Audit** | NONE — `AppointmentController::queue` records no audit event (proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 6-field entries `{appointmentId, tokenNo, status, patient, startsAt, encounterId}` — `tokenNo` integer/null, `patient` the `{id, mrn, fullName}` ref (nullable), `startsAt` nullable ISO, `encounterId` nullable. |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `500 SERVER_ERROR` (malformed `date`/`providerStaffId`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, `patientId`, `providerStaffId`, `serviceId`, `cancelReason`, `lockVersion`, `createdAt`/`updatedAt`. |
| **Mutation** | none — a pure read; no appointment state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:identifiers` — the patient identity-document read (Phase 28)

`GET /functions/v1/patients-identifiers/<patientId>`

Mirrors `PatientIdentifierController::index` (the `GET
patients/{patient}/identifiers` route) exactly — the patient-scoped
identity-document read (national ID / passport / license / other), with the
exact Laravel `created_at DESC` ordering, NO status filter, and the
encrypted-at-rest value semantics under the `patient:view` gate.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-identifiers/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope; a missing/malformed id → `404 NOT_FOUND` (Laravel's implicit binding → ModelNotFoundException parity; out-of-scope ≡ nonexistent, existence never leaked). |
| **Authorization** | `can(context, 'patient:view')` — the exact Laravel gate `authorize:patient:view` (the same gate as `patients:show` / `patients:timeline`); denial → `403 SCOPE_DENIED` before any query. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientIdentifiers(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT (patients is TENANT_FACILITY) decides 404; the identifiers are read under the same claims (patient_identifiers is TENANT_ONLY) bound to the verified patient + tenant claim. |
| **Status filter** | NONE — both `active` and `superseded` identifiers return (the Laravel query has no status where; identifiers are superseded, never deleted). |
| **Ordering** | `orderByDesc('created_at')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 6-field map `{id, type, value, issuingCountry, isVerified, status}` — `type` ∈ national_id/passport/license/other; `value` is the DECRYPTED plaintext (the EncryptedString cast boundary — the RLS-scoped dependency carries the cast; live decryption REQUIRES REAL SUPABASE — the edge never holds the Laravel app key); `issuingCountry` nullable; `isVerified` boolean; `status` ∈ active/superseded. |
| **Audit** | NONE — `PatientIdentifierController::index` records no audit event (`patient.identifier.added` is the store-side write only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 6-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (malformed/nonexistent/out-of-scope patient — the exact `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`/`patientId`, `valueEncrypted` (ciphertext), `valueHash`, `verifiedBy`/`verifiedAt`/`createdBy`/`createdAt`/`updatedAt`. |
| **Mutation** | none — a pure read; no identifier state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:contacts` — the patient contact read (Phase 29)

`GET /functions/v1/patients-contacts/<patientId>`

Mirrors `PatientContactController::index` (the `GET
patients/{patient}/contacts` route) exactly — the patient-scoped contact
read (phone / email / address / emergency contact), with the exact Laravel
`is_primary DESC, created_at ASC` ordering, NO status filter, and the
decoded jsonb `address`/`contactPerson` payloads under the `patient:view`
gate.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-contacts/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope; a missing/malformed id → `404 NOT_FOUND` (Laravel's implicit binding → ModelNotFoundException parity; out-of-scope ≡ nonexistent, existence never leaked). |
| **Authorization** | `can(context, 'patient:view')` — the exact Laravel gate `authorize:patient:view` (the same gate as `patients:show` / `patients:timeline` / `patients:identifiers`); denial → `403 SCOPE_DENIED` before any query. |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientContacts(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT (patients is TENANT_FACILITY) decides 404; the contacts are read under the same claims (patient_contacts is TENANT_ONLY) bound to the verified patient + tenant claim. |
| **Status filter** | NONE — both `active` and `superseded` contacts return (the Laravel query has no status where; history is preserved by superseding, never deleting). |
| **Ordering** | `orderByDesc('is_primary')` then `orderBy('created_at')` — the exact Laravel order (boolean DESC → primary first; the ASC default NULLS LAST on the secondary key). |
| **Items** | the exact 7-field map `{id, type, value, address, contactPerson, isPrimary, status}` — `type` ∈ phone/email/address/emergency_contact; `value` plain nullable text (phone/email/emergency phone — NOT encrypted, no crypto boundary); `address` / `contactPerson` the DECODED jsonb payloads (the 'array' casts), nullable; `isPrimary` boolean; `status` ∈ active/superseded. The value/address XOR CHECK holds (exactly one carries the contact detail). |
| **Audit** | NONE — `PatientContactController::index` records no audit event (`patient.contact.added/updated` are the store/update-side writes only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 7-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (malformed/nonexistent/out-of-scope patient — the exact `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`/`patientId`, `validFrom`/`validTo`/`createdAt`/`createdBy`/`updatedBy`. |
| **Mutation** | none — a pure read; no contact state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:insurance-policies` — the patient insurance-policy read (Phase 30)

`GET /functions/v1/patients-insurance-policies/<patientId>`

Mirrors `InsurancePolicyController::index` (the `GET
patients/{patient}/insurance-policies` route) exactly — the patient-scoped
insurance-policy read (coverage under a payer), with the exact Laravel
`created_at DESC` ordering, NO status filter, the eager `payer` ref, and the
decoded jsonb `benefits` payload under the DISTINCT `insurance:view` gate.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-insurance-policies/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope; a missing/malformed id → `404 NOT_FOUND` (Laravel's implicit binding → ModelNotFoundException parity; out-of-scope ≡ nonexistent, existence never leaked). |
| **Authorization** | `can(context, 'insurance:view')` — the exact Laravel gate `authorize:insurance:view`, a DISTINCT capability from `patient:view` (held by the billing-clerk / receptionist / doctor / org-admin roles in the RolePermissionSeeder). A principal with `patient:view` alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientInsurancePolicies(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT (patients is TENANT_FACILITY) decides 404; the policies are read under the same claims (insurance_policies is TENANT_ONLY) bound to the verified patient + tenant claim; the payer ref resolves under the SAME tenant claim (payers is TENANT_ONLY — the eager `payer:id,name,code` parity) — an out-of-scope payer renders `null`, never a leak. |
| **Status filter** | NONE — `active`, `expired` AND `cancelled` policies return (the Laravel query has no status where; status is a lifecycle, never a deletion). |
| **Ordering** | `orderByDesc('created_at')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 11-field map `{id, patientId, payerId, payer, policyNumber, coverageType, validFrom, validTo, benefits, status, lockVersion}` — `patientId`/`payerId`/`lockVersion` ARE contract-explicit (the Laravel map presents them); `payer` the `{id, name, code}` ref, nullable; `validFrom` a date string (column NOT NULL); `validTo` nullable; `benefits` the DECODED jsonb payload (the 'array' cast); `status` ∈ active/expired/cancelled. No encrypted fields — `policy_number` is plain text, no crypto boundary. |
| **Audit** | NONE — `InsurancePolicyController::index` records no audit event (`patient.insurance.added/updated/cancelled` are the write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 11-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. `patient:view`-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (malformed/nonexistent/out-of-scope patient — the exact `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, `createdAt`/`createdBy`/`updatedBy`/`updatedAt`. (`patientId`/`payerId`/`lockVersion` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no policy state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:consents` — the patient consent read (Phase 31)

`GET /functions/v1/patients-consents/<patientId>`

Mirrors `ConsentController::index` (the `GET patients/{patient}/consents`
route) exactly — the patient-scoped consent read (treatment / data use /
telehealth / marketing / research), with the exact Laravel `version DESC`
ordering, NO status filter, and the decoded jsonb `scope` payload under the
DISTINCT `consent:view` gate.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-consents/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope; a missing/malformed id → `404 NOT_FOUND` (Laravel's implicit binding → ModelNotFoundException parity; out-of-scope ≡ nonexistent, existence never leaked). |
| **Authorization** | `can(context, 'consent:view')` — the exact Laravel gate `authorize:consent:view`, a DISTINCT capability from `patient:view` and `insurance:view` (held by the receptionist / doctor / org-admin roles in the RolePermissionSeeder — the seeded billing_clerk does NOT hold it). A principal with `patient:view`/`insurance:view` alone is DENIED — proven in the harness (the cashier actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientConsents(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT (patients is TENANT_FACILITY) decides 404; the consents are read under the same claims (consents is TENANT_ONLY) bound to the verified patient + tenant claim. |
| **Status filter** | NONE — `active`, `expired` AND `revoked` consents return (the Laravel query has no status where; the versioned lifecycle — a new capture expires the prior version, revocation is a state change — history outlives the consent). |
| **Ordering** | `orderByDesc('version')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 9-field map `{id, patientId, consentType, version, status, scope, givenAt, revokedAt, revocationReason}` — `patientId` IS contract-explicit (the Laravel map presents it); `consentType` ∈ treatment/data_use/telehealth/marketing/research; `version` integer; `status` ∈ active/revoked/expired; `scope` the DECODED jsonb payload (the 'array' cast); `givenAt` a nullable ISO timestamp (the column is NOT NULL, the `?->` keeps the nullable shape); `revokedAt` nullable ISO; `revocationReason` nullable. No encrypted fields — no crypto boundary. |
| **Audit** | NONE — `ConsentController::index` records no audit event (`patient.consent.captured/revoked` are the write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 9-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. `patient:view`/`insurance:view`-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (malformed/nonexistent/out-of-scope patient — the exact `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, `givenBy`/`revokedBy`/`documentId`/`createdAt`/`updatedAt`. (`patientId` IS the contract — never suppressed.) |
| **Mutation** | none — a pure read; no consent state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `patients:documents` — the patient document-metadata read (Phase 32)

`GET /functions/v1/patients-documents/<patientId>`

Mirrors `PatientDocumentController::index` (the `GET
patients/{patient}/documents` route) exactly — the patient-scoped
document-METADATA read (consent forms / IDs / referrals / reports), with the
exact Laravel `created_at DESC` ordering, NO status filter, and the nullable
metadata fields under the DISTINCT `document:view` gate. Object storage does
NOT exist yet (SECURITY.md §12 design only) — records are honestly `staged`
with no object key and `objectKey` is deliberately NOT presented: no crypto
boundary exists in this contract.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/patients-documents/<patientId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the patient UUID is a RESOURCE SELECTOR only — never authorization scope; a missing/malformed id → `404 NOT_FOUND` (Laravel's implicit binding → ModelNotFoundException parity; out-of-scope ≡ nonexistent, existence never leaked). |
| **Authorization** | `can(context, 'document:view')` — the exact Laravel gate `authorize:document:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` (held by the receptionist / doctor / org-admin roles in the RolePermissionSeeder — the seeded billing_clerk does NOT hold it). A principal with `patient:view`/`insurance:view` alone is DENIED — proven in the harness (the cashier actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listPatientDocuments(claims, id)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The patient gate SELECT (patients is TENANT_FACILITY) decides 404; the documents are read under the same claims (patient_documents is TENANT_ONLY) bound to the verified patient + tenant claim. |
| **Status filter** | NONE — `staged`, `available`, `archived` AND `purged` documents return (the Laravel query has no status where; the lifecycle statuses — no object storage yet, records are honestly `staged`). |
| **Ordering** | `orderByDesc('created_at')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 10-field map `{id, patientId, documentType, mimeType, sizeBytes, checksum, status, uploadedAt, expiresAt, retentionClass}` — `patientId` IS contract-explicit (the Laravel map presents it); `documentType` ∈ consent/id/referral/report/discharge/other; `mimeType`/`sizeBytes`/`checksum`/`expiresAt`/`retentionClass` nullable (staged metadata); `status` ∈ staged/available/archived/purged; `uploadedAt`/`expiresAt` nullable ISO timestamps. **`objectKey` is DELIBERATELY ABSENT** — the Laravel contract does not present the storage pointer (no object storage yet) — it never crosses this boundary; `checksum` is a plain hash string, NO encrypted fields, NO crypto boundary. |
| **Audit** | NONE — `PatientDocumentController::index` records no audit event (`patient.document.added` is the store-side write only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 10-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. `patient:view`/`insurance:view`-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (malformed/nonexistent/out-of-scope patient — the exact `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`/`facilityId`, **`objectKey`** (the storage pointer), `uploadedBy`/`parentDocumentId`/`createdAt`/`updatedAt`. (`patientId` IS the contract — never suppressed.) |
| **Mutation** | none — a pure read; no document state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:departments` — the organization-scoped department read (Phase 33)

`GET /functions/v1/organizations-departments/<organizationId>`

Mirrors `DepartmentController::index` (the `GET
organizations/{organization}/departments` route) exactly — the
organization-scoped catalog read (OPD, surgery, pharmacy…), with the exact
Laravel `name` ASC ordering, the facility filter applied ONLY for
facility-scoped callers, NO status filter (active AND inactive), under the
DISTINCT `department:view` gate. Phase 33 introduces the
**TENANT_FACILITY_BRANCH** RLS tier to the edge reads — the department read
is branch-scoped exactly like the Laravel/RLS model (`branch_id IS NULL OR
branch_id = BRANCH OR BRANCH IS NULL`).

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-departments/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (the branch proposal narrows the TENANT_FACILITY_BRANCH read). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'department:view')` — the exact Laravel gate `authorize:department:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` (held by the org_admin / hospital_admin / branch_manager / support_agent roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with `patient:view`+`insurance:view`+`consent:view`+`document:view` alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; `listOrganizationDepartments(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the departments are read under the claims (departments is **TENANT_FACILITY_BRANCH** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` departments return (the Laravel query has no status where; the catalog statuses; soft-deleted rows excluded). |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 7-field map `{id, facilityId, branchId, name, code, status, parentDepartmentId}` — `facilityId` / `branchId` / `parentDepartmentId` ARE contract-explicit and nullable (the Laravel map presents them; the self-FK parent ref is never suppressed); `status` ∈ active/inactive; the explicit 7-column SELECT is the present() projection — tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `DepartmentController::index` records no audit event (`department.created` / `department.updated` / `department.deleted` are write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 7-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. `patient:view`/`insurance:view`/`consent:view`/`document:view`-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`branchId`/`parentDepartmentId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no department state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `facilities:branches` — the facility-scoped branch read (Phase 34)

`GET /functions/v1/facilities-branches/<facilityId>`

Mirrors `BranchController::index` (the `GET facilities/{facility}/branches`
route) exactly — the facility-scoped branch read (wings, satellite
counters, dedicated units), with the exact Laravel `name` ASC ordering, the
query-bound facility scope, NO status filter (active AND inactive), under
the DISTINCT `branch:view` gate. Phase 34 is the facility-scoped sibling of
Phase 33: `AccessCheck::facility` (not `organization`), and branches is
**TENANT_ONLY** RLS — the facility scoping is the QUERY, not a policy.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/facilities-branches/<facilityId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only. |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the facility id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Facility not found.'` (the exact `AccessCheck::facility` throw — `Facility::find($id)` → null); a KNOWN facility outside the caller's scope — another tenant, OR a facility-scoped principal requesting another facility → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'branch:view')` — the exact Laravel gate `authorize:branch:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listFacilityBranches(claims, facilityId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The facility gate resolves the facility (`AccessCheck::facility` — out-of-tenant and out-of-facility-scope both deny(read); platform callers bypass; org-level claims may read any in-tenant facility). The branches are bound to the VERIFIED facility id (branches is **TENANT_ONLY** — select policy `tenant_id = TENANT`; the facility scoping IS the query, the exact `->where('facility_id', $facility->getKey())`). |
| **Status filter** | NONE — `active` AND `inactive` branches return (the Laravel query has no status where; the lifecycle statuses; soft-deleted rows excluded). |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 5-field map `{id, facilityId, name, code, status}` — **`facilityId` renders NULL**: the Laravel index query hydrates ONLY `['id', 'name', 'code', 'status']`, so `present()` reads an un-hydrated `facility_id` attribute → null (the LITERAL index output; the store/show routes hydrate the full model and return the real facility id). `status` ∈ active/inactive; the exact 4-column SELECT is the index projection — tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `BranchController::index` records no audit event (`branch.created` / `branch.updated` / `branch.deleted` are write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 5-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent facility — `Facility not found.`; out-of-scope facility — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId` IS in the map — as null, the literal Laravel index output.) |
| **Mutation** | none — a pure read; no branch state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:locations` — the organization-scoped location read (Phase 35)

`GET /functions/v1/organizations-locations/<organizationId>`

Mirrors `LocationController::index` (the `GET
organizations/{organization}/locations` route) exactly — the
organization-scoped location read (waiting areas, stores, nursing stations,
procedure areas), with the exact Laravel `name` ASC ordering, the facility
filter applied ONLY for facility-scoped callers, NO status filter (active
AND inactive), under the DISTINCT `location:view` gate. Phase 35 is the
org-selector sibling of Phase 33 on the same **TENANT_FACILITY_BRANCH** RLS
tier — the department/location reads share the gate family, the
`AccessCheck::organization` semantics, and the branch clause.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-locations/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (the branch proposal narrows the TENANT_FACILITY_BRANCH read). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'location:view')` — the exact Laravel gate `authorize:location:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; `listOrganizationLocations(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the locations are read under the claims (locations is **TENANT_FACILITY_BRANCH** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` locations return (the Laravel query has no status where; the catalog statuses; soft-deleted rows excluded). |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 7-field map `{id, facilityId, branchId, name, code, type, status}` — `facilityId` / `branchId` ARE contract-explicit and nullable, AND **HYDRATED real values** (the index select includes them — unlike the Phase 34 branches read where `facilityId` renders null); `type` ∈ store/waiting_area/nursing_station/procedure_area/other; `status` ∈ active/inactive; the explicit 7-column SELECT is the present() projection — tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `LocationController::index` records no audit event (`location.created` / `location.updated` / `location.deleted` are write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 7-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`branchId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no location state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:wards` — the organization-scoped ward read (Phase 36)

`GET /functions/v1/organizations-wards/<organizationId>`

Mirrors `WardController::index` (the `GET
organizations/{organization}/wards` route) exactly — the
organization-scoped ward read (general, surgery, pediatric, ICU, maternity,
other wards), with the exact Laravel `name` ASC ordering, the facility
filter applied ONLY for facility-scoped callers, NO status filter (active
AND inactive), under the DISTINCT `ward:view` gate. Phase 36 is the third
org-selector sibling of Phases 33/35 on the same **TENANT_FACILITY_BRANCH**
RLS tier — the department/location/ward reads share the gate family, the
`AccessCheck::organization` semantics, and the branch clause.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-wards/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (the branch proposal narrows the TENANT_FACILITY_BRANCH read). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'ward:view')` — the exact Laravel gate `authorize:ward:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; `listOrganizationWards(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the wards are read under the claims (wards is **TENANT_FACILITY_BRANCH** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` wards return (the Laravel query has no status where; the lifecycle statuses; soft-deleted rows excluded). |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 7-field map `{id, facilityId, branchId, name, code, wardType, status}` — `facilityId` / `branchId` ARE contract-explicit and nullable, AND **HYDRATED real values** (the index select includes them); `wardType` ∈ general/surgery/pediatric/icu/maternity/other; `status` ∈ active/inactive; the explicit 7-column SELECT is the present() projection (`ward_type` mapped to `wardType`; the `settings` jsonb is NOT selected — the Laravel contract does not present it) — tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `WardController::index` records no audit event (`ward.created` / `ward.updated` / `ward.deleted` are write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 7-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`branchId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no ward state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:rooms` — the organization-scoped room read (Phase 37)

`GET /functions/v1/organizations-rooms/<organizationId>`

Mirrors `RoomController::index` (the `GET
organizations/{organization}/rooms` route) exactly — the
organization-scoped room read (general, private, semi-private, ICU, other
rooms), with the exact Laravel `name` ASC ordering, the eager ward ref
(`with('ward:id,code,name')`), the facility filter applied ONLY for
facility-scoped callers, NO status filter (active AND inactive), under the
DISTINCT `room:view` gate. Phase 37 is the fourth org-selector sibling of
Phases 33/35/36 on the same **TENANT_FACILITY_BRANCH** RLS tier — the
department/location/ward/room reads share the gate family, the
`AccessCheck::organization` semantics, and the branch clause.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-rooms/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (the branch proposal narrows the TENANT_FACILITY_BRANCH read). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'room:view')` — the exact Laravel gate `authorize:room:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; `listOrganizationRooms(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the rooms are read under the claims (rooms is **TENANT_FACILITY_BRANCH** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` rooms return (the Laravel query has no status where; the lifecycle statuses; soft-deleted rows excluded). |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 11-field map `{id, facilityId, branchId, wardId, ward, name, code, roomType, dailyRateMinor, currency, status}` — `facilityId` / `wardId` are NOT NULL in the base schema and `branchId` is nullable (tenancy_v2), all THREE HYDRATED real values (the controller performs NO partial select — the full model hydrates); `ward` = the eager ref `{id, code, name}` (the exact `with('ward:id,code,name')`; null only if the composite-FK relation cannot resolve); `roomType` ∈ general/private/semi_private/icu/other; `dailyRateMinor` (integer minor units) and `currency` (3-char) nullable; `status` ∈ active/inactive; tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `RoomController::index` records no audit event (`room.created` / `room.updated` / rate-change events are write-side only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 11-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`branchId`/`wardId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no room state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:beds` — the organization-scoped bed read (Phase 38)

`GET /functions/v1/organizations-beds/<organizationId>`

Mirrors `BedController::index` (the `GET
organizations/{organization}/beds` route) exactly — the
organization-scoped bed read (the allocatable inpatient-capacity units),
with the exact Laravel `bed_code` ASC ordering, the eager room ref
(`with('room:id,code,name,ward_id')` — presented as id/code/name), the
facility filter applied ONLY for facility-scoped callers, NO status filter
(every lifecycle status), under the DISTINCT `bed:view` gate. Phase 38 is
the fifth org-selector sibling of Phases 33/35/36/37 on the same
**TENANT_FACILITY_BRANCH** RLS tier — the
department/location/ward/room/bed reads share the gate family, the
`AccessCheck::organization` semantics, and the branch clause. Beds are the
one catalog resource that is **NEVER soft-deleted** — `out_of_service` is a
status, not a deletion (no `deleted_at` column).

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-beds/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only (the branch proposal narrows the TENANT_FACILITY_BRANCH read). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'bed:view')` — the exact Laravel gate `authorize:bed:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility/branch exclusively from the authoritative context/claims; `listOrganizationBeds(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the beds are read under the claims (beds is **TENANT_FACILITY_BRANCH** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)`), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `available` / `occupied` / `reserved` / `cleaning` / `out_of_service` all return (the BedStatus state machine; the Laravel query has no status where). Beds are NEVER soft-deleted — `out_of_service` is a status, not a deletion (no `deleted_at` column; the read has NO deleted_at filter). |
| **Ordering** | `orderBy('bed_code')` — the exact Laravel order (no secondary key; lexicographic string order). |
| **Items** | the exact 8-field map `{id, facilityId, branchId, roomId, room, bedCode, status, lockVersion}` — `facilityId` / `roomId` are NOT NULL in the base schema and `branchId` is nullable (tenancy_v2), all three HYDRATED real values (the controller performs NO partial select — the full model hydrates); `room` = the eager ref `{id, code, name}` (the exact `with('room:id,code,name,ward_id')`; null only if the composite-FK relation cannot resolve — the eager load's `ward_id` is never presented); `bedCode` is the string(20) bed code; `status` ∈ available/occupied/reserved/cleaning/out_of_service; `lockVersion` is the optimistic-locking counter (bigint, default 0) — **CONTRACT-EXPLICIT** (Laravel presents it, so it is presented here); tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `BedController::index` records no audit event (`bed.created` / `bed.updated` / state-transition events are write-side only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 8-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`, `createdBy`/`updatedBy`, `currentAdmissionId`. (`facilityId`/`branchId`/`roomId`/`lockVersion` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no bed state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:staff` — the organization-scoped staff read (Phase 39)

`GET /functions/v1/organizations-staff/<organizationId>`

Mirrors `StaffController::index` (the `GET
organizations/{organization}/staff` route) exactly — the
organization-scoped staff read (employment/clinical identity within a
tenant), with the exact Laravel `full_name` ASC ordering, the eager
department ref (`with('department:id,code,name')` — presented as
id/code/name), the facility filter applied ONLY for facility-scoped
callers, NO status filter (active/on_leave/departed), under the DISTINCT
`staff:view` gate. Phase 39 is the sixth org-selector sibling of Phases
33/35/36/37/38 but on a **DIFFERENT RLS tier**: staff is
**TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH) — staff has NO `branch_id`
column, so the select policy is `tenant_id = TENANT AND (facility_id =
FACILITY OR FACILITY IS NULL)` with NO branch clause (a branch proposal is
accepted but does NOT narrow the read — proven in the harness and the DB
tier). Staff are the second catalog resource that is **NEVER soft-deleted**
— `departed` is a status, not a deletion (no `deleted_at` column). The
`license_number_encrypted` column (EncryptedString ciphertext at rest) is
NEVER selected or presented — the Laravel index map does not include it; no
crypto boundary is crossed.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-staff/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (staff is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'staff:view')` — the exact Laravel gate `authorize:staff:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listOrganizationStaff(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the staff are read under the claims (staff is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause, staff has no `branch_id` column), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` / `on_leave` / `departed` all return (the Staff status lifecycle; the Laravel query has no status where). Staff are NEVER soft-deleted — `departed` is a status, not a deletion (no `deleted_at` column; the read has NO deleted_at filter). |
| **Ordering** | `orderBy('full_name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 10-field map `{id, facilityId, departmentId, department, employeeCode, fullName, designation, status, userId, hireDate}` — `facilityId` / `departmentId` are NOT NULL in the base schema and HYDRATED real values (the controller performs NO partial select — the full model hydrates); `department` = the eager ref `{id, code, name}` (the exact `with('department:id,code,name')`; null if the composite-FK relation cannot resolve — e.g. a soft-deleted department, never a leak); `employeeCode` is the string(50) employee code; `designation` / `userId` (plain FK to the global users catalog) / `hireDate` nullable — `hireDate` serialized with the date cast's `toDateString()` → `YYYY-MM-DD`; `status` ∈ active/on_leave/departed; tenant/audit/timestamp metadata AND the `license_number_encrypted` ciphertext NEVER leave the read. |
| **Audit** | NONE — `StaffController::index` records no audit event (`staff.created` / `staff.updated` / status-transition events are write-side only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 10-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`, `createdBy`/`updatedBy`, `licenseNumberEncrypted`/`licenseNumber`/plaintext license numbers, `settings`. (`facilityId`/`departmentId`/`userId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no staff state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:services` — the organization-scoped services read (Phase 40)

`GET /functions/v1/organizations-services/<organizationId>`

Mirrors `ServiceController::index` (the `GET
organizations/{organization}/services` route) exactly — the
organization-scoped service-catalog read (OPD consultation, procedure,
investigation, follow-up, other), with the exact Laravel `name` ASC
ordering, the eager department ref (`with('department:id,code,name')` —
presented as id/code/name), the facility filter applied ONLY for
facility-scoped callers, NO status filter (active AND inactive), under the
DISTINCT `service:view` gate. Phase 40 is the seventh org-selector sibling
of Phases 33/35/36/37/38/39 on the **TENANT_FACILITY** RLS tier (like staff
— services has NO `branch_id` column, so the select policy is `tenant_id =
TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch
clause; a branch proposal is accepted but does NOT narrow the read — proven
in the harness and the DB tier). Unlike staff/beds, services **ARE
soft-deletable** — the SoftDeletes model scope excludes `deleted_at`-set
rows, reproduced exactly in the read. The catalog carries money fields
(`default_charge_minor` — integer minor units, never floats, DATABASE.md
§0.4) and `default_duration_minutes`.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-services/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (services is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'service:view')` — the exact Laravel gate `authorize:service:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` (held by the support_agent / org_admin / hospital_admin / branch_manager roles in the RolePermissionSeeder — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it). A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listOrganizationServices(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the services are read under the claims (services is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause, services has no `branch_id` column), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` both return (the catalog statuses; the Laravel query has no status where). **Services ARE soft-deletable** — the SoftDeletes model scope excludes `deleted_at`-set rows (`s.deleted_at is null` in the SELECT — the exact default-scope parity), so soft-deleted services never return. |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 10-field map `{id, facilityId, departmentId, department, name, code, serviceType, status, defaultDurationMinutes, defaultChargeMinor, currency}` — `facilityId` is NOT NULL in the base schema and HYDRATED (real value); `departmentId` is NULLABLE (the composite FK allows NULL — a service may be department-less) and contract-explicit; `department` = the eager ref `{id, code, name}` (the exact `with('department:id,code,name')`; null when departmentId is null or the relation cannot resolve — e.g. a soft-deleted department); `serviceType` ∈ opd_consultation/procedure/investigation/follow_up/other; `status` ∈ active/inactive; `defaultDurationMinutes` (integer) / `defaultChargeMinor` (integer minor units — never floats) / `currency` (3-char) all nullable; tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `ServiceController::index` records no audit event (`service.created` / `service.updated` / `service.deleted` are write-side events only; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 10-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`departmentId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no service state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:payers` — the organization-scoped payer read (Phase 41)

`GET /functions/v1/organizations-payers/<organizationId>`

Mirrors `PayerController::index` (the `GET
organizations/{organization}/payers` route) exactly — the organization-scoped
payer-catalog read (government/private/tpa/other payers), with the exact
Laravel `name` ASC ordering, **NO status filter** (active AND inactive),
under the DISTINCT `payer:view` gate. Phase 41 is the eighth org-selector
sibling of Phases 33/35/36/37/38/39/40 — but on the **TENANT_ONLY** RLS
tier, the SIMPLEST tier: payers has **NO `facility_id` column at all** (a
policy covers a patient at ANY facility of the tenant), so the select
policy is just `tenant_id = TENANT` — there is NO facility clause AND NO
facility filter in the Laravel query (the `! isPlatform &&
facilityId() !== null` guard is ABSENT, so even a facility-scoped caller
sees every tenant payer — the material TENANT_ONLY difference from the
TENANT_FACILITY staff/services reads, proven in the harness and the DB
tier). Payers have **NO SoftDeletes** — nothing is ever excluded. The
catalog carries no money fields and no relations — the read is the bare
5-field present() map.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-payers/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (payers is TENANT_ONLY — no facility/branch dimension at all). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'payer:view')` — the exact Laravel gate `authorize:payer:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` / `service:view`. The RolePermissionSeeder grants it to support_agent / org_admin / org_finance / hospital_admin / branch_manager / receptionist / billing_clerk — the seeded doctor / nurse roles do NOT hold it. A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor). |
| **Scope** | tenant exclusively from the authoritative context/claims; `listOrganizationPayers(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the payers are read under the claims (payers is **TENANT_ONLY** — select policy `tenant_id = TENANT` — NO facility clause, NO branch clause, payers has neither column), bound to the verified organization id. The Laravel query has NO facility filter at all (the `! isPlatform && facilityId() !== null` guard is ABSENT) — even a facility-scoped caller sees every tenant payer. |
| **Status filter** | NONE — `active` AND `inactive` both return (the catalog statuses; the Laravel query has no status where). Payers have **NO SoftDeletes** — nothing is ever excluded. |
| **Ordering** | `orderBy('name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 5-field map `{id, name, code, payerType, status}` — `payerType` ∈ government/private/tpa/other; `status` ∈ active/inactive; NO facility/branch field (payers has no `facility_id` column); tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `PayerController::index` records no audit event (`payer.created` is the only audit path, write-side; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 5-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `facilityId`, `createdAt`/`updatedAt`, `createdBy`/`updatedBy`. (The 5 present() fields ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no payer state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:medications` — the organization-scoped formulary read (Phase 42)

`GET /functions/v1/organizations-medications/<organizationId>`

Mirrors `MedicationController::index` (the `GET
organizations/{organization}/medications` route) exactly — the
organization-scoped formulary read (the prescription reference catalog),
with the exact Laravel `generic_name` ASC ordering, the facility filter
applied ONLY for facility-scoped callers, NO status filter (active AND
inactive), under the DISTINCT `medication:view` gate. Phase 42 is the ninth
org-selector sibling of Phases 33/35/36/37/38/39/40/41 on the
**TENANT_FACILITY** RLS tier (like staff/services — medications has NO
`branch_id` column, so the select policy is `tenant_id = TENANT AND
(facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause; a
branch proposal is accepted but does NOT narrow the read — proven in the
harness and the DB tier). Like services, medications **ARE soft-deletable**
— the SoftDeletes model scope excludes `deleted_at`-set rows, reproduced
exactly in the read. **The gate holder set differs from the catalog
phases: the RolePermissionSeeder grants `medication:view` to the doctor
and nurse roles too** (the clinical workstation reads the formulary it
prescribes from) — the receptionist/billing_clerk roles do NOT hold it.
The formulary carries money (`price_minor` — integer minor units, never
floats, DATABASE.md §0.4, `price_minor >= 0` CHECK), `currency` (3-char
ISO), `is_controlled` (boolean — controlled-substance flag), and the
strength/form/unit fields.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-medications/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (medications is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'medication:view')` — the exact Laravel gate `authorize:medication:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` / `service:view` / `payer:view`. The RolePermissionSeeder grants it to support_agent / org_admin / hospital_admin / branch_manager / **doctor** / **nurse** — the seeded receptionist / billing_clerk roles do NOT hold it. A principal with the related view permissions alone is DENIED — proven in the harness (the receptionist actor; the doctor is the facility-scoped SUCCESS actor — the doctor DOES hold the formulary gate). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listOrganizationMedications(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the medications are read under the claims (medications is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause, medications has no `branch_id` column), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` both return (the catalog statuses; the Laravel query has no status where). **Medications ARE soft-deletable** — the SoftDeletes model scope excludes `deleted_at`-set rows (`m.deleted_at is null` in the SELECT — the exact default-scope parity), so soft-deleted medications never return. |
| **Ordering** | `orderBy('generic_name')` — the exact Laravel order (no secondary key). |
| **Items** | the exact 11-field map `{id, facilityId, code, genericName, brandName, strength, form, unit, priceMinor, currency, isControlled, status}` — `facilityId` is NOT NULL in the base schema and HYDRATED (real value); `brandName` is the ONLY NULLABLE text field (generic drugs carry no brand); `strength`/`form`/`unit` are NOT NULL (form defaults to 'tablet'); `priceMinor` is an integer in minor units (never floats — `price_minor >= 0` CHECK) / `currency` is the 3-char ISO code (default 'NPR') / `isControlled` is a boolean (controlled-substance flag); `status` ∈ active/inactive; the `lock_version` optimistic-locking counter EXISTS in the schema but is NEVER presented (unlike beds — the medication read does not expose it); tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `MedicationController::index` records no audit event (`medication.created` is the only audit path, write-side; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 11-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `lockVersion`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId` IS the contract — never suppressed.) |
| **Mutation** | none — a pure read; no formulary state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:schedule-templates` — the organization-scoped schedule read (Phase 43)

`GET /functions/v1/organizations-schedule-templates/<organizationId>`

Mirrors `ScheduleController::templates` (the `GET
organizations/{organization}/schedule-templates` route) exactly — the
organization-scoped provider-schedule read (recurring weekly templates that
`SlotService` derives availability from), with the exact Laravel
`day_of_week` ASC ordering, the eager staff ref
(`with('staff:id,full_name,designation')` — presented as id/fullName/
designation), the facility filter applied ONLY for facility-scoped callers,
NO status filter (active AND inactive), under the DISTINCT `schedule:view`
gate. Phase 43 is the tenth org-selector sibling of Phases
33/35/36/37/38/39/40/41/42 on the **TENANT_FACILITY** RLS tier (like
staff/services/medications — schedule_templates has NO `branch_id` column,
so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
FACILITY IS NULL)` with NO branch clause; a branch proposal is accepted but
does NOT narrow the read — proven in the harness and the DB tier). Like
services/medications, schedule templates **ARE soft-deletable** — the
SoftDeletes model scope excludes `deleted_at`-set rows, reproduced exactly
in the read. **The gate holder set differs from the catalog phases: the
RolePermissionSeeder grants `schedule:view` to the front-desk AND clinical
roles too** (receptionist/doctor/nurse) — the billing_clerk role does NOT
hold it. The read carries time (`starts_at`/`ends_at` as `H:i`) and date
(`valid_from`/`valid_to` as `YYYY-MM-DD`) semantics.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-schedule-templates/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (schedule_templates is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'schedule:view')` — the exact Laravel gate `authorize:schedule:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` / `service:view` / `payer:view` / `medication:view`. The RolePermissionSeeder grants it to support_agent / org_admin / hospital_admin / branch_manager / **receptionist** / **doctor** / **nurse** — the seeded billing_clerk role does NOT hold it. A principal with the related view permissions alone is DENIED — proven in the harness (the billing_clerk actor; the doctor is the facility-scoped SUCCESS actor — the doctor DOES hold the schedule gate). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listOrganizationScheduleTemplates(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the templates are read under the claims (schedule_templates is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause, schedule_templates has no `branch_id` column), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `inactive` both return (the catalog statuses; the Laravel query has no status where). **Schedule templates ARE soft-deletable** — the SoftDeletes model scope excludes `deleted_at`-set rows (`t.deleted_at is null` in the SELECT — the exact default-scope parity), so soft-deleted templates never return. |
| **Ordering** | `orderBy('day_of_week')` — the exact Laravel order (no secondary key; the two day-4 rows in the fixture TIE and the tie order is PostgreSQL-unspecified — exactly as Laravel leaves it). |
| **Items** | the exact 13-field map `{id, facilityId, staffId, staff, serviceId, dayOfWeek, startsAt, endsAt, slotMinutes, capacity, validFrom, validTo, status}` — `facilityId`/`staffId` are NOT NULL in the base schema and HYDRATED (real values); `staff` = the eager ref `{id, fullName, designation}` (staff has NO SoftDeletes and the composite FK is RESTRICT — the ref always resolves in a consistent DB, so the Laravel `?: null` is unreachable in practice); `serviceId` is NULLABLE (the composite FK allows NULL — a service-less template); `startsAt`/`endsAt` are the TIME columns formatted `H:i` (the datetime cast's format — e.g. '09:00'); `validFrom`/`validTo` are the date casts' `toDateString()` (`YYYY-MM-DD`; validTo nullable); `dayOfWeek` ∈ 0..6 (ISO 8601 — 0 Sun .. 6 Sat); `slotMinutes` (5–240 CHECK) / `capacity` integers; `status` ∈ active/inactive; tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `ScheduleController::templates` records no audit event (`schedule.template.created` is the only audit path, write-side; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 13-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`. (`facilityId`/`staffId`/`serviceId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no schedule state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `organizations:schedule-exceptions` — the organization-scoped schedule-exception read (Phase 44)

`GET /functions/v1/organizations-schedule-exceptions/<organizationId>`

Mirrors `ScheduleController::exceptions` (the `GET
organizations/{organization}/schedule-exceptions` route) exactly — the
direct sibling of the Phase 43 templates read: leave / holiday / blocked-date
exceptions that cancel derived availability for a provider on a date, with
the exact Laravel `exception_date` DESC ordering, the facility filter
applied ONLY for facility-scoped callers, NO status filter (active AND
cancelled), under the same DISTINCT `schedule:view` gate. Phase 44 is the
eleventh org-selector sibling of Phases 33/35/36/37/38/39/40/41/42/43 on the
**TENANT_FACILITY** RLS tier (schedule_exceptions has NO `branch_id`
column, so the select policy is `tenant_id = TENANT AND (facility_id =
FACILITY OR FACILITY IS NULL)` with NO branch clause; a branch proposal is
accepted but does NOT narrow the read — proven in the harness and the DB
tier). **Unlike the templates read, schedule exceptions are NOT
soft-deletable** — the ScheduleException model has NO SoftDeletes trait
and the table has NO `deleted_at` column ("date-scoped rows expire
naturally"), so there is NO soft-delete filter to reproduce. The gate
holder set is the SAME as Phase 43: the RolePermissionSeeder grants
`schedule:view` to support_agent / org_admin / hospital_admin /
branch_manager / receptionist / doctor / nurse — the billing_clerk role
does NOT hold it. The read carries date semantics (`exception_date` as
`YYYY-MM-DD`) and the CHECK-constrained `reason` (leave/holiday/block) and
`status` (active/cancelled) values.

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/organizations-schedule-exceptions/<organizationId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (schedule_exceptions is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the organization id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Organization not found.'` (the exact `AccessCheck::organization` throw — `Organization::find($id)` → null); a KNOWN organization outside the caller's scope → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'schedule:view')` — the exact Laravel gate `authorize:schedule:view`, the SAME DISTINCT capability as the Phase 43 templates read, distinct from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` / `service:view` / `payer:view` / `medication:view`. The RolePermissionSeeder grants it to support_agent / org_admin / hospital_admin / branch_manager / **receptionist** / **doctor** / **nurse** — the seeded billing_clerk role does NOT hold it. A principal with the related view permissions alone is DENIED — proven in the harness (the billing_clerk actor; the doctor is the facility-scoped SUCCESS actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listOrganizationScheduleExceptions(claims, organizationId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The organization gate resolves the org (the organization id IS the tenant id; `AccessCheck::organization` — platform callers bypass the scope check); the exceptions are read under the claims (schedule_exceptions is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` — NO branch clause, schedule_exceptions has no `branch_id` column), bound to the verified organization id. The facility filter is applied ONLY when the caller has a facility claim — the exact `! isPlatform && facilityId() !== null` guard; org-level / platform callers see every facility of the tenant (RLS facilityClause parity). |
| **Status filter** | NONE — `active` AND `cancelled` both return (the CHECK-constrained lifecycle statuses; the Laravel query has no status where). **Schedule exceptions are NOT soft-deletable** — no SoftDeletes trait, no `deleted_at` column — nothing is ever excluded. |
| **Ordering** | `orderByDesc('exception_date')` — the exact Laravel order (the org-a fixture dates are all DISTINCT — the DESC order is fully deterministic at the DB tier). |
| **Items** | the exact 6-field map `{id, facilityId, staffId, exceptionDate, reason, status}` — `facilityId`/`staffId` are NOT NULL in the base schema and HYDRATED (real values); `exceptionDate` is the date cast's `toDateString()` (`YYYY-MM-DD`); `reason` ∈ leave/holiday/block (the CHECK constraint — NOT NULL text); `status` ∈ active/cancelled (the CHECK constraint). **The staff reference is NOT presented** — unlike the templates read, `presentException()` exposes no staff ref (the eager `with('staff:id,full_name')` is a query-level detail only); the `template_id` column EXISTS but is never presented nor selected; tenant/audit/timestamp metadata never leaves the read. |
| **Audit** | NONE — `ScheduleController::exceptions` records no audit event (`schedule.exception.created` is the only audit path, write-side; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = a bare array of the exact 6-field items (Laravel passes the collection directly; no pagination). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent org — `Organization not found.`; out-of-scope org — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `createdAt`/`updatedAt`/`deletedAt`, `createdBy`/`updatedBy`, `templateId`, the staff reference. (`facilityId`/`staffId` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no schedule state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## `facilities:settings` — the facility-scoped configuration read (Phase 45)

`GET /functions/v1/facilities-settings/<facilityId>`

Mirrors `FacilitySettingsController::index` (the `GET
facilities/{facility}/settings` route) exactly — facility configuration as
DATA (PRODUCT_REQUIREMENTS §5.5): versioned key/value settings, with the
exact Laravel `key` ASC ordering, the facility-scoped selector via
`AccessCheck::facility` (the Phase 34 branches access pattern), under the
DISTINCT `settings:view` gate. Phase 45 is the first facility-scoped
sibling of Phases 34 (branches) on the **TENANT_FACILITY** RLS tier
(facility_settings has NO `branch_id` column, so the select policy is
`tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with
NO branch clause; a branch proposal is accepted but does NOT narrow the
read — proven in the harness and the DB tier). **The response is a JSON
OBJECT keyed by setting key (mapWithKeys) — never an array** — each entry
`{value, version, updatedAt}`: `value` is the decoded jsonb payload (the
`value` cast is 'array'), `version` the integer optimistic-lock counter
(every change bumps it — 1 on create), `updatedAt` the `updated_at` cast
formatted exactly like Carbon's `toIso8601String()` (e.g.
'2026-03-10T08:30:00+00:00') or NULL (the `?->` null guard). NO status
column exists (settings have no lifecycle), **never soft-deleted**
(removing a key is an audited state change). The gate holder set: the
RolePermissionSeeder grants `settings:view` to support_agent / org_admin /
hospital_admin / branch_manager — the seeded receptionist / billing_clerk /
doctor / nurse roles do NOT hold it (the doctor is the denial actor).

| Aspect | Contract |
|---|---|
| **Method / path** | `GET /functions/v1/facilities-settings/<facilityId>` |
| **Authentication** | `Authorization: Bearer <GoTrue access JWT>`; `X-Correlation-Id` optional; `X-Swasthya-Facility` / `X-Swasthya-Branch` are PROPOSALS only — the branch proposal is VALIDATED against the context but is IRRELEVANT to this read (facility_settings is TENANT_FACILITY — no branch dimension). |
| **Request body** | GET — no request body is read or honored; an attached body is never trusted (malformed JSON → `400 INVALID_REQUEST`). |
| **Path parameter** | the facility id is a RESOURCE SELECTOR only — never authorization scope. Missing → `404 NOT_FOUND`. A malformed/nonexistent id resolves to null at the AccessCheck layer → `404 NOT_FOUND 'Facility not found.'` (the exact `AccessCheck::facility` throw — `Facility::find($id)` → null); a KNOWN facility outside the caller's scope — another tenant, or a facility-scoped principal requesting another facility — → `deny(read)` → `404 NOT_FOUND 'Resource not found.'` (existence is never leaked). |
| **Authorization** | `can(context, 'settings:view')` — the exact Laravel gate `authorize:settings:view`, a DISTINCT capability from `patient:view` / `insurance:view` / `consent:view` / `document:view` / `department:view` / `branch:view` / `location:view` / `ward:view` / `room:view` / `bed:view` / `staff:view` / `service:view` / `payer:view` / `medication:view` / `schedule:view`. The RolePermissionSeeder grants it to support_agent / org_admin / hospital_admin / branch_manager — the seeded receptionist / billing_clerk / doctor / nurse roles do NOT hold it. A principal with the related view permissions alone is DENIED — proven in the harness (the doctor actor; the org_admin is the org-level SUCCESS actor, the hospital_admin the facility-scoped SUCCESS actor). |
| **Scope** | tenant/facility exclusively from the authoritative context/claims; `listFacilitySettings(claims, facilityId)` runs as `swasthya_app` (NOBYPASSRLS) with `request.jwt.claims` set. The facility gate resolves the facility (`AccessCheck::facility` — out-of-tenant and out-of-facility-scope both deny(read); platform callers bypass; org-level claims may read any in-tenant facility). The settings are bound to the VERIFIED facility id (facility_settings is **TENANT_FACILITY** — select policy `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)`; the facility scoping is BOTH the query — the exact `->where('facility_id', $facility->getKey())` — AND the RLS facility clause: an org-level claim (facility NULL) sees the whole tenant and the verified-facility binding narrows the read to exactly that facility). |
| **Status filter** | NONE — no status column exists (settings are not lifecycled). **Never soft-deleted** — no SoftDeletes trait, no `deleted_at` column (removing a key is an audited state change) — nothing is ever excluded. |
| **Ordering** | `orderBy('key')` — the exact Laravel order (the keys are all DISTINCT — fully deterministic). |
| **Items** | the exact mapWithKeys shape: a JSON OBJECT keyed by setting key, each entry `{value, version, updatedAt}` — `value` is the decoded jsonb payload (the `value` cast is 'array' — jsonb is already JSON and passes through unchanged; objects AND scalars both occur in the fixture); `version` is the integer optimistic-lock counter (1 on create, bumped on every change); `updatedAt` is the `updated_at` timestamp formatted exactly like Carbon's `toIso8601String()` — `YYYY-MM-DDTHH:MM:SS+00:00` (e.g. '2026-03-10T08:30:00+00:00') — or NULL when `updated_at` is null (the `?->` guard); tenant/audit/timestamp metadata beyond the presented `updatedAt` never leaves the read. |
| **Audit** | NONE — `FacilitySettingsController::index` records no audit event (`facility.settings.updated` / `facility.settings.deleted` are the only audit paths, write-side; proven: zero audit rows in the harness). |
| **Response** | `200`: `data` = the keyed settings OBJECT (mapWithKeys — never an array; an empty facility returns `{}`, never null). |
| **Errors** | `400 INVALID_REQUEST` (malformed JSON) · `401 INVALID_TOKEN/TOKEN_EXPIRED` · `403 FORBIDDEN` (inactive/missing context) · `403 TENANT_SUSPENDED` · `403 SCOPE_DENIED` (capability — incl. the related-view-permission-only principals) · `403 FACILITY/BRANCH_DENIED` · `404 NOT_FOUND` (missing/malformed/nonexistent facility — `Facility not found.`; out-of-scope facility — `Resource not found.`). |
| **Never returned** | JWT/secret/hashes, service-role keys, permissions, assignments, audit internals, internal claims, `tenantId`, `facilityId`, `createdAt`, `createdBy`/`updatedBy`, the setting row `id`. (`value`/`version`/`updatedAt` ARE the contract — never suppressed.) |
| **Mutation** | none — a pure read; no configuration state can change through this endpoint (proven: no mutation in the harness and the DB tier). |

## Validation tiers (honest boundary)

| Tier | What | Where | Status |
|---|---|---|---|
| **Proven locally (executed)** | pure TS modules on the shared pipeline: JWT verify paths (iss/aud/exp/nbf/iat, wrong-issuer, missing-sub, jti-variant), context decisions, claims construction, authorization, envelope, `health-auth` + `me` + `patients:list` + `patients:show` + `appointments:create` + `appointments:checkin` + `encounters:create` + `encounter-notes:draft` + `encounter-notes:sign` + `encounters:sign` + `encounters:invoice` + `invoices:pay` + `invoices:show` + `invoices:payments` + `encounters:charges` + `encounters:show` + `appointments:show` + `appointments:index` + `patients:search` + `encounters:notes` + `patients:timeline` + `appointments:queue` + `patients:identifiers` + `patients:contacts` + `patients:insurance-policies` + `patients:consents` + `patients:documents` + `organizations:departments` + `facilities:branches` + `organizations:locations` + `organizations:wards` + `organizations:rooms` + `organizations:beds` + `organizations:staff` + `organizations:services` + `organizations:payers` + `organizations:medications` + `organizations:schedule-templates` + `organizations:schedule-exceptions` + `facilities:settings` handlers, GoTrue refresh/session-shape parity | `node supabase/functions/_shared/harness/run.mjs` (WebCrypto; zero deps) | 855 tests green |
| **Proven locally (executed, DB)** | subject→user, status/suspension gates, proposals, forged-claim immunity, claims→RLS isolation (tenant/facility/branch, missing-claims fail-closed), identity-binding constraints, me-contract ↔ RLS correspondence, patients:list claims-scoped query ↔ RLS-visible rows, patients:show RLS-gated single-row reads (out-of-scope ≡ nonexistent), appointments:create claims-scoped INSERT (RLS-gated, unique-index race → 23505, cancelled-frees-slot, forged-claims immunity), appointments:checkin row-locked token allocation + guarded status transition (duplicate → zero rows, forged-claims immunity, token_counters itself claims-scoped), encounters:create guarded appointment transition + encounter INSERT (duplicate start → zero rows + unique-index 23505 backstop, forged-claims immunity, encounter invisible outside scope), encounter-notes:draft clinical note INSERT (tenant-only RLS boundary, composite-FK author backstop 23503, forged-claims immunity, cross-facility same-tenant visibility documented), encounter-notes:sign guarded draft→signed transition (duplicate → zero rows, signed immutability, server-side signed_at, forged-claims immunity), encounters:sign guarded encounter signing + appointment handoff (duplicate → zero rows, signed immutability, server-side ended_at/signed_at/signed_by, in_consultation→completed handoff, forged-claims immunity), encounters:invoice charge derivation (service rate + ordered prescription lines, integer minor units, cancelled/null-quantity line semantics) + invoice/lines INSERT + totals (54350 parity) + uq_invoices_tenant_number and uq_invoice_lines_tenant_charge unique backstops (23505, savepoint-isolated) + already-invoiced pre-check + forged/missing-claims immunity, invoices:pay capture transaction (payment + allocation INSERT + guarded lock_version update, full payment → paid, stale version → zero rows + full rollback, uq_payments_tenant_idempotency backstop 23505, idempotency replay returns the original payment, forged/missing-claims immunity), invoices:show claims-scoped single-invoice read (header + ordered lines visible in scope, out-of-scope ≡ nonexistent, wrong-facility/wrong-tenant invisible, forged/missing-claims immunity, no mutation), invoices:payments claims-scoped payment read (allocations ordered by allocated_at, LEFT JOIN payment method under tenant+facility RLS — cross-facility payment renders method null, uq_payment_allocations_tenant_payment_invoice 23505 backstop savepoint-isolated, out-of-scope invoice ≡ nonexistent, wrong-facility/wrong-tenant invisible, forged/missing-claims immunity + mutation immunity, no mutation), encounters:charges claims-scoped charge read (charges ordered by charged_at, ALL statuses return including voided — Laravel hasMany has no status filter, out-of-scope encounter ≡ nonexistent, wrong-facility/wrong-tenant invisible, forged/missing-claims immunity + mutation immunity, no mutation), encounters:show claims-scoped single-encounter read (the 11 present() columns visible in scope, out-of-scope ≡ nonexistent, wrong-facility/wrong-tenant invisible, forged/missing-claims immunity + mutation immunity, no mutation), appointments:show claims-scoped single-appointment read (the 15 present() columns + patient/provider refs visible in scope, out-of-facility related ref renders null — never a leak, out-of-scope ≡ nonexistent, wrong-facility/wrong-tenant invisible, forged/missing-claims immunity + mutation immunity, no mutation, no audit — AppointmentController::show parity), appointments:index claims-scoped appointment list (fac-a1 rows ordered by starts_at asc, the date/providerStaffId filters narrow exactly, org-level claims see every tenant facility — RLS facilityClause parity, out-of-facility patient ref joins to NULL — never a leak, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation, no audit — AppointmentController::index parity), patients:search claims-scoped patient search (name-substring/MRN-prefix matches case-insensitive, archived patients excluded — status = 'active', REAL pg_trgm similarity ordering, LIKE wildcards unescaped — Laravel parity, facility scope narrows / org-level claims search the whole tenant, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — PatientController::search parity), encounters:notes claims-scoped clinical-notes read (all statuses return — draft/signed/amended, ordered by created_at ascending — the exact Laravel order, the tenant-scoped notes SELECT bound to the verified encounter, the author ref joins under the same claims — an out-of-facility author renders NULL, never a leak, out-of-scope encounter ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — EncounterController::notes parity), patients:timeline claims-scoped timeline read (entries bound to the verified patient + tenant claim — patient_timeline_entries is TENANT_ONLY, ordered by occurred_at DESC then id DESC — the exact Laravel order incl. the PostgreSQL DESC NULLS FIRST default, the structured jsonb summary never leaks, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — PatientController::timeline parity), appointments:queue claims-scoped queue read (checked_in / in_consultation visits of the date — booked rows excluded, ordered by token_no asc — the exact Laravel order incl. the ASC NULLS LAST default, the patient ref + encounter id join under the same claims — an out-of-facility ref joins to NULL, never a leak, org-level claims see every tenant facility — RLS facilityClause parity, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — AppointmentController::queue parity), patients:identifiers claims-scoped identifiers read (identifiers bound to the verified patient + tenant claim — patient_identifiers is TENANT_ONLY, ordered by created_at DESC — the exact Laravel order, NO status filter — active AND superseded rows return, value_encrypted ciphertext at rest never equals the plaintext + value_hash is the deterministic sha256 — hashValue() parity, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — PatientIdentifierController::index parity), patients:contacts claims-scoped contacts read (contacts bound to the verified patient + tenant claim — patient_contacts is TENANT_ONLY, ordered by is_primary DESC then created_at ASC — the exact Laravel order, boolean DESC → primary first, NO status filter — active AND superseded rows return, the decoded jsonb address/contact_person payloads, the value/address XOR CHECK holds, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — PatientContactController::index parity), patients:insurance-policies claims-scoped policies read (policies bound to the verified patient + tenant claim — insurance_policies is TENANT_ONLY, ordered by created_at DESC — the exact Laravel order, NO status filter — active/expired/cancelled rows all return — status is a lifecycle never a deletion, the payer ref LEFT-JOIN resolves under the same tenant claim — payers is TENANT_ONLY — the eager payer:id,name,code parity, the decoded jsonb benefits payload, the valid_from NOT NULL / valid_to nullable contract, uq_policies_tenant_patient_payer + uq_policies_tenant_payer_number partial unique indexes respected in the fixture graph, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — InsurancePolicyController::index parity), patients:consents claims-scoped consents read (consents bound to the verified patient + tenant claim — consents is TENANT_ONLY, ordered by version DESC — the exact Laravel order, NO status filter — active/expired/revoked rows all return — the versioned lifecycle, history outlives the consent, the decoded jsonb scope payload, the given_at NOT NULL / revoked_at + revocation_reason nullable contract, uq_consents_tenant_patient_type partial unique index respected in the fixture graph, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — ConsentController::index parity), patients:documents claims-scoped documents read (documents bound to the verified patient + tenant claim — patient_documents is TENANT_ONLY, ordered by created_at DESC — the exact Laravel order, NO status filter — staged/available/archived/purged rows all return — the lifecycle statuses, the nullable mime_type/size_bytes/checksum/expires_at/retention_class contract, the storage pointer object_key EXISTS in the table but the read projection NEVER selects it — no crypto boundary, no pointer leak, out-of-scope patient ≡ nonexistent, wrong-facility/wrong-tenant/missing claims expose zero rows + mutation immunity, no mutation — PatientDocumentController::index parity), organizations:departments claims-scoped departments read (departments bound to the verified organization id — departments is **TENANT_FACILITY_BRANCH**, ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the catalog statuses, the branch clause `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` proven two-sided — a br-a1 claim sees br-a1 + branch-less rows and hides the br-a1b row, a br-a1b claim sees exactly br-a1b + branch-less, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact 7-column projection is the present() map — tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — DepartmentController::index parity), facilities:branches claims-scoped branches read (branches bound to the VERIFIED facility id — branches is **TENANT_ONLY** (select policy `tenant_id = TENANT`), so the facility scoping IS the query — the exact `->where('facility_id', ...)` — ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the lifecycle statuses, the exact Laravel index projection selects ONLY id/name/code/status — `facility_id` is never hydrated and the present() `facilityId` renders null (the literal index output) while tenant_id/created_at/updated_by never leave the read, org-level claims read any in-tenant facility (AccessCheck::facility parity), fac-a2 claims see exactly their facility, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — BranchController::index parity), organizations:locations claims-scoped locations read (locations bound to the verified organization id — locations is **TENANT_FACILITY_BRANCH**, ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the catalog statuses, the branch clause `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` proven two-sided — a br-a1 claim sees br-a1 + branch-less rows and hides the br-a1b row, a br-a1b claim sees exactly br-a1b + branch-less, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact 7-column projection is the present() map with HYDRATED facility/branch ids (real values — the index select includes them) while tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — LocationController::index parity), organizations:wards claims-scoped wards read (wards bound to the verified organization id — wards is **TENANT_FACILITY_BRANCH**, ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the lifecycle statuses, the branch clause `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` proven two-sided — a br-a1 claim sees br-a1 + branch-less rows and hides the br-a1b row, a br-a1b claim sees exactly br-a1b + branch-less, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact 7-column projection is the present() map with HYDRATED facility/branch ids (real values — the index select includes them; `ward_type` mapped to `wardType`; the `settings` jsonb is NOT selected) while tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — WardController::index parity), organizations:rooms claims-scoped rooms read (rooms bound to the verified organization id — rooms is **TENANT_FACILITY_BRANCH**, ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the lifecycle statuses, the branch clause `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` proven two-sided — a br-a1 claim sees br-a1 + branch-less rows and hides the br-a1b row, a br-a1b claim sees exactly br-a1b + branch-less, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 11-field present() map with the eager ward ref LEFT JOIN (`with('ward:id,code,name')` — exactly id/code/name) and HYDRATED facility/ward ids (real values — the controller performs NO partial select) while tenant_id/created_at/updated_by never leave the read, the nullable branch_id (tenancy_v2) + daily_rate_minor/currency contracts, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — RoomController::index parity), organizations:beds claims-scoped beds read (beds bound to the verified organization id — beds is **TENANT_FACILITY_BRANCH**, ordered by bed_code ASC — the exact Laravel order, NO status filter — available/occupied/reserved/cleaning/out_of_service rows all return — the BedStatus state machine, NEVER soft-deleted — out_of_service is a status, not a deletion (no deleted_at filter), the branch clause `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` proven two-sided — a br-a1 claim sees br-a1 + branch-less rows and hides the br-a1b row, a br-a1b claim sees exactly br-a1b + branch-less, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 8-field present() map with the eager room ref LEFT JOIN (`with('room:id,code,name,ward_id')` — exactly id/code/name) and HYDRATED facility/room ids (real values — the controller performs NO partial select) + the nullable branch_id (tenancy_v2) + lock_version (CONTRACT-EXPLICIT) while tenant_id/created_at/updated_by/current_admission_id never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (statuses + lock_versions unchanged) — BedController::index parity), organizations:staff claims-scoped staff read (staff bound to the verified organization id — staff is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by full_name ASC — the exact Laravel order, NO status filter — active/on_leave/departed rows all return — the staff lifecycle, NEVER soft-deleted — departed is a status, not a deletion (no deleted_at filter), the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 10-field present() map with the eager department ref LEFT JOIN (`with('department:id,code,name')` — exactly id/code/name; a soft-deleted department renders the ref NULL — proven at the DB tier) and HYDRATED facility/department ids (real values — the controller performs NO partial select) + the nullable user_id/designation/hire_date contract (hire_date as YYYY-MM-DD — the date cast's toDateString) while tenant_id/created_at/updated_by/license_number_encrypted NEVER leave the read (no crypto boundary — the ciphertext is never selected), other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing — StaffController::index parity), organizations:services claims-scoped services read (services bound to the verified organization id — services is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the catalog statuses, SOFT-DELETABLE — the SoftDeletes model scope excludes deleted_at-set rows (the deleted_at filter IS present, proven at the DB tier), the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 10-field present() map with the eager department ref LEFT JOIN (`with('department:id,code,name')` — exactly id/code/name) and HYDRATED facility id (real value — the controller performs NO partial select) + the nullable department_id (the composite FK allows NULL — a department-less service) + default_duration_minutes/default_charge_minor/currency (money is integer minor units — never floats) while tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (the soft-deleted row stays deleted) — ServiceController::index parity), organizations:payers claims-scoped payers read (payers bound to the verified organization id — payers is **TENANT_ONLY** — the select policy is just `tenant_id = TENANT` — NO facility clause, NO branch clause, payers has NEITHER column — so there is NO facility dimension at all, and the Laravel query has NO facility filter (the `! isPlatform && facilityId() !== null` guard is ABSENT): even a facility-scoped caller sees every tenant payer — the material TENANT_ONLY difference from the TENANT_FACILITY staff/services reads, proven at the DB tier), ordered by name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the catalog statuses, NO SoftDeletes — nothing is ever excluded, the exact projection is the 5-field present() map (id/name/code/payer_type/status) while tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (statuses unchanged) — PayerController::index parity), organizations:medications claims-scoped formulary read (medications bound to the verified organization id — medications is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by generic_name ASC — the exact Laravel order, NO status filter — active AND inactive rows return — the catalog statuses, SOFT-DELETABLE — the SoftDeletes model scope excludes deleted_at-set rows (the deleted_at filter IS present, proven at the DB tier), the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 11-field present() map (id/facility_id/code/generic_name/brand_name/strength/form/unit/price_minor/currency/is_controlled/status) with the HYDRATED facility id (real value — the controller performs NO partial select) + the nullable brand_name (the only nullable text field — generic drugs carry no brand), the NOT-NULL strength/form/unit contract (form defaults to 'tablet'), the integer-minor-units price_minor (>= 0 CHECK) + 3-char currency + is_controlled boolean, while lock_version/tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (statuses unchanged, the soft-deleted row stays deleted) — MedicationController::index parity), organizations:schedule-templates claims-scoped schedule-template read (templates bound to the verified organization id — schedule_templates is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by day_of_week ASC — the exact Laravel order (no secondary key — the two day-4 fixture rows TIE and the tie order is PostgreSQL-unspecified, exactly as Laravel leaves it; the day sequence + row set are the deterministic contract), NO status filter — active AND inactive rows return — the catalog statuses, SOFT-DELETABLE — the SoftDeletes model scope excludes deleted_at-set rows (the deleted_at filter IS present, proven at the DB tier), the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 13-field presentTemplate map (id/facility_id/staff_id/service_id/day_of_week/starts_at/ends_at/slot_minutes/capacity/valid_from/valid_to/status) with the HYDRATED facility/staff ids (real values — the controller performs NO partial select) + the eager staff ref LEFT JOIN (`with('staff:id,full_name,designation')` — staff has NO SoftDeletes and the composite FK is RESTRICT, so the ref always resolves in a consistent DB — the Laravel `?: null` is unreachable in practice), the nullable service_id (the composite FK allows NULL — a service-less template) + valid_to, the H:i time + YYYY-MM-DD date formatting, the day_of_week 0..6 (ISO 8601) + slot_minutes/capacity integer contracts, while tenant_id/created_at/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (statuses unchanged, the soft-deleted template stays deleted) — ScheduleController::templates parity), organizations:schedule-exceptions claims-scoped schedule-exception read (exceptions bound to the verified organization id — schedule_exceptions is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by exception_date DESC — the exact Laravel order (all fixture dates DISTINCT — fully deterministic), NO status filter — active AND cancelled rows return — the CHECK-constrained lifecycle statuses, NOT soft-deletable — no SoftDeletes trait, no deleted_at column — nothing is ever excluded, the facility filter parity — fac-a2 claims see exactly their facility, org-level claims see every facility of the tenant (the `! isPlatform && facilityId() !== null` guard — the controller applies NO facility filter for the org-level caller), the exact projection is the 6-field presentException map (id/facility_id/staff_id/exception_date/reason/status) with the HYDRATED facility/staff ids (real values — the controller performs NO partial select; **the staff reference is NOT presented** — presentException exposes no staff ref, unlike the templates read; the template_id column exists but is never selected), the reason ∈ leave/holiday/block + status ∈ active/cancelled CHECK constraints, the exception_date YYYY-MM-DD date formatting, while tenant_id/created_at/created_by/updated_by never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (statuses unchanged), the uq_schedule_exceptions_tenant_staff_date unique backstop (23505, savepoint-isolated) + the composite (tenant, facility, staff_id) → staff RESTRICT FK backstop (23503, savepoint-isolated) — ScheduleController::exceptions parity), facilities:settings claims-scoped settings read (settings bound to the VERIFIED facility id — facility_settings is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH — no branch_id column, so the select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)` with NO branch clause — a branch proposal does NOT narrow the read, proven in the DB tier), ordered by key ASC — the exact Laravel order (all keys DISTINCT — fully deterministic), NO status column exists, NOT soft-deletable — no SoftDeletes trait, no deleted_at column (removing a key is an audited state change) — nothing is ever excluded, the VERIFIED-facility binding parity — org-level claims (facility NULL) see the whole tenant and the query's facility binding narrows the read to exactly that facility (the exact `->where('facility_id', $facility->getKey())`), the exact projection is the mapWithKeys OBJECT keyed by setting key (never an array) with each entry {value/version/updated_at} — value the decoded jsonb payload (the 'array' cast — objects AND scalars), version the integer counter, updated_at formatted exactly like Carbon's toIso8601String ('YYYY-MM-DDTHH:MM:SS+00:00') or NULL (the `?->` guard), while tenant_id/updated_by/created_at/row id never leave the read, other-tenant rows invisible AND mutation-immune, forged/missing claims expose zero rows, read mutates nothing (values + versions unchanged, NULL updated_at still NULL), the uq (tenant_id, facility_id, key) unique backstop (23505, savepoint-isolated) + the composite (tenant, facility) → facilities RESTRICT FK backstop (23503, savepoint-isolated) — FacilitySettingsController::index parity) | `backend/tests/Feature/{EdgeFunctionPipelineTest,AuthSubjectBindingTest,IdentityProvisioningTest,ClaimsBasedRlsTest,DatabaseRowLevelSecurityTest}.php` against the disposable local PostgreSQL | 94 focused tests green (pipeline 49/49 — 1030 assertions) |
| **Simulated / contract-tested** | GoTrue itself — tokens are locally minted in the GoTrue shape (HS256, sub/iss/aud/exp, jti/session_id/role/email) by `JwtClaims` / the harness `signJwt`, NOT by real GoTrue; refresh-rotation/reuse-detection semantics map 1:1 to Supabase sessions but are NOT exercised against real GoTrue | — | pending real GoTrue |
| **Requires real Supabase validation** | Deno runtime execution; `SUPABASE_JWT_SECRET` injection; real GoTrue JWT issuance (algorithm/claims exactly as GoTrue emits); `request.jwt.claims` GUC injection by the platform/pooler; real `auth.users`/identity sync; service-role import; the transaction-wiring/audit-chain SQL in the adapters; deployment of `health-auth` + `me` + `patients-list` + `patients-show` + `patients-timeline` + `patients-identifiers` + `patients-insurance-policies` + `patients-contacts` + `patients-consents` + `patients-documents` + `organizations-departments` + `facilities-branches` + `organizations-locations` + `appointments-create` + `appointments-checkin` + `appointments-queue` + `encounters-create` + `encounters-notes-draft` + `encounters-notes-sign` + `encounters-sign` + `encounters-invoice` + `invoices-pay` + `invoices-show` + `invoices-payments` + `encounters-charges` + `encounters-show` + `appointments-show` + `appointments-index` + `patients-search` + `encounters-notes` + `patients-identifiers` + `patients-contacts` + `patients-insurance-policies` + `patients-consents` + `patients-documents` + `organizations-departments` + `facilities-branches` + `organizations-locations` + `organizations-wards` + `organizations-rooms` + `organizations-beds` + `organizations-staff` + `organizations-services` + `organizations-payers` + `organizations-medications` + `organizations-schedule-templates` + `organizations-schedule-exceptions` + `facilities-settings` | Supabase project (Docker CLI or dashboard) | **pending — NOT claimed** |

Run the local pieces:

```bash
node supabase/functions/_shared/harness/run.mjs
# DB tier:
cd backend && ../.toolchain/php/php.exe vendor/bin/pest tests/Feature/EdgeFunctionPipelineTest.php tests/Feature/IdentityProvisioningTest.php tests/Feature/AuthSubjectBindingTest.php tests/Feature/ClaimsBasedRlsTest.php tests/Feature/DatabaseRowLevelSecurityTest.php
```

## Typecheck

```bash
frontend/node_modules/.bin/tsc -p supabase/functions/_shared/harness/tsconfig.json
```

(Excludes the `*/index.ts` Deno adapters — their Deno/postgres wiring is
validated at deployment, not locally.)
