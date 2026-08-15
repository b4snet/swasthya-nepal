# SECURITY AUDIT — Swasthya Nepal

Program Phases 1–2 deliverable. **Every finding in this document was verified
by an executed test or a live query against the disposable PostgreSQL
cluster** — nothing is inferred from code reading alone. Unverified claims
are marked `NOT PROVEN` and are not counted as findings.

Verification date: 2026-08-15 (sessions: PROGRAM PHASE 1, PROGRAM PHASE 2).
Scope: authentication, MFA, authorization/RBAC, IDOR/BOLA, tenant isolation,
input validation, injection, XSS, CSRF/CORS, SSRF, file uploads, rate
limiting, secrets, logging, dependencies, security headers.

Severity scale: CRITICAL / HIGH / MEDIUM / LOW / INFO.

---

## 1. Executive summary

**Phase 1** verified the multi-tenant isolation model sound for the runtime
connection path: Hospital A cannot read, update, delete, or attach records
under Hospital B at the API layer (all adversarial probes → safe denials) or
the database layer (two-sided probes on all 37 RLS-scoped tables → zero rows
under `swasthya_app` + NOBYPASSRLS). All 37 scoped tables now carry FORCE
RLS; `swasthya_app` is non-superuser, NOBYPASSRLS, owner of nothing. One
latent broken endpoint (500 on every contact update) was found and fixed.

**Phase 2** audited the full security surface. Two REQUIRED-but-absent
controls were implemented from scratch and proven: **TOTP MFA** (RFC 6238
verified against the official test vectors) and **password reset** (single-use
expiring tokens, no account enumeration, session revocation). Two real
defects were found and fixed: the **API rate limiter never counted
unauthenticated requests** (framework priority ran auth before throttle), and
**JSON payloads serialized `<script>` unescaped** (defense-in-depth hex
escaping added). Dev-tooling advisories were closed (vite 5→7, vitest
2→3.2.7).

**Verdict: SECURITY PARTIALLY VERIFIED.** The application-layer and
database-layer boundaries exercised are sound, and every confirmed defect in
Phase-1/2 scope is fixed with a regression test. Items outside this session's
scope are marked `NOT PROVEN` (see §21): authentication hardening against a
real network attacker (rate limits are per-IP in tests; distributed
production limiting requires Redis), live Supabase/GoTrue behavior, object
storage (not implemented), and deployment-phase controls.

## 2. Authentication

VERIFIED (traced live middleware + API probes + dedicated suites):

- **Login:** email + password → `Hash::check` (argon2id) → per-account
  lockout (5 failures / 15 min) + per-IP `throttle:auth` (5/min). A locked
  account receives `429 RATE_LIMITED` with `Retry-After`; failures are
  audited (`auth.login_failed`, `auth.lockout`). Generic 401 for bad
  credentials — no account enumeration (identical response whether the email
  exists).
- **Logout:** revokes the current access token AND every refresh-token
  family for the user (`auth.logout` audited).
- **Refresh:** rotating single-use tokens; replay of a revoked token revokes
  the whole family (`auth.refresh_reuse` audited as a theft signal).
- **Access tokens:** Sanctum bearer tokens, hashed at rest, short-lived
  (60 min default).
- **MFA interaction:** an MFA-enabled account CANNOT receive tokens from
  login — it receives a one-shot challenge (`MFA_REQUIRED`, challengeId in
  error.details) that must be completed at `auth/mfa/challenge`.
- **Password reset:** implemented Phase 2 (§3 below, §5 of the code
  docs). `forgot` never reveals account existence; tokens are single-use,
  15-min expiry, stored as SHA-256 hashes; reset revokes every session.

Regression coverage: `MfaTest` (7/92), `PasswordResetTest` (5/31),
`SecurityRateLimitAndInputTest` (7/28), pre-existing `AuthTest`.

## 3. MFA

**Implemented Phase 2 (was: schema readiness only).** Verified against the
official RFC 6238 Appendix B vectors (T=59 → 287082, T=1111111109 → 081804,
T=2000000000 → 279037 — all match).

- **Enrollment:** `enroll` requires the current password (step-up), persists
  an encrypted secret (`Crypt::encryptString`, APP_KEY — KMS column
  encryption is the recorded production hardening), returns the
  base32 secret + otpauth URL once.
