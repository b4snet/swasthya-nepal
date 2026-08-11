# Swasthya Frontend Foundation Report

Date: 2026-08-11 — Milestone: the Swasthya web application exists and runs the verified OPD workflow end to end.

## 1. Existing Frontend

Before this milestone there was **no application frontend**. The repository contained the engineering contract (nineteen design documents), the Laravel backend (`backend/` — platform foundation + Patient Master + the full OPD clinical workflow, Tenancy V2 with PostgreSQL RLS), and a generated static documentation dashboard (`docs/index.html`) used only for previewing the documents. The README and ARCHITECTURE.md recorded the frontend as "designed — not built", and both the staging-readiness report and DEVELOPMENT_LOG stated that desktop/mobile viewport verification was impossible because no SPA existed.

## 2. Frontend Architecture

A single-page application in `frontend/` (React + TypeScript + Vite) structured as:

- `src/api` — typed API client (`client.ts`: token storage, refresh-with-retry, error mapping; `endpoints.ts`: typed contracts for every backend endpoint used; `types.ts`).
- `src/auth` — `AuthProvider`: login/logout/refresh/session-restore against the real backend; access token in `sessionStorage`, refresh token in `localStorage` (never hardcoded, never sent anywhere but `/auth/refresh`).
- `src/context` — `TenantContext`: server-authoritative facility/role context derived only from the login/refresh `assignments` payload; a `ready` gate prevents tenant-scoped pages from mounting before context resolves.
- `src/components` — the design-system library (tokens in `styles/tokens.css`, reusable UI in `ui.tsx`/`ui.css`).
- `src/layout` — app shell: desktop sidebar, header with facility context, mobile bottom navigation.
- `src/pages` — Login, Dashboard, Patients, PatientRegister, PatientProfile, Appointments, Queue, Encounter (doctor workspace), Billing, Audit.
- `src/hooks` — `useFetch`: loading/error/data with a request-generation guard against out-of-order responses and single-fetch refresh.

Data-fetching: one consistent hook with explicit loading/error/data states, stale-while-revalidate on refresh, and server-confirmed mutations only (no client-only truth for clinical/financial actions). Facility switches invalidate and refetch — stale Tenant A data is never shown in Tenant B context.

## 3. Framework Decision

**React + TypeScript + Vite** is the single primary frontend. Decision rationale: ARCHITECTURE.md prescribes one SPA, mobile-first; React + TypeScript is the documented choice; Angular and any second framework are deliberately not used (the platform assigns one responsibility to one technology — MASTER_RULES.md §3). The backend (Laravel) remains the sole business API and the security boundary. The decision is also recorded in `DEVELOPMENT_LOG.md` and `README.md`.

## 4. Authentication

Real authentication against `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh` (rotating refresh tokens), and `POST /api/v1/auth/logout`:

- Login stores the issued tokens and the `assignments` payload; the backend is authoritative.
- Session restoration on page reload exchanges the refresh token; **a genuine backend bug was found and fixed here** — `refresh` did not return `assignments`, so every page reload crashed the SPA. `AuthController` now returns contract parity with login (with a regression test).
- 401 → clear session → login screen; 403/422/429 are surfaced as user-facing, typed error states; network failure is distinguished from server errors.
- No fake login, no hardcoded users, no fabricated tokens.

## 5. Tenant Context

