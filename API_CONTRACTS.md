# API_CONTRACTS.md — Swasthya API Contract

> **Status:** Working baseline · **Owner:** Principal Architect (contract ratified with the team)
> **Version:** 1.0
> **Document chain:** `ARCHITECTURE.md` §5 (API architecture) and `MASTER_RULES.md` §12 (API rules) define the API's architecture and discipline; **this document defines the contract itself** — the conventions every endpoint obeys and the concrete shapes clients are built against.
>
> **Scope:** conventions + example contracts for the core domains. This is a contract specification — no API is implemented here. **All example payloads use synthetic placeholder data** (fictional orgs, patients, facilities); no production data is invented or implied.
>
> **Source of truth:** the machine-readable contract is the OpenAPI 3.1 spec generated from code (`MASTER_RULES.md` §12.6). This document is the human-readable contract: the conventions and the expected shapes. Where this document and the generated spec disagree, the spec (code) wins and this document is updated.

---

## 0. API Design Principles

1. **One API, one client.** The SPA, patient portal, mobile, and (later) partners all consume the same versioned API. No parallel APIs per surface.
2. **Contract-first.** Every endpoint ships with its OpenAPI definition, its validation rules, and its tests; an undocumented endpoint does not ship (`MASTER_RULES.md` P.8).
3. **The server is the authority.** Authorization, tenancy, validation, and idempotency are server-enforced; the client proposes, the server disposes.
4. **Explicit over implicit.** Fields are explicit, nullable is explicit, missing and null are distinct (`DESIGN_SYSTEM.md` §33 — a missing clinical value is `null`, never a fabricated zero).
5. **Stable, additive, versioned.** Breaking changes happen only through the versioning policy (Section 2).

---

## 1. REST Conventions

### 1.1 Resources and URLs

- Plural, kebab-case resource names: `/api/v1/patients`, `/api/v1/lab-orders`.
- Nested only where the relationship is inseparable: `/api/v1/organizations/{org_id}/facilities`. Flat top-level resources are preferred (`/api/v1/facilities/{id}`) once the resource has a stable identity.
- Actions that are not CRUD are **subresource verbs**: `POST /encounters/{id}/sign`, `POST /appointments/{id}/cancel` — never `POST /encounters/sign`.
- **UUIDs** everywhere as string identifiers; `tenant_id` never appears in URLs (Section 5).

### 1.2 Methods

| Method | Semantics | Response |
|---|---|---|
| `GET` | Read. Never mutates. | 200 (or 404/403) |
| `POST` | Create (or action). Carries idempotency keys (Section 13). | 201 + Location; 202 for accepted async work |
| `PATCH` | Partial update of a mutable resource (concurrency via `If-Match`, Section 14). | 200 |
| `PUT` | Full replacement — used only where the resource is a full-state value object. | 200 |
| `DELETE` | **Rare.** Destruction is usually a status transition (`POST .../{id}/cancel`), not a `DELETE` (audit + soft-delete rules, `DATABASE.md` §0.11). `DELETE` exists only for genuinely deletable artifacts (drafts, scratch records) and is audited. | 204 |

### 1.3 Status codes

| Code | Meaning |
|---|---|
| 200 | OK — read or successful update |
| 201 | Created — new resource |
| 202 | Accepted — async work (with `Location` or job reference) |
| 204 | No content — deletion, logout |
| 400 | Malformed request (bad JSON, bad types) |
| 401 | Unauthenticated (missing/expired/invalid token) |
| 403 | Authenticated but not authorized (or tenant suspended — see codes) |
| 404 | Not found (or not within your scope — same response either way) |
| 409 | Conflict: state conflict, idempotency-key reuse, concurrent modification |
| 422 | Valid JSON but failed validation (field errors) |
| 429 | Rate limited (with `Retry-After`) |
| 500 | Server error (never leaks internals) |
| 503 | Unavailable (maintenance, overload) |

### 1.4 JSON conventions

- Fields are `camelCase`. URLs are kebab-case. Enums are `snake_case` strings with documented values.
- **Money** is `amountMinor` (integer minor units) + `currency` (ISO 4217) — never floats (`DATABASE.md` §0.4).
- **Timestamps** are RFC 3339 UTC (`2026-08-11T09:30:00Z`); **date-only** fields are `YYYY-MM-DD` with no timezone (Section 19–20).
- **Null vs missing:** a field that exists with `null` is *explicitly unknown/absent* (clinical "—"); a field that is absent from the payload was not sent. Responses always include nullable fields explicitly for clinical data.
- IDs are always strings (UUIDs), never numbers.