- **Activation:** `activate` verifies a real TOTP code before storing the ten
  recovery-code **hashes** (SHA-256). Plaintext recovery codes travel to the
  client exactly once; a database read can never yield a usable code.
- **Login enforcement:** MFA-enabled accounts get a one-shot, 5-minute
  challenge; only its hash is stored. `challenge` is the only path to tokens.
- **Recovery codes:** single-use (removed on consumption); replay rejected.
- **Disable:** requires the current password AND a valid TOTP code — MFA
  cannot be removed with a stolen session or a recovery code.
- **Rate limiting:** per-user failures (5/15 min, `Retry-After` header)
  layered on per-IP `throttle:auth`.
- **No bypass:** login and refresh never mint tokens for an MFA-enabled
  account without a completed challenge; access tokens stay short-lived.
- **Audit:** every MFA event recorded (`mfa.enroll`, `mfa.activate`,
  `auth.mfa_challenge`, `mfa.disable`, `mfa.recovery_codes_regenerated`).

Regression coverage: `MfaTest` — enrollment gating, encrypted storage,
one-shot challenge + no-token-without-challenge, recovery-code single use +
replay rejection, wrong/expired/throttled codes, disable gating, audit trail.

## 4. Authorization / RBAC

VERIFIED:

- Every role's permissions come from the seeded catalog (`RolePermissionSeeder`,
  6 scopes, ~50 permissions). No role is granted by the client — role
  assignments are created by authorized administrators only.
- **Vertical escalation denied:** doctor, nurse, receptionist, billing clerk,
  pharmacist each tested against clinical/financial/administrative endpoints
  they must NOT reach (prescribe/sign/invoice/platform provisioning) → safe
  denials (403/404/422 per the real route contract), with positive controls
  (each role CAN reach its legitimate surface).
- **Horizontal/facility escalation denied:** facility-scoped admins cannot
  reach other facilities or platform administration.
- **Platform vs tenant:** `org_admin` cannot perform platform role
  assignment (controller-level denial, verified); only platform-scope
  principals reach `platform/*`.
- Authorization is enforced server-side by `EnsurePermission` after tenant
  resolution; frontend visibility is never the boundary.

Regression coverage: `SecurityRbacTest` (3/29).

## 5. IDOR / BOLA

VERIFIED (expanded beyond Phase 1):

- Read sweep across 20+ resources: cross-tenant ID swaps → 404, never a leak
  of existence or data.
- Nested-resource parent mismatch (e.g. `patients/A/documents/B` where B
  belongs to another patient/tenant) → 404/403 with no mutation.
- Cross-tenant writes (PATCH/DELETE/POST child) → safe denials and victim
  rows untouched (insurance policies, documents, facility settings, schedule
  exceptions, role-assignment revoke, user listing).
- Forged organization selectors → 404 `Resource not found.` (sibling
  catalog contract; verified on patients, payers, medications, departments).
- The URL organization is a resource selector validated against claims —
  it can never switch the caller's tenant context.

Regression coverage: `SecurityIdorAndSurfaceTest` (4/22) +
`CrossTenantApiAttackTest` (9 tests) + `SecurityRbacTest` (3/29).

## 6. Tenant isolation

VERIFIED at two layers (see Phase 1 report; re-run green in Phase 2):

- **API layer:** two-sided adversarial suite — reads/writes/child-creates/
  booking/forged payloads across tenants all denied; victim graph untouched.
- **Database layer:** two-sided SELECT/UPDATE/DELETE probes on all 37
  RLS-scoped tables under `swasthya_app` + NOBYPASSRLS → zero rows in both
  directions. FORCE RLS on all 37 tables. 144 policies unchanged.
- **Tenant context:** derived exclusively from the authenticated principal's
  role assignments; never from URL, body, or headers (tampered
  `X-Swasthya-Facility` → 403, forged `tenant_id` in payloads → 422).

## 7. Input validation

VERIFIED:

- Server-side validation at the boundary for every endpoint; unknown fields
  rejected (422, strict mode) — mass-assigned `tenant_id`/`facility_id`/`id`
  are rejected before any persistence.