- The SPA **never derives authorization from local storage**. The only source of context is the server-issued `assignments` payload: `{organizationId, organizationCode, facilityId, facilityName, roles}`.
- The selected facility is sent on every request as the `X-Swasthya-Facility` *proposal*; the backend validates it against the principal's active assignments and derives the tenant context (TENANCY.md V2 §7). Switching facility simply changes the proposal — the backend decides.
- One authorized facility → auto-selected; several → explicit chooser; platform-only → platform surface.
- A `TenantGate` holds rendering until context is `ready`, so no tenant-scoped request can ever fire without resolved context (this fixed a real bug where `/organizations//patients` was issued and the backend 500'd).

## 6. RBAC

Navigation and actions are filtered by the roles in the server-issued assignments (`hasRole(...)`): Queue for hospital_admin/doctor/nurse/receptionist, Billing for hospital_admin/accountant/billing_clerk, Audit for hospital_admin/org_admin/platform_admin, encounter actions gated to `doctor`. These are **UX controls only** — the backend authorization middleware and RLS remain mandatory and were exercised by the E2E (e.g., the doctor starts encounters, the admin issues invoices).

## 7. App Shell

- Desktop: sidebar (Dashboard, Patients, Appointments, Queue, Billing, Audit per role), header with brand, facility context badge/selector, user chip, sign-out; skip-to-content link.
- Mobile: fixed bottom navigation (Dashboard, Patients, Appointments, Queue + More sheet for Billing/Audit), touch-sized targets, single-column layouts.
- Errors, empty states, and loading states are first-class components; the session/context never leaves the user trapped after logout, expiry, or facility revocation (context resets to the login/chooser state).

## 8. Dashboard

Only real backend data: today's appointments, in-queue count, completed count, today's appointments list, and the current queue. Empty states are honest ("Queue is clear", "No appointments today", "Outstanding-balance aggregation is a Phase 13 item — the frontend does not invent it"). No fake numbers.

## 9. Patient UI

- List with search (real API search incl. MRN), registration (real POST, duplicate detection surfaced from the backend, validation errors shown), and profile (demographics, MRN, status, timeline rendered from real events via a structured-summary formatter).
- Full-page reloads work because session restore re-issues the assignments (see §4).
- Sensitive data is only fetched with authorization; a non-authorized user cannot reach patient pages (route gate + backend enforcement).

## 10. Appointment UI

Availability-driven booking: date → real availability from the provider's schedule template → slot selection → booking POST with real backend validation and the double-booking race guard. Booking carries the appointment's service so the invoice derives the consultation charge. List view shows real appointments with statuses. (Cancellation/rescheduling exist in the backend; the UI surfaces status changes from the real API.)

## 11. Check-in

Receptionist check-in from the queue page issues the backend-assigned token ("Checked in — token #N") and moves the appointment into the queue — all from real API responses; a refresh no longer loses the confirmation (stale-while-revalidate fix).

## 12. Queue

- Real queue from `GET /appointments/queue` (checked-in/in-consultation, token-ordered). A contract mismatch was found and fixed: the endpoint returns `appointmentId`, and the SPA was posting `/appointments/undefined/start-encounter`; a `QueueEntry` type and the endpoint's new `encounterId` field complete the contract.
- Doctor actions: start consultation (navigates into the encounter) and reopen an in-consultation encounter.
- Mobile: the same queue is operable from the bottom navigation at an iPhone viewport without horizontal overflow.

## 13. Doctor Workspace

Encounter page with tabs: Clinical note (chief complaint, examination, assessment, plan → draft → sign), Diagnosis (ICD-10 code, type, description, primary flag → backend-persisted), Prescription (medication, dose, frequency, duration → draft), and Sign encounter. Every action is a real API call with server-confirmed notices; nothing is fabricated.

## 14. Billing

Invoice issuance from the signed encounter (real charges: consultation + prescription lines), invoice detail with line items, totals, and status from the real API. The E2E verifies NPR 80.00 = 5,000 consultation + 3,000 medication.

## 15. Payment

Payment capture with method selection and the backend's idempotency key; success is only shown after the backend confirms (the notice and status come from the response; the idempotent replay path is backend-handled — a second capture with the same key does not double-charge). "Paid" status renders from the real invoice.

## 16. Audit

A read-only, authorized audit view (hospital_admin/org_admin/platform_admin) listing actor, action, entity, and timestamp from `GET /audit-events`. The frontend cannot modify or delete audit records; the append-only store is backend-enforced.

## 17. Mobile UX

Verified by the Playwright mobile spec at the iPhone 13 viewport: login → register patient → book appointment → check-in → queue, entirely via the bottom navigation, with `scrollWidth - clientWidth <= 0` asserted (no horizontal overflow) at each step. The layout is mobile-first (single column, bottom nav, large touch targets) rather than a shrunk desktop dashboard.

## 18. Accessibility

Labels are programmatically associated with controls (aria-labelledby via `htmlFor`/`id`), roles are used for dialogs/radiogroups/status, focus is managed on the app shell (skip-link, `#content` target), error messages use `role="alert"`, touch targets follow DESIGN_SYSTEM.md, and the E2E relies on roles/labels (not class names) for interaction. Two genuine a11y ambiguities (duplicate "Phone" labels, "Date" vs "Appointment date") were fixed by disambiguation.

## 19. Error Handling

Typed UI states for 401 (session expiry → login), 403 (authorization denied), 404 (hidden/absent), 409 (conflict — e.g., double-booking), 422 (field validation), 429 (rate limit), 5xx (server), and network failure/timeouts. Clinical/financial actions never imply success before the backend confirms; error notices are specific and in the interface's voice.

## 20. Testing

- **Unit/component (Vitest + Testing Library):** 21 tests — design-system components, login (success/401/429/validation), tenant context (facility auto-select, multi-facility choice, server-issued roles only), audit authorization, timeline summary formatting. Typecheck clean (`tsc -b --noEmit`).
- **E2E (Playwright, real backend + real PostgreSQL under RLS as `swasthya_app`, no mocks):** the primary spec drives the complete workflow Patient → Appointment → Check-in → Queue → Encounter → Clinical documentation → Diagnosis → Prescription → Billing → Payment → Audit (desktop, 58.5 s); the second spec drives the receptionist flow at the iPhone 13 viewport (24.8 s). Both projects run serialized (the backend's double-booking guard is a partial unique index), and each run cancels leftover fixture-date appointments through the real cancel endpoint so the suite is repeatable against a persistent dev DB.
- **Backend regression:** the full Pest suite was re-run green after the controller changes — **241 tests / 1,748 assertions** (including the new refresh-contract assertion and the RLS suite as `swasthya_app`).

## 21. E2E Results

Both specs pass against the live stack (Vite dev server → Laravel on `127.0.0.1:58999` running as `swasthya_app` with RLS active):

- Desktop OPD workflow — **PASSED (58.5 s)**; the database afterwards showed the complete real chain: appointment `completed`, encounter with signed note, diagnosis, prescription, invoice `paid`, payment row, and the audit trail containing every step (`patient.created` → `payment.captured`).
- Mobile receptionist flow — **PASSED (24.8 s)** with no horizontal overflow.

## 22. Performance

Not prematurely optimized. The app issues one request per data need; patient lists are paginated by the backend; the queue loads only the day's appointments; no polling loops (refresh is user-triggered or action-triggered). Page-load time is dominated by the dev-mode Vite transform; the production build is a standard Vite bundle. The one measured backend hot spot (patient name search under RLS at 1M rows, ~57 ms, documented in the staging readiness report) is unaffected by the frontend.

## 23. Security Review

- No secrets in frontend source; no database or service-role credentials reach the browser (the SPA talks only to the API).
- Tenant/facility ids in the browser are **proposals only** — never authorization; backend + RLS remain authoritative (verified live: 401 without a token, 403 for out-of-scope facilities, and the RLS suite as the least-privilege role).
- No bypass endpoints or hidden routes; the audit view is role-gated and read-only.
- Access tokens live in `sessionStorage`, refresh tokens in `localStorage` (standard for this backend's bearer model); nothing sensitive is cached beyond the current session's API responses in React state, which is cleared on context/session changes.
- A refresh-contract defect (missing `assignments`) that would have left the app mis-routed after reload was found and fixed with a regression test.

## 24. Staging Readiness

No staging environment exists — `STAGING.md` is the concrete build spec (services, env vars, DB role bootstrap, storage, secrets, networking, TLS, health checks, monitoring, deployment steps, acceptance checklist), and no cloud provider has been invented. The frontend deploys as a static Vite build served behind the API gateway per `STAGING.md`; the CI definition (`.github/workflows/ci.yml`) covers the backend and its disposable-PostgreSQL pipeline, but no real runner has executed it (the repository is not yet a git repo).

## 25. Remaining Risks

1. No CI runner has ever run the pipeline; no git repository yet.
2. No staging environment built from `STAGING.md` — deployment topology unverified.
3. The mobile E2E covers the receptionist flow; the doctor workspace at mobile viewport is exercised only on desktop.
4. MFA TOTP, secrets management, and compliance assessment remain open (recorded in DEVELOPMENT_LOG).
5. The patient-name search RLS hot spot is documented, not fixed (no index helps with the current facility OR-NULL predicate).
6. E2E uses the fixture identities and dev database; the suite is repeatable but leaves append-only audit history (by design).

## 26. Final Verdict

**READY FOR STAGING.**

The frontend foundation is real: a React + TypeScript SPA that performs the complete verified OPD workflow against the real backend and real database with real authentication, real authorization, real tenant context, real RLS, and real audit — proven by 21 unit tests, 2 Playwright E2E specs (desktop + mobile), and the 241-test backend suite. It is **not** READY FOR PRODUCTION REVIEW: no staging environment exists, CI has never run on a real runner, and the deployment topology from `STAGING.md` is unverified.