---

## 2. API Versioning

- **Version in the URL:** `/api/v1/...`. Path versioning is chosen for explicitness, cache-friendliness, and supportability (`ARCHITECTURE.md` §5).
- **Additive changes are allowed within a version:** new optional fields, new endpoints, new enum values. Additive fields never change the meaning of existing fields.
- **Breaking changes require a new version** (`/api/v2`): removed/renamed fields, changed semantics, tightened validation, removed endpoints.
- **Deprecation policy:** a breaking change announces the new version and deprecates the old; the old version keeps working for a minimum 6-month window, returns `Deprecation: true` and `Sunset: <date>` headers, and is removed only after the window closes and traffic is monitored (`MASTER_RULES.md` §31).
- Clients pin versions; the platform publishes the list of supported versions.

---

## 3. Authentication

- **Bearer access tokens:** `Authorization: Bearer <access_token>` on every request. Short-lived (15–60 min), scoped, revoked server-side on logout/password change/role change/offboarding (`ARCHITECTURE.md` §9; `SECURITY.md` §5).
- **Refresh:** `POST /api/v1/auth/refresh`; the refresh token travels in an `httpOnly, Secure, SameSite=Strict` cookie; rotation detects reuse (Section 13 applies to refresh too).
- **MFA:** staff logins with MFA return a challenge (`202` + `mfaRequired`) that must be completed before tokens issue (contract in Section 21.1).
- Token errors are `401` with a machine-readable code distinguishing `INVALID_TOKEN`, `TOKEN_EXPIRED`, `TOKEN_REVOKED`.

---

## 4. Authorization