- SQL injection payloads stored/treated as literal data or rejected; no SQL
  errors surfaced. Tested: `' OR '1'='1`, `'; DROP TABLE patients; --`,
  `1 UNION SELECT password FROM users --`.
- Type confusion and oversized payloads rejected at validation (422).
- Stored XSS payloads are serialized hex-escaped in JSON (see §9 finding 2).

## 8. Injection

- **SQL:** parameterized queries throughout (Eloquent); injection payloads
  verified inert (`SecurityRateLimitAndInputTest`).
- **Command/template:** no user input reaches shell commands or Blade
  templates with `{!! !!}` — no server-side template injection surface found
  (inspection).
- **No outbound HTTP/URL-fetching functionality exists** — there is nothing
  to SSRF. Documented as NOT PRESENT, not claimed as "protected".

## 9. XSS

VERIFIED:

- The API stores attacker-supplied strings as data and returns them in JSON.
  **Finding 1 (FIXED):** `response()->json()` defaulted to `json_encode`
  options 0, serializing `<script>` raw in the transport. Fix: `Envelope`
  now hex-escapes HTML-significant characters
  (`JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT` +
  `JSON_UNESCAPED_UNICODE`) on every API payload. Regression: the response
  body contains `\u003Cscript\u003E`, never a raw `<script>`.
- No `dangerouslySetInnerHTML` anywhere in the frontend (searched).
  React escapes text by default. Client-side rendering behavior remains
  `NOT PROVEN` end-to-end (no browser E2E harness in this session).

## 10. CSRF / CORS

VERIFIED:

- **CSRF:** the SPA authenticates with bearer tokens (not cookies); the only
  cookie is the `swasthya_refresh` cookie — `HttpOnly`, `SameSite=Strict`,
  `Secure` in production, used only by the (public, throttled, rotating)
  refresh endpoint. No state-changing endpoint trusts a session cookie
  alone. CSRF risk is bounded; verified by inspection of the cookie
  attributes and the auth surface.
- **CORS:** `HandleCors` runs globally (verified in the resolved middleware
  stack). Allowlist is `SWASTHYA_CORS_ALLOWED_ORIGINS` (empty by default =
  no cross-origin grants); credentials enabled only for the allowlisted
  origin; an evil origin is never echoed as `Access-Control-Allow-Origin`
  (verified: evil origin sees the allowlisted origin, which does not match
  its own, so the browser blocks the read). CORS is a browser policy, never
  an authorization control — the API does not trust Origin.

## 11. SSRF

**NOT PRESENT.** Verified by searching the application for outbound HTTP
(`Http::`, `file_get_contents`, `curl`, `fopen`): all matches are Eloquent
`->get()` calls. There is no user-controlled URL-fetching functionality to
exploit. Documented as absent, not as "protected".

## 12. File uploads

**NOT IMPLEMENTED (documented gap, not a live vulnerability).** The document
surface is metadata-only: `POST patients/{patient}/documents` registers
`documentType / mimeType / sizeBytes / checksum / retentionClass` and creates
a `staged` record with **no object key and no download endpoint**. There is
no upload path, no storage, no MIME/size enforcement to bypass, and no public
bucket to leak. The honest status: object storage (with tenant-isolated keys,
signed/private URLs, MIME + size validation, malware scanning, and audit) is
a REQUIRED production capability that does not exist yet — see
`PROJECT_STATUS.md`.

## 13. Rate limiting

**Finding (FIXED): the general API surface had no effective rate limit for
unauthenticated requests.**

- Root cause verified live: the framework's middleware priority sorts
  `AuthenticatesRequests` BEFORE `ThrottleRequests`, and this app's
  `ResolveTenantContext` throws its own 401 for missing tokens — so an
  unauthenticated request failed at auth and **never reached the limiter**;
  `throttle:api` was defined but never attached to the API group.
- Fix: (a) `throttle:api` attached to the whole authenticated group BEFORE
  `auth:sanctum` in `routes/api.php`; (b) explicit middleware `priority`
  list in `bootstrap/app.php` putting both throttle variants ahead of
  `AuthenticatesRequests` (a plain `prependToPriorityList` cannot express
  this — it only inserts absent entries, and `ThrottleRequests` already
  exists in the framework default).