- Authorization is **server-side and scope-based**: the token's principal, their role assignments, and the resource determine access. The client never asserts authority.
- **Denials:** `403` with a code: `FORBIDDEN`, `SCOPE_DENIED` (role lacks the verb), `FACILITY_DENIED` (context outside the principal's facility scope), `TENANT_SUSPENDED`.
- **Resource visibility:** a resource that exists but is outside the caller's scope returns `404` (not `403`) for *reads* — existence is not leaked (`SECURITY.md` §18). Writes return `403`/`SCOPE_DENIED`.
- **Sensitive endpoints** (merge patients, void charges, sign encounters) additionally enforce the confirmation semantics — the API requires `reason` and, where policy requires, a second-operator approval reference (`DESIGN_SYSTEM.md` §27; `MASTER_RULES.md` §11).

---

## 5. Tenant Context

- **`tenant_id` is never sent by the client.** The server derives it from the authenticated principal (`TENANCY.md` §3).
- **The client may propose facility context** with the header `X-Swasthya-Facility: <facility_uuid>`. The server validates it against the principal's scope, derives the tenant, and **echoes the effective context** in the response:

```json
{
  "data": { },
  "meta": {
    "context": { "tenantId": "…", "facilityId": "…", "branchId": null }
  }
}
```

- A proposed facility outside the principal's scope → `403 FACILITY_DENIED`. An absent header uses the principal's default context; the effective context is always echoed so the client state stays truthful.
- **Patient requests are self-scoped:** the portal never sends a context; the server resolves the patient's own context (`TENANCY.md` §2).
- All payloads that carry facility-local data include `facilityId` (and `branchId` where relevant) as explicit fields; the server validates they fall inside the derived context.

---

## 6. Request Validation

- Every mutation and every filtered read validates input **server-side** against explicit rules: types, formats, ranges, referential validity, and enum allowlists (`MASTER_RULES.md` §12.5).
- Validation failures → `422` with the error envelope and `details[]` per field (Section 8). A malformed request (unparseable JSON, wrong types) → `400`.
- **Unknown fields are rejected** (strict mode) — a typo in a field name is a `422`, not a silent ignore.
- Validation rules ship in the OpenAPI spec and are tested; the client types are generated from the same spec (`ARCHITECTURE.md` §3).

---

## 7. Response Format

Every successful response uses one envelope:

```json
{
  "data": { },                 // the resource(s) — object, array, or null
  "meta": {
    "context": { "tenantId": "…", "facilityId": "…" },
    "pagination": { "current": 1, "size": 25, "total": 143, "last": 6 }
  },
  "links": {
    "first": "/api/v1/patients?page%5Bnumber%5D=1",
    "prev": null,
    "next": "/api/v1/patients?page%5Bnumber%5D=2",
    "last": "/api/v1/patients?page%5Bnumber%5D=6"
  }
}
```

- `204` responses have no body.
- `202` responses carry `meta.job` (`{ jobId, status }`) when the work is async.
- Every mutating response returns `X-Audit-Event-Id: <uuid>` — the audit record for the mutation (Section 16).
- Every response returns `X-Request-Id` and echoes `X-Correlation-Id` (Section 17).

---

## 8. Error Format

Errors use one envelope, always:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "3 fields failed validation.",
    "details": [
      { "field": "dateOfBirth", "code": "INVALID_FORMAT", "message": "Use YYYY-MM-DD." },
      { "field": "sex", "code": "NOT_ALLOWED", "message": "Value must be one of: female, male, other." }
    ],
    "correlationId": "5f2c…"
  }
}
```

**Error code taxonomy** (machine-readable; stable per version):

| Code | HTTP | Meaning |
|---|---|---|
| `INVALID_REQUEST` | 400 | Malformed request |
| `VALIDATION_ERROR` | 422 | Field-level validation failure (details) |
| `INVALID_TOKEN` / `TOKEN_EXPIRED` / `TOKEN_REVOKED` | 401 | Token problems |
| `MFA_REQUIRED` | 202/401 | MFA challenge outstanding |
| `FORBIDDEN` / `SCOPE_DENIED` / `FACILITY_DENIED` | 403 | Authorization failures |
| `TENANT_SUSPENDED` | 403 | Tenant state blocks operation |
| `NOT_FOUND` | 404 | Missing or out-of-scope resource |
| `CONFLICT` | 409 | State conflict (e.g., slot already booked) |
| `LOCK_CONFLICT` | 409 | Concurrent modification (Section 14) |
| `IDEMPOTENCY_REUSE` | 409 | Key reused with a different request (Section 13) |
| `RESOURCE_EXISTS` | 409 | Duplicate (e.g., duplicate patient MRN candidate) |
| `RATE_LIMITED` | 429 | Quota exceeded (with `Retry-After`) |
| `SERVER_ERROR` | 500 | Unexpected; details never exposed |
| `SERVICE_UNAVAILABLE` | 503 | Dependency/maintenance |

- `message` is human-readable and safe to show; `details` is structured; the server never leaks stack traces, SQL, or PHI (Section 17, `MASTER_RULES.md` §17).

---

## 9. Pagination

- **Lists paginate**; there is no unbounded list response.
- Query params: `page[number]` (1-based) and `page[size]` (default 25, max 100).
- `meta.pagination` reports `current`, `size`, `total`, `last`; `links` carries first/prev/next/last.
- **Cursor pagination for high-volume streams** (audit events, notifications, activity): `page[cursor]` with `meta.pagination.nextCursor` — offset pagination is not used where the list is append-heavy.
- Pagination is stable under concurrent writes where it matters (cursor streams); list pages are point-in-time.

---

## 10. Filtering

- Query param convention: `filter[<field>]=<value>`; comma-separated values are OR (`filter[status]=booked,checked_in`).
- Range filters: `filter[startsAt][gte]=…&filter[startsAt][lte]=…` (`gt/gte/lt/lte`, `eq` default).
- Allowed filters are **documented per endpoint** in the OpenAPI spec; an undocumented filter is a `422`.
- Filters never cross tenant boundaries: a `filter[tenant_id]` does not exist (Section 5).

---

## 11. Sorting

- `sort=<field>`; prefix `-` for descending: `sort=-created_at`.
- Sort keys are **allowlisted per endpoint** (no arbitrary column sorting); an unknown sort key is a `422`.
- Default sort is documented per endpoint (usually `-created_at` or the domain-meaningful order, e.g., appointments by `starts_at`).

---

## 12. Searching

- **`q` parameter** for search endpoints: `GET /api/v1/patients/search?q=…`.
- **Patient search** is the safety-critical surface (`DESIGN_SYSTEM.md` §16): results are *candidates* with identity confirmation fields (name, MRN, DOB, age/sex), never silent opens; an exact MRN match ranks first; fuzzy matches via name variants; the response never auto-selects.
- Search respects scope: results are limited to the caller's facility/tenant context; cross-tenant search is impossible by construction.
- Debounce (≥ 300 ms) is a client concern; the API is stateless on search.
- Empty/ambiguous searches return guidance in `meta.search` (`{ "hint": "No exact match — check spelling or scan the wristband." }`), per `DESIGN_SYSTEM.md` §23.

---

## 13. Idempotency

- **Every create/mutate of a clinical or financial resource requires an idempotency key**: header `Idempotency-Key: <uuid-or-client-string>` (client-generated, unique per operation).
- The server persists the key (per tenant + principal), computes the request hash, and:
  - first use → executes, stores the outcome, returns it;
  - replay with the **same** key and matching hash → returns the **stored** response, with header `Idempotency-Replayed: true`;
  - same key with a **different** request → `409 IDEMPOTENCY_REUSE`.
- Keys expire per policy (e.g., 24 h) — beyond that the client must generate a new key.
- Backed by the `idempotency_keys` table (`DATABASE.md` §0.10) — the database, not a cache, is the source of idempotency truth.
- **Refresh tokens rotate with the same discipline:** a replayed refresh token revokes the token family (`SECURITY.md` §4).

---

## 14. Concurrency

- Mutable resources expose their version: the response includes `ETag: "<lock_version>"` (the `lock_version` from `DATABASE.md` §0.7).
- **Writes carry `If-Match: "<lock_version>"`.** A mismatch → `409 LOCK_CONFLICT` with the current resource (so the client can re-read, re-merge, retry) — never a silent overwrite.
- High-volume read-modify-write paths (stock, beds, slots) are serialized server-side with row locks; the client still sees `LOCK_CONFLICT` on stale writes.
- Clients should implement a retry-after-re-read loop for `LOCK_CONFLICT` and surface it as "someone else updated this" (`DESIGN_SYSTEM.md` §25) — never auto-overwrite.

---

## 15. Rate Limiting

- Limits are per-IP **and** per-account, differentiated by route class (`SECURITY.md` §17).
- Response headers on every request: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.
- Exceeding → `429` with error `RATE_LIMITED`, `Retry-After: <seconds>`, and the standard error envelope.
- The client backs off per `Retry-After`; a 429 is never retried immediately in a loop.
- Auth endpoints have the strictest limits.

---

## 16. Audit

- Every mutating response returns `X-Audit-Event-Id` — the append-only audit event for the mutation (`MASTER_RULES.md` §19; `DATABASE.md` §3.36).
- Audited classes at the contract level: auth events, clinical record reads/mutations, financial mutations, role/consent/export changes, privileged actions, AI actions, tenant lifecycle.
- **Audit query endpoints are themselves restricted** (audit roles only) and paginate via cursor (Section 9).
- The contract never exposes a "delete audit" or "edit audit" path — such a path does not exist.

---

## 17. Correlation IDs and Request IDs

- **`X-Correlation-Id`:** client-generated (optional) or server-generated; spans a workflow (booking → check-in → encounter → billing); echoed in every response and recorded in logs, audit events, and outbound calls (`MASTER_RULES.md` §18.2).
- **`X-Request-Id`:** server-generated per request; echoed in the response header; ties a single request to its logs.
- Error envelopes embed the correlation ID so a user-reported error is traceable end-to-end.
- Both headers are safe to log; they carry no data.

---

## 18. Request IDs (Client Traceability)

- The SPA attaches a client-generated `X-Correlation-Id` per user gesture; the API preserves it across internal hops (worker jobs, integrations) so one patient action is one trace.
- Background jobs carry the correlation ID in the job payload; integration messages carry it in `correlationId` (`DATABASE.md` §3.42).
- `X-Request-Id` is generated at the edge if absent and is never trusted from the client (it identifies the *server-side* request).

---

## 19. Timestamps

- **Wire format:** RFC 3339 UTC — `2026-08-11T09:30:00Z`. All `*At` fields are this format.
- **Date-only fields** (`dateOfBirth`, `validFrom`, `settlementDate`) are `YYYY-MM-DD` — deliberately timezone-free; they mean a calendar date, not an instant (`DATABASE.md` §0.3).
- **Durations** are ISO 8601 (`PT30M`, `P5D`).
- Clients render timestamps in the facility timezone (Section 20); the API never returns local time without an offset.

---

## 20. Timezone Handling

- **Storage and transport are UTC.** The server stores `timestamptz` (UTC) and returns RFC 3339 with `Z`; no server-side "local time" conversion exists (`DATABASE.md` §0.3).
- **Rendering is a client concern:** the effective facility timezone arrives in the context (`meta.context` carries `timezone`); the client renders in that timezone.
- **Day-boundary logic is explicit:** "starts at 9:00 local on the appointment day" is computed by the *server* from the facility timezone when creating schedule-derived times — never by the client adding a fixed offset.
- **Date-only fields are never converted** across timezones: `dateOfBirth` stays `YYYY-MM-DD` regardless of where the client is.

---

## 21. Example Contracts

> All examples use synthetic placeholder data.

### 21.1 Authentication

**Login (MFA flow)**

```
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "admin@demo-care.example", "password": "…" }
```

**MFA required → 202:**

```json
{
  "data": {
    "mfaRequired": true,
    "challengeId": "0f8f…-challenge-uuid",
    "expiresIn": 300
  },
  "meta": { "context": { "tenantId": null, "facilityId": null } }
}
```

**Complete MFA:**

```
POST /api/v1/auth/mfa/verify
{ "challengeId": "0f8f…-challenge-uuid", "code": "482913" }
```

**200:**

```json
{
  "data": {
    "accessToken": "1|abc…",
    "expiresIn": 3600,
    "tokenType": "Bearer",
    "user": { "id": "u-001", "email": "admin@demo-care.example", "name": "Admin Dev" }
  },
  "meta": {
    "context": { "tenantId": "t-demo-care", "facilityId": "f-central" }
  }
}
```

**Errors:** `401 INVALID_CREDENTIALS` · `202 MFA_REQUIRED` (as above) · `429 RATE_LIMITED` after lockout thresholds.

**Refresh:** `POST /api/v1/auth/refresh` — the refresh token is accepted in the JSON body (`refreshToken`) and/or the `swasthya_refresh` cookie → `200` with the **same shape as login** (fresh `accessToken`, rotated refresh token, `user`, and the `assignments` payload, so a client can restore its tenant/facility/role context after a page reload). A replayed token revokes the token family (`SECURITY.md` §4). **Logout:** `POST /api/v1/auth/logout` → `204`.

### 21.2 Organizations

**List my organizations** (a user may belong to several):

```
GET /api/v1/organizations
```

**200:**

```json
{
  "data": [
    { "id": "t-demo-care", "code": "demo-care", "name": "Demo Care Group", "status": "active",
      "facilities": [ { "id": "f-central", "name": "Demo Care Central" } ] }
  ],
  "meta": { "pagination": { "current": 1, "size": 25, "total": 1, "last": 1 } }
}
```

**Get one** (requires membership): `GET /api/v1/organizations/{org_id}` → `200` with org detail (settings, currency, timezone) · `404 NOT_FOUND` if not a member.

### 21.3 Facilities

**List facilities I can operate in** (org-scoped, filtered by my assignments):

```
GET /api/v1/organizations/t-demo-care/facilities?filter[status]=active&sort=name
```

**200:**

```json
{
  "data": [
    { "id": "f-central", "name": "Demo Care Central", "code": "central", "status": "active",
      "branches": [ { "id": "b-1", "name": "Main Wing" } ] }
  ],
  "meta": { "context": { "tenantId": "t-demo-care", "facilityId": null } }
}
```

**Create** (org admin): `POST /api/v1/organizations/t-demo-care/facilities` with `{ "name", "code", "timezone" }` → `201` + `Location: /api/v1/facilities/f-002`, `X-Audit-Event-Id` · `403 SCOPE_DENIED` for non-admins.

### 21.4 Users

**Current user:**

```
GET /api/v1/users/me
```

**200:**

```json
{
  "data": {
    "id": "u-001", "email": "admin@demo-care.example", "name": "Admin Dev",
    "assignments": [
      { "organizationId": "t-demo-care", "facilityId": "f-central", "roles": ["org_admin"] }
    ]
  }
}
```

**Create a staff user** (org admin):

```
POST /api/v1/organizations/t-demo-care/users
{ "email": "nurse.poudel@demo-care.example", "name": "Poudel Nurse",
  "staff": { "facilityId": "f-central", "departmentId": "d-ward", "employeeCode": "EMP-0114" } }