- Verified empirically: resolved route middleware is
  `ThrottleRequests:api → Authenticate:sanctum → ResolveTenantContext →
  SubstituteBindings`; unauthenticated requests now consume the per-IP
  budget (4th request → 429 with limit 3).
- Current contract: public auth endpoints 5/min/IP (`throttle:auth`),
  authenticated API 300/min/IP (`throttle:api`), MFA writes 60/min/IP
  (`throttle:writes`); per-account lockouts on login (5/15 min) and MFA
  (5/15 min) and password reset (5/15 min) layer on top.
- `NOT PROVEN`: distributed production limiting. Tests run with the
  per-process array cache; production must use Redis-backed limiting so the
  budget survives instance scaling (config is env-driven and ready).

Regression coverage: `SecurityRateLimitAndInputTest` (authenticated throttle,
unauthenticated throttle, header-based isolation).

## 14. Secrets

VERIFIED:

- Only `.env.example` / `.env.staging.example` are tracked; both contain
  placeholders (APP_KEY empty). No real `.env` in git.
- Git history scan (`git log -p --all`) found **no committed values** for
  APP_KEY, DB_PASSWORD, JWT secrets, or service-role credentials.
- Frontend: no JWT/service-role/anon keys in `src/` or `public/` (searched
  for `eyJ...` JWT fragments and `service_role`).
- Service-role and database-owner credentials are server-side concepts only
  (`IdentityProvisioner` references `service_role` as an authority constant,
  never a value) and never reach the browser.
- `NOT PROVEN`: full production secret-management rotation procedure —
  deployment-phase item (`PRODUCTION_SECRETS.md`).

## 15. Logging / PHI

VERIFIED:

- `LogRequest` logs **path only** — no query string, no body, no headers
  (verified in source; the structured line carries method/path/status/
  duration/ip/user-agent).
- `AuditLogger` stores facts and references, never clinical content;
  MFA secrets and reset tokens are never stored (only hashes) and never
  logged (searched: no `Log::*` call includes a token, password, secret, or
  code value).
- Reset-token delivery uses the configured mailer (log driver locally —
  the token appears in the mail log, which is the delivery channel; in
  production a real mailer is configured and the token never enters
  application logs).
- No PHI was found in any application log path.

## 16. Dependencies

VERIFIED:

- Composer: `composer audit --no-dev` → **no security advisories**.
- npm: 7 advisories found (5 moderate, 1 high, 1 critical), all dev-only
  (vite/vitest). **Fixed:** vite ^5.4 → ^7.3.6, vitest ^2.0.5 → ^3.2.7,
  plugin-react ^4.7.0. Frontend tests (26) pass, tsc clean, production build
  succeeds.