```

**201** with the user + pending status (MFA to be set at first login) · `422 VALIDATION_ERROR` on duplicate email.

### 21.5 Roles

**List the role catalog** (platform-provided, filtered by scope):

```
GET /api/v1/roles?filter[scopeType]=facility
```

**200:**

```json
{
  "data": [
    { "id": "r-pharmacist", "code": "pharmacist", "name": "Pharmacist", "scopeType": "facility",
      "permissions": [ { "id": "p-dispense", "code": "pharmacy:dispense" } ] }
  ],
  "meta": { "pagination": { "current": 1, "size": 25, "total": 6, "last": 1 } }
}
```

### 21.6 Permissions

**List permissions** (grouped by domain): `GET /api/v1/permissions` → `200` with `{ data: [ { code: "pharmacy:dispense", domain: "pharmacy", description: "…" } ] }`. Read-only catalog; no tenant data.

### 21.7 Patients

**Register a patient** (duplicate detection is part of the contract):

```
POST /api/v1/patients
Idempotency-Key: 4f9d…-new-uuid
X-Swasthya-Facility: f-central
Content-Type: application/json

{
  "fullName": "Sita Sharma", "dateOfBirth": "1988-04-12", "sex": "female",
  "phone": "+977-98…", "address": { "district": "Biratnagar" }
}
```

**201:**

```json
{
  "data": {
    "id": "p-9001", "mrn": "MRN-000114", "fullName": "Sita Sharma",
    "dateOfBirth": "1988-04-12", "sex": "female",
    "status": "active", "createdAt": "2026-08-11T09:30:00Z"
  },
  "meta": {
    "context": { "tenantId": "t-demo-care", "facilityId": "f-central" },
    "duplicates": [ { "id": "p-8992", "mrn": "MRN-000103", "fullName": "Sita Sharma", "score": 0.97 } ]
  },
  "links": { "self": "/api/v1/patients/p-9001" }
}
```

The `duplicates` array is a candidate list for human-confirmed merge — never an auto-merge. **Errors:** `409 RESOURCE_EXISTS` when MRN rules are violated · `422` validation.

**Search** (candidates, never auto-open):

```
GET /api/v1/patients/search?q=sita+sharma&X-Swasthya-Facility: f-central
```

**200:**

```json
{
  "data": [
    { "id": "p-8992", "mrn": "MRN-000103", "fullName": "Sita Sharma", "dateOfBirth": "1988-04-12", "sex": "female" }
  ],
  "meta": { "search": { "hint": "2 candidates found — confirm identity before opening." } }
}
```

**Update with concurrency:**

```
PATCH /api/v1/patients/p-9001
If-Match: "7"
{ "phone": "+977-98…" }
```

**200** → `200` + `ETag: "8"`; **409 LOCK_CONFLICT** with the current resource if another actor updated since version 7.

### 21.8 Appointments

**Book** (slot-level conflict detection + idempotency):

```
POST /api/v1/appointments
Idempotency-Key: 7c1e…-new-uuid
X-Swasthya-Facility: f-central
{
  "patientId": "p-9001", "providerStaffId": "s-dr-11",
  "appointmentType": "opd", "startsAt": "2026-08-12T04:15:00Z",
  "scheduleOccurrenceId": "occ-331"
}
```

**201:**

```json
{
  "data": {
    "id": "appt-5521", "status": "booked", "tokenNo": 14,
    "patientId": "p-9001", "providerStaffId": "s-dr-11",
    "startsAt": "2026-08-12T04:15:00Z", "endsAt": "2026-08-12T04:30:00Z"
  },
  "meta": { "context": { "tenantId": "t-demo-care", "facilityId": "f-central" } }
}
```

**Errors:** `409 CONFLICT` (slot already booked — with `meta.slot.available: false`) · `409 IDEMPOTENCY_REUSE`.

**Cancel** (audited, reason required): `POST /api/v1/appointments/appt-5521/cancel` `{ "reasonCode": "patient_request" }` → `200` with `status: "cancelled"` · **Reschedule:** `POST /api/v1/appointments/appt-5521/reschedule` `{ "startsAt": "2026-08-13T04:15:00Z", "scheduleOccurrenceId": "occ-338" }` → `200`, same conflict handling.

### 21.9 Encounters

**Start an OPD encounter** (from a checked-in appointment or walk-in):

```
POST /api/v1/encounters
Idempotency-Key: a3f2…-new-uuid
X-Swasthya-Facility: f-central
{ "patientId": "p-9001", "appointmentId": "appt-5521", "type": "opd", "providerStaffId": "s-dr-11" }
```

**201:**

```json
{
  "data": { "id": "enc-771", "status": "open", "type": "opd", "patientId": "p-9001",
            "providerStaffId": "s-dr-11", "startedAt": "2026-08-12T04:16:00Z" },
  "meta": { "context": { "tenantId": "t-demo-care", "facilityId": "f-central" } }
}
```

**Sign** (the record becomes immutable; amendments are new versions):

```
POST /api/v1/encounters/enc-771/sign
{ "diagnoses": [ { "code": "J00", "codingSystem": "icd10", "description": "Acute nasopharyngitis", "diagnosisType": "final", "isPrimary": true } ] }
```

**200:**

```json
{
  "data": { "id": "enc-771", "status": "signed", "signedBy": "s-dr-11", "signedAt": "2026-08-12T04:40:00Z" },
  "links": { "self": "/api/v1/encounters/enc-771" },
  "headers": { "X-Audit-Event-Id": "aud-…" }
}
```

**Errors:** `409 CONFLICT` when signing a signed encounter (amend instead) · `403 SCOPE_DENIED` when the provider is outside the facility context.

### 21.10 Billing

**Create a charge** (financial mutation — idempotency is mandatory):

```
POST /api/v1/charges
Idempotency-Key: 9b8a…-new-uuid
X-Swasthya-Facility: f-central
{ "patientId": "p-9001", "sourceType": "encounter", "sourceId": "enc-771",
  "description": "OPD consultation", "amountMinor": 50000, "currency": "NPR",
  "taxRateBps": 1300 }
```

**201:**

```json
{
  "data": { "id": "chg-3341", "status": "posted", "amountMinor": 50000, "currency": "NPR",
            "taxRateBps": 1300, "createdAt": "2026-08-12T04:41:00Z" },
  "meta": { "context": { "tenantId": "t-demo-care", "facilityId": "f-central" } }
}
```

**Get an invoice with outstanding balance:**

```
GET /api/v1/invoices/inv-1042
```

**200:**

```json
{
  "data": {
    "id": "inv-1042", "invoiceNumber": "INV-1042", "status": "partially_paid",
    "totalMinor": 56500, "currency": "NPR",
    "lines": [ { "chargeId": "chg-3341", "description": "OPD consultation", "amountMinor": 56500 } ]
  },
  "meta": { "outstandingMinor": 56500, "context": { "tenantId": "t-demo-care", "facilityId": "f-central" } }
}
```

**Record a payment** (allocated across invoices; idempotent):

```
POST /api/v1/payments
Idempotency-Key: c5d4…-new-uuid
X-Swasthya-Facility: f-central
{ "method": "cash", "amountMinor": 56500, "currency": "NPR",
  "allocations": [ { "invoiceId": "inv-1042", "amountMinor": 56500 } ] }
```

**201:**

```json
{
  "data": {
    "id": "pay-881", "status": "captured", "amountMinor": 56500, "currency": "NPR",
    "allocations": [ { "invoiceId": "inv-1042", "amountMinor": 56500 } ]
  },
  "meta": { "context": { "tenantId": "t-demo-care", "facilityId": "f-central" } }
}
```

**Errors:** `409 CONFLICT` (invoice already settled for that amount) · `422` (allocation exceeds outstanding) · `409 IDEMPOTENCY_REUSE`.

---

## 22. Client Contract

- **Typed client:** the SPA generates TypeScript types and an API client from the OpenAPI spec — the contract and the code cannot drift (`ARCHITECTURE.md` §3).
- **Retry discipline:** 429 → respect `Retry-After`; 409 `LOCK_CONFLICT` → re-read, re-merge, retry once; 5xx → retry with backoff (max 3); **never** retry an idempotent-less mutation.
- **Headers every client sends:** `Authorization`, `Idempotency-Key` (mutations), `X-Swasthya-Facility` (context hint), `X-Correlation-Id` (per user gesture).
- **Headers every client reads:** `X-Request-Id`, `X-Correlation-Id`, `X-Audit-Event-Id`, `ETag`, `X-RateLimit-*`, `Deprecation`/`Sunset`.

---

*This document is the contract Swasthya's API honors: one envelope, one error taxonomy, one versioning policy, tenancy derived not trusted, idempotency on every clinical and financial write, and concurrency that never silently overwrites. The generated OpenAPI spec is the machine contract; this document is the agreement.*