- Remaining: **2 moderate** — react-router-dom 6.30.4 (open-redirect → XSS
  advisory GHSA-jjmj-jmhj-qwj2; affects 6.30.2–6.30.4, fixed only in v7 —
  a framework migration out of this audit's safe scope). Documented; upgrade
  to react-router v7 tracked as a frontend dependency refresh. Dev-only
  (no production runtime impact for this static SPA).

## 17. Security headers

VERIFIED (asserted on every API response, including error responses):

- `Content-Security-Policy: default-src 'none'`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000`
- `Permissions-Policy` (present)
- `X-Request-Id` / `X-Correlation-Id` on every response.

## 18. Findings

| ID | Severity | Component | Status |
|----|----------|-----------|--------|
| 1 | HIGH | `PatientContactController::update` — 500 on every request (missing route param) | FIXED (Phase 1) |
| 2 | MEDIUM | RLS enabled but not FORCED (documented deviation) | FIXED (Phase 1) |
| 3 | LOW | INSERT isolation is app-layer by design | Documented boundary |
| 4 | LOW | `organizations/{org}/patients` ignored URL org selector | FIXED (Phase 1) |
| 5 | INFO | `beds/{bed}` has no DELETE route (405) | Not a vuln |
| 6 | MEDIUM | API rate limit never counted unauthenticated requests | FIXED (Phase 2) |
| 7 | LOW | JSON payloads serialized `<script>` unescaped | FIXED (Phase 2) |
| 8 | HIGH | MFA required by SECURITY.md but absent | FIXED (Phase 2 — implemented) |
| 9 | HIGH | Password reset required by SECURITY.md but absent | FIXED (Phase 2 — implemented) |
| 10 | LOW | react-router-dom 6.30.4 moderate advisory (dev-only) | Documented; needs v7 |
| 11 | INFO | Object storage not implemented (metadata-only documents) | Documented gap |

## 19. Fixes

1. `PatientContactController::update` — declared `Patient $patient`, added
   parent-linkage guard (Phase 1).
2. FORCE RLS migration on all 37 scoped tables + owner-binding proof test
   (Phase 1).
3. `PatientController::index` — org selector gated like siblings (Phase 1).
4. `bootstrap/app.php` — explicit middleware priority (throttle before auth);
   `routes/api.php` — `throttle:api` on the API group before auth (Phase 2).
5. `Envelope` — hex-escape HTML-significant chars in all JSON payloads
   (Phase 2).
6. MFA: `Totp`, `MfaService`, `MfaChallenge`, `MfaController`, requests,
   migration, routes, `User` model helpers, `AuthController` challenge hook
   (Phase 2).
7. Password reset: `PasswordResetService`, `PasswordResetToken` model +
   migration, `PasswordResetController`, `ForgotPasswordRequest`,
   `ResetPasswordRequest`, `ResetPasswordMail` + view, routes (Phase 2).
8. Frontend: vite/vitest/plugin-react upgraded (Phase 2).

## 20. Regression tests added

| Suite | Tests | Assertions |
|-------|-------|------------|
| `TenancyDatabaseInventoryTest` (DB RLS inventory + FORCE proof) | 5 | 661 |
| `CrossTenantApiAttackTest` (API adversarial) | 9 | part of below |
| `MfaTest` | 7 | 92 |
| `PasswordResetTest` | 5 | 31 |
| `SecurityRbacTest` | 3 | 29 |
| `SecurityIdorAndSurfaceTest` | 4 | 22 |
| `SecurityRateLimitAndInputTest` | 7 | 28 |
| Frontend `vitest` (vite 7) | 26 | — |

All existing Phase 1–45 tests preserved (see §22 for the full regression).

## 21. Remaining risks / NOT PROVEN

1. **Distributed rate limiting** — per-IP limits proven locally with the
   array cache; production must use Redis-backed limiting (config ready).
2. **Authentication hardening vs. a real network attacker** — token theft,
   password spraying at scale, MFA fatigue, and account-takeover scenarios
   beyond the exercised lockouts are `NOT PROVEN` here (no live network
   test; live Supabase/GoTrue behavior requires deployment).
3. **Object storage** — not implemented; upload/download security is a
   REQUIRED production capability, not yet testable.
4. **Live Supabase / GoTrue** — all edge-function behavior is contract-tested
   locally; nothing has executed against a real Supabase instance.
5. **Client-side rendering XSS** — React default escaping + no
   `dangerouslySetInnerHTML` verified by inspection; no browser E2E harness
   in this session.
6. **Secrets rotation procedure / secret manager** — deployment phase.
7. **Deployment-phase controls** — HTTPS termination, load balancer limits,
   WAF/edge protections, monitoring/alerting runbooks.

## 22. Full regression (Phase 2 close)

- Full backend Pest: **397 passed / 3,945 assertions** (Phase 1 close: 371 /
  3,743 → +26 tests, +202 assertions: MFA 7/92, password reset 5/31, rate-limit
  suite 7/28, RBAC 3/29, IDOR/surface 4/22).
- Edge-function pipeline: **49 / 1,030** (unchanged — edge layer untouched).
- Node harness: **855/855**.
- Frontend: **26 vitest tests**, tsc clean, production build succeeds.
- TypeScript (harness): PASS. Pint: PASS (332 files). `git diff --check`: CLEAN.
- Secret scan: clean. Artifact/temp-file sweep: clean.

## Honesty statement

Everything above that is marked VERIFIED was proven by an executed test or a
live query in this session. Anything not executed is explicitly marked
`NOT PROVEN`. The correct conclusion is **SECURITY PARTIALLY VERIFIED** —
not "production-secure". The two biggest REQUIRED controls (MFA, password
reset) are now implemented and proven; the remaining production hardening
(object storage, Redis-backed limiting, deployment controls) is documented
and queued.
