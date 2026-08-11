# SECURITY.md — Swasthya Security Design

> **Status:** Working baseline · **Owner:** Principal Architect (security posture ratified with the team)
> **Version:** 1.0
> **Document chain:** This document is the security counterpart of `MASTER_RULES.md` (§6–10), `ARCHITECTURE.md` (§9–22), and `DATABASE.md` (§1). It specifies the **security controls** — required, recommended, and future — that the platform must implement. It does not implement features.
>
> **Compliance posture (read first):** This document claims **no** regulatory compliance. Nothing here is a statement that Swasthya "complies with" any law, standard, or certification (including Nepal's privacy law 2075, ISO 27001, SOC 2, or any healthcare framework) — such claims are made only after qualified legal/security assessment with documented evidence, per `PRODUCT_REQUIREMENTS.md` §9. What this document provides is the **technical control design** that positions the platform to be assessed.

---

## 0. Security Posture

### 0.1 Threat model (what we defend against)

| Adversary | Motivation / method |
|---|---|
| **External attackers** | Internet-facing API and patient portal: exploitation, credential stuffing, scanning, data scraping |
| **Cross-tenant attackers** | A tenant's staff probing *other tenants'* data — the SaaS-specific threat that RLS exists for |
| **Compromised accounts** | Phishing, credential reuse, stolen devices — a legitimate login used illegitimately |
| **Malicious/compromised insiders** | Staff or admins with legitimate access exceeding their authority |
| **Supply chain** | Vulnerable dependencies, compromised integration providers, malicious packages |
| **Data exposure** | Misconfiguration (public buckets, open ports, leaked secrets), logs, backups, disposed devices |

### 0.2 Defense posture

1. **Defense in depth:** authentication → authorization → row-level security → audit. No single control is trusted; each layer assumes the one before it may fail.
2. **The database is the last line of defense** for tenant isolation (`DATABASE.md` §1.5): RLS holds even if application code errs.
3. **Assume breach:** the platform is built so that a compromise is *detected* (audit, monitoring, alerting) and *contained* (least privilege, isolation), not so that compromise is impossible.
4. **No secrets, no short-circuits:** no credentials in code, no bypass flags, no "trusted internal" endpoints (`MASTER_RULES.md` §6, §8).
5. **Safety-critical actions get extra controls** (confirmation ladder, two-person verification — `DESIGN_SYSTEM.md` High-Risk Actions) and are always audited.

### 0.3 Control tiers

- **[REQUIRED]** — Must exist before production go-live; enforced by CI, review, or architecture; a missing required control is a release blocker.
- **[RECOMMENDED]** — High-value controls scheduled early (within the first operating year or at a stated scale threshold); absence is a documented, owned risk.
- **[FUTURE]** — Planned capabilities gated on scale, compliance findings, or external availability; recorded so they are not reinvented.

---

## 1. Authentication

- **[REQUIRED]** Token-based authentication (short-lived access tokens + rotating refresh tokens) for all clients — no session cookies for the API (`ARCHITECTURE.md` §9).
- **[REQUIRED]** Four identity classes with separate credential and session policies: patients, clinical staff, org admins, platform superadmins (`MASTER_RULES.md` §7.2).
- **[REQUIRED]** MFA for all staff and administrators; patients per tenant policy (Section 3).
- **[REQUIRED]** Every auth event audited: success, failure, lockout, token issue/refresh/revoke, password change, MFA changes.
- **[REQUIRED]** Rate limiting and lockout on all auth endpoints (Sections 17–18).
- **[RECOMMENDED]** Step-up authentication: re-authenticate (MFA) for privileged or high-risk actions (Section 26).
- **[FUTURE]** Passkeys/WebAuthn as a first-class credential; risk-based authentication (device/behavior signals) at national scale.

## 2. Password Security

- **[REQUIRED]** Passwords hashed with argon2id (or bcrypt at a compliant cost); **never** stored plaintext, reversible, or in logs.
- **[REQUIRED]** Breached-password checking on registration and password change (against a maintained breach list).
- **[REQUIRED]** Server-enforced policy (length/complexity floor), no password hints, no password-reveal in the UI beyond an explicit, reason-scoped reveal.
- **[REQUIRED]** Password change invalidates all outstanding tokens and sessions for that user.
- **[REQUIRED]** Password reset: single-use, short-lived reset tokens; reset requires proof of account control; reset events audited.
- **[RECOMMENDED]** Passkeys as an alternative to passwords for staff.
- **[FUTURE]** Passwordless-first flows for patient accounts where tenant policy allows.

## 3. MFA Readiness

- **[REQUIRED]** TOTP MFA for staff and administrators at first login after onboarding; no staff account operates without MFA.
- **[REQUIRED]** Single-use recovery codes issued at enrollment; enrollment and removal are audited and gated (a staff member cannot remove their own MFA without an approved flow).
- **[REQUIRED]** MFA challenge on refresh of sensitive scopes; no MFA bypass path exists in code (no "remember this device forever" without policy).
- **[RECOMMENDED]** WebAuthn/FIDO2 (hardware keys, platform authenticators) for administrators.
- **[RECOMMENDED]** SMS OTP only as an explicitly opt-in fallback, with the known weaknesses (SIM swap, interception) documented and monitoring for anomalous use.
- **[FUTURE]** Risk-based step-up: MFA triggered by anomaly (new device, new geo, privileged action) rather than only at login.

## 4. Session Management

- **[REQUIRED]** Short-lived access tokens (15–60 min) with rotating refresh tokens; rotation detects **reuse** (a replayed refresh token revokes the entire token family and alerts).
- **[REQUIRED]** Server-side session revocation: logout, password change, role change, and offboarding revoke everywhere, immediately (`MASTER_RULES.md` §7.6).
- **[REQUIRED]** Refresh tokens stored as `httpOnly, Secure, SameSite=Strict` cookies; access tokens held in memory in the SPA (never in localStorage).
- **[REQUIRED]** Idle timeout for sensitive surfaces (admin, billing, clinical) per policy; explicit, audited logout.
- **[REQUIRED]** Sessions bound to the issuing device where feasible (token fingerprint).
- **[RECOMMENDED]** User-visible active-sessions list with remote revocation ("sign out of other devices").
- **[FUTURE]** Concurrent-session policy per identity class (e.g., patients: N devices; staff: hard limit).

## 5. Token Security

- **[REQUIRED]** Tokens travel only over TLS; never in URLs, logs, or analytics payloads; never rendered in the UI.
- **[REQUIRED]** Tokens carry scopes/abilities matching the role; the API rejects tokens for scopes the principal lacks (Section 10).
- **[REQUIRED]** Tokens stored **hashed** at rest (the server stores a hash, not the token) — a database read yields no usable token.
- **[REQUIRED]** Short expiry; revocation on password change, role change, account lock, and offboarding.
- **[REQUIRED]** Token issuance, refresh, and revocation are audited with actor, device, and IP.
- **[RECOMMENDED]** Token fingerprinting against device theft; anomaly alerts on token reuse or new-device use of a privileged token.
- **[FUTURE]** OAuth2/OIDC for partner/external access (scoped, short-lived, per-partner) — only for external systems, never replacing the internal token flow (`ARCHITECTURE.md` §9).

## 6. RBAC

- **[REQUIRED]** Seeded role model with action rights *and* scope (org/facility/branch/record); permission checks only in policies (`MASTER_RULES.md` §9).
- **[REQUIRED]** Authorization matrix tested for every role × action; a permission that cannot be tested is not added.
- **[REQUIRED]** Segregation of duties where it matters: requester ≠ approver, charge ≠ void, entry ≠ verification, prescribe ≠ dispense.
- **[REQUIRED]** Role changes take effect immediately and are audited (grant, revoke, scope change).
- **[RECOMMENDED]** Quarterly review of high-privilege assignments (org admins, finance, clinical leads).
- **[FUTURE]** Attribute-based extensions (ABAC) for record-level conditions (e.g., doctor sees patients of their own department) as policy complexity grows.

## 7. Least Privilege

- **[REQUIRED]** Every actor, role, service account, and DB role holds the minimum rights needed; default is deny.
- **[REQUIRED]** The application database role (`swasthya_app`) is non-owner, non-superuser, no `BYPASSRLS`, scoped grants per schema (`DATABASE.md` §1.5).
- **[REQUIRED]** No shared accounts (no `admin/admin`, no team logins); every action traces to a person or a named service.
- **[REQUIRED]** Platform operations use least-privilege, MFA-protected accounts; no standing production superuser in daily use (Section 28).
- **[RECOMMENDED]** Automated entitlement review (periodic report of who holds what, matched against roles) with sign-off by org/facility admins.
- **[FUTURE]** Just-in-time privilege elevation with expiration and approval for platform operations (Section 28).

## 8. Tenant Isolation

- **[REQUIRED]** Single-database + RLS with `FORCE ROW LEVEL SECURITY` and the dedicated app role; policies `USING/WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)` (`DATABASE.md` §1.5).
- **[REQUIRED]** Tenant context derived only from the authenticated principal; never from client input; re-validated per request and per background job.
- **[REQUIRED]** Tenant-safe composite foreign keys — a cross-tenant reference is structurally impossible (`DATABASE.md` §0.9).
- **[REQUIRED]** Tenant scoping in every execution context: jobs, caches, object storage keys, search, realtime channels (`ARCHITECTURE.md` §8.4).
- **[REQUIRED]** The cross-tenant leakage test suite is a CI gate (attempts reads/writes across tenants at API and DB layers) (`MASTER_RULES.md` §16.4).
- **[RECOMMENDED]** Periodic automated isolation audit: a harness queries for any row reachable outside its tenant context and fails on findings.
- **[FUTURE]** Schema-per-tenant escalation for enterprise/compliance customers, behind the existing tenant-context abstraction (`ARCHITECTURE.md` §28.7).

## 9. Facility Isolation

- **[REQUIRED]** Facility/branch scoping enforced in the policy layer for all facility-local records (appointments, encounters, beds, stock, schedules) — a facility user cannot reach another facility's records within the same tenant (`DATABASE.md` §1.4).
- **[REQUIRED]** Facility/branch context never accepted from the client; always derived from role scope.
- **[REQUIRED]** Authorization tests include facility-boundary cases (same role, different facility → denied).
- **[RECOMMENDED]** Optional hard facility isolation: for tenants that want facility walls enforced at the database layer, a configurable RLS policy dimension — designed behind the same context abstraction.
- **[FUTURE]** Cross-facility referral/transfer workflows with explicit, audited access grants (a patient transferred between facilities is visible to the receiving facility under a documented grant, not by loosening isolation).

## 10. API Authorization

- **[REQUIRED]** Every endpoint and action is authorized; there are no internal endpoints that skip authorization (`MASTER_RULES.md` §8.2).
- **[REQUIRED]** Resource access by ownership/scope, never by untrusted ID alone; fetching by ID validates the caller may see that record in that scope.
- **[REQUIRED]** All API input validated server-side; validation failures return structured errors, never 500s (`MASTER_RULES.md` §12).
- **[REQUIRED]** Idempotency keys on clinical/financial mutations prevent replay duplication (`MASTER_RULES.md` §12.4).
- **[REQUIRED]** Rate limits per route class and per account (Sections 17–18).
- **[RECOMMENDED]** Partner/external APIs with OAuth2 scopes, per-partner keys, and independent quotas (Section 5, FUTURE).
- **[RECOMMENDED]** Request signing or mutual TLS for machine-to-machine paths where the ecosystem supports it.

## 11. Encryption in Transit

- **[REQUIRED]** TLS 1.2+ everywhere, **preferring TLS 1.3**; no plaintext HTTP in production, including internal service traffic.
- **[REQUIRED]** HSTS with preload intent; certificates managed with automated renewal and expiry monitoring (a near-expiry cert is a paging alert).
- **[REQUIRED]** TLS termination at the edge (CDN/ALB) with end-to-end TLS to the application; database and Redis connections encrypted.
- **[REQUIRED]** No legacy cipher suites; TLS configuration reviewed against current best practice on a cadence.
- **[RECOMMENDED]** mTLS between internal components where the platform runs its own infrastructure (worker ↔ broker, app ↔ DB proxy).
- **[FUTURE]** Certificate transparency / public-key pinning policy if client cert validation is ever required for integrations.

## 12. Encryption at Rest

- **[REQUIRED]** Storage-level encryption for all data stores: database volumes, object storage, backups (managed KMS keys).
- **[REQUIRED]** **Column-level encryption (pgcrypto) for the most sensitive identifiers**: national IDs (e.g., NPRN), license numbers, bank details, MFA seeds — keys in KMS, never in the database or application code (`DATABASE.md` §0.12, §3.12).
- **[REQUIRED]** Object storage server-side encryption (SSE-KMS) with tenant-scoped key prefixes; documents never stored unencrypted.
- **[REQUIRED]** Backups encrypted with keys separate from the live environment's key hierarchy where feasible.
- **[RECOMMENDED]** Application-layer envelope encryption for the highest-sensitivity patient identity fields, isolating keys per tenant (tenant master keys) when compliance assessment recommends it.
- **[FUTURE]** Full database-level TDE from the managed provider where it becomes available and is assessed.

## 13. Secrets Management

- **[REQUIRED]** All secrets (DB credentials, API keys, encryption keys, integration credentials) live in the managed secrets store; applications read them at runtime (`MASTER_RULES.md` §29).
- **[REQUIRED]** No secrets in source code, committed `.env` files, images, CI logs, docs, or screenshots; `.env.example` contains placeholders only.
- **[REQUIRED]** CI secret scanning blocks merges; a committed secret is revoked and rotated immediately, reported as an incident.
- **[REQUIRED]** Rotation on schedule and on personnel/credential change; leaked-secret handling is a documented runbook step.
- **[REQUIRED]** Least-privilege access to the secrets store; access to secrets is itself logged.
- **[RECOMMENDED]** Dynamic/short-lived database credentials (IAM-based or tokenized) instead of static passwords for the application role.
- **[FUTURE]** External secrets operator / workload identity so no long-lived credential exists in any environment.

## 14. Database Security

- **[REQUIRED]** The application connects as `swasthya_app` — non-owner, non-superuser, RLS-enforced (`DATABASE.md` §1.5); migrations run with a separate, scoped deploy role that holds no runtime privileges.
- **[REQUIRED]** The database is not publicly reachable: private subnets, security-group/network isolation, access only via the app tier and audited bastion.
- **[REQUIRED]** Encrypted connections to PostgreSQL; PgBouncer requires authentication; no trust-authentication anywhere.
- **[REQUIRED]** All queries parameterized via the ORM; raw SQL only through vetted, reviewed named abstractions (Section 21).
- **[REQUIRED]** Grants are scoped per schema/table; the app role can neither `DROP` nor read another tenant's rows (RLS) nor read the audit table's write path.
- **[RECOMMENDED]** Database activity monitoring (slow-query and anomaly alerting); prepared statements throughout; connection pool saturation monitored.
- **[FUTURE]** Database audit triggers as an additional belt-and-suspenders layer if compliance assessment demands it.

## 15. File Security

- **[REQUIRED]** All documents in object storage accessed via **signed, expiring URLs** scoped to the requester's permissions; every access audited (`MASTER_RULES.md` §6.7).
- **[REQUIRED]** Tenant-scoped key prefixes plus IAM-level separation; a tenant cannot address another tenant's prefix (`ARCHITECTURE.md` §12).
- **[REQUIRED]** No PHI in filenames or object keys (IDs, not names); checksums recorded; versioning enabled (never overwrite).
- **[REQUIRED]** Upload validation: type by content (magic bytes), size limits, extension allowlists; uploads enter a **staged → scanned → available** state (Section 16).
- **[REQUIRED]** Encryption at rest (SSE-KMS) and retention lifecycle mirrors the document metadata (`DATABASE.md` §3.38).
- **[RECOMMENDED]** Client-side encryption for the most sensitive upload categories if assessment requires.
- **[FUTURE]** Content inspection/DLP for uploaded documents at national scale.

## 16. Malware Scanning Readiness

- **[REQUIRED]** All uploads are scanned before they become available to users: staged state → scan → publish or quarantine. A quarantined document is visible to administrators as quarantined, never to end users as available.
- **[REQUIRED]** Scan infrastructure (e.g., managed/ClamAV-based) is monitored; scan failure blocks publication (fail closed — an unscannable file is not "clean").
- **[REQUIRED]** Downloaded/exchanged documents pass through the same scan when they enter the system (integration-delivered files are staged like uploads).
- **[RECOMMENDED]** Container image and artifact scanning in the build pipeline (overlaps Section 33, but is part of malware readiness for what we *run*, not just what users *upload*).
- **[FUTURE]** Deep content inspection and AI-assisted malware analysis at national scale; email-attachment analysis if email attachments ever become a supported surface.

## 17. Rate Limiting

- **[REQUIRED]** Rate limits on all API routes, differentiated by class: stricter on auth endpoints, moderate on reads, per-resource on writes; per-IP *and* per-account.
- **[REQUIRED]** Limits are Redis-backed, distributed, and survive instance scaling; headers communicate remaining quota; 429 responses are structured and actionable.
- **[REQUIRED]** Rate limit decisions never trust client-supplied identity (IP spoofing via headers is ignored or validated at the edge).
- **[REQUIRED]** Bypassing or disabling rate limits for any environment is prohibited; rate-limit configuration is versioned like code.
- **[RECOMMENDED]** Edge-layer (WAF/ALB) rate limiting as the first line, application limits as the second.
- **[FUTURE]** Adaptive rate limiting (per-tenant behavior baselines) at national scale.

## 18. Brute-Force Protection

- **[REQUIRED]** Account lockout with exponential backoff per account and per IP; lockouts are audited and alerting on spikes.
- **[REQUIRED]** Credential-stuffing defenses: breached-password rejection, device/token fingerprinting, and anomaly detection on login velocity.
- **[REQUIRED]** Login failures do not reveal whether the account exists (uniform responses) — enumeration resistance.
- **[REQUIRED]** MFA challenge is non-bypassable by lockout resets: resetting a password re-locks until MFA re-established (staff).
- **[RECOMMENDED]** Managed bot mitigation / CAPTCHA on public-facing login surfaces at scale.
- **[FUTURE]** Risk-based authentication gating (geo, device, time-of-day anomalies) before the password challenge (Section 3).

## 19. CSRF

- **[REQUIRED]** The API is bearer-token authenticated (no cookie-based state changes for access), which removes the classic CSRF vector for the data API (`ARCHITECTURE.md` §9).
- **[REQUIRED]** Where cookies are used (refresh token cookie, any admin cookie surface), `SameSite=Strict/Lax` + Secure + HttpOnly, and CSRF tokens protect any cookie-authenticated state change.
- **[REQUIRED]** CORS is strict (Section 24): a malicious origin cannot make authenticated cross-origin requests.
- **[REQUIRED]** All state-changing methods require the auth context (GET never mutates; no CSRF-triggerable side effects via images/forms).
- **[RECOMMENDED]** Double-submit or origin-check validation at the gateway for cookie-authenticated surfaces.

## 20. XSS

- **[REQUIRED]** The SPA renders via React with escaping by default; `dangerouslySetInnerHTML`/`innerHTML` with dynamic content is prohibited (`MASTER_RULES.md` §13.7).
- **[REQUIRED]** **Content Security Policy** restricting script sources, blocking inline scripts, and reporting violations (Section 23).
- **[REQUIRED]** Server-side output encoding discipline: user-supplied content is treated as data everywhere it renders; rich text (clinical notes) goes through a strict allowlist sanitizer if rich text is permitted at all.
- **[REQUIRED]** All input validated server-side; stored XSS is defended at the rendering layer regardless of what was stored.
- **[REQUIRED]** Cookies HttpOnly; no sensitive data in `localStorage`/`sessionStorage`.
- **[RECOMMENDED]** CSP violation reporting wired to the observability pipeline; DOM-based XSS test cases in the frontend test suite.
- **[FUTURE]** Trusted Types enforcement across the SPA.

## 21. SQL Injection

- **[REQUIRED]** All persistence through the ORM/query builder with bound parameters; string-built SQL is prohibited (`MASTER_RULES.md` §5.5).
- **[REQUIRED]** The raw-SQL escape hatch (if ever needed) is a vetted, named, reviewed abstraction with bound parameters — never concatenation.
- **[REQUIRED]** Dynamic identifiers (table/column names) are validated against allowlists, never interpolated from input.
- **[REQUIRED]** RLS is the second layer: even a constructed query cannot cross tenants (`DATABASE.md` §1.5).
- **[RECOMMENDED]** SAST rules (injection-pattern detection) in CI; query-plan review on hot paths.
- **[FUTURE]** Database query firewalling for anomalous statement patterns at national scale.

## 22. SSRF

- **[REQUIRED]** All server-initiated HTTP (integrations, webhooks, outbound notifications) resolves through an **egress allowlist** of known destinations; user-supplied URLs are never fetched by the server.
- **[REQUIRED]** Block access to internal/metadata endpoints (e.g., `169.254.169.254`, private ranges) from application and worker runtimes; outbound traffic egresses through a controlled proxy/NAT.
- **[REQUIRED]** Webhook/URL inputs validate scheme (https only) and destination against the allowlist before any request is made.
- **[RECOMMENDED]** DNS pinning/verification for allowlisted hosts to prevent DNS-rebinding tricks.
- **[FUTURE]** Egress filtering with per-integration identities at the network layer.

## 23. Secure Headers

- **[REQUIRED]** On every response: `Content-Security-Policy` (reporting enabled, no unsafe-inline where achievable), `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `frame-ancestors` (deny or strict allowlist — no framing by third parties), `Permissions-Policy` (camera/mic/geo disabled except telehealth surfaces where scoped).
- **[REQUIRED]** No inline scripts/styles in production builds; headers verified by a test that fails the build on regression.
- **[RECOMMENDED]** CSP nonce/hash-based policies for first-party scripts; header review on every release.
- **[FUTURE]** Certificate Transparency monitoring and preload-list participation for HSTS.

## 24. CORS

- **[REQUIRED]** CORS allowlist contains only the known application origins (SPA origins, staging, support tooling) — never `*` with credentials, never a reflected `Origin`.
- **[REQUIRED]** Preflight handling follows the allowlist; credentials are only sent to allowlisted origins; CORS configuration is verified by an automated test.
- **[REQUIRED]** The API does not trust `Origin` for authorization decisions — CORS is a browser policy, not an auth control.
- **[RECOMMENDED]** CORS regression tests that attempt cross-origin mutations and assert rejection.
- **[FUTURE]** Subdomain isolation for partner surfaces if ever introduced, with their own strict policies.

## 25. Audit Logging

- **[REQUIRED]** One central, append-only, tamper-evident audit pipeline (`MASTER_RULES.md` §19; `DATABASE.md` §3.36): hash-chained, no update/delete path, read-only role, backed up with clinical-grade rigor.
- **[REQUIRED]** Coverage: authentication, authorization denials on sensitive resources, clinical record reads and mutations, financial mutations, role changes, consent changes, document access, data exports, privileged/admin actions, tenant provisioning/offboarding, AI actions.
- **[REQUIRED]** Synchronous audit writes for clinical and financial mutations; the audit write is part of the transaction's correctness.
- **[REQUIRED]** No PHI in operational logs; correlation IDs connect application logs to audit events (`MASTER_RULES.md` §18).
- **[REQUIRED]** Audit retention per compliance policy; losing audit data is a production incident.
- **[RECOMMENDED]** Alerting on audit anomalies (denial spikes, out-of-hours privileged access, unexpected exports).
- **[FUTURE]** Machine-learning-assisted anomaly detection over the audit stream at national scale.

## 26. Privileged Actions

- **[REQUIRED]** The confirmation ladder (`DESIGN_SYSTEM.md` §27) applies to all privileged actions: void/adjust financial records, merge patients, reverse dispenses, discard units, result corrections, discharges, role grants.
- **[REQUIRED]** Reason capture (code + note) for every privileged action; the reason prints in the audit trail.
- **[REQUIRED]** Two-person verification in-app for life-critical classes (transfusion, controlled dispensing, result overrides) — both operators authenticated and recorded (`MASTER_RULES.md` §11; `DESIGN_SYSTEM.md` High-Risk Actions).
- **[REQUIRED]** Break-glass paths (emergency access to a tenant) are explicit, limited, MFA-protected, alerted, and reviewed — never a silent backdoor.
- **[RECOMMENDED]** Step-up authentication (re-MFA) for any privileged action from an unusual device/context.
- **[FUTURE]** Just-in-time privileged role elevation with approval and expiry (Section 7).

## 27. Administrative Access

- **[REQUIRED]** No shared admin accounts; every administrator is an identifiable user with MFA.
- **[REQUIRED]** Platform-superadmin and org-admin are separate roles with separate scopes; org admins cannot reach platform functions and vice versa.
- **[REQUIRED]** All admin actions (settings, roles, tenant lifecycle, entitlement changes) are audited with actor, target, and outcome.
- **[REQUIRED]** Admin sessions are shorter-lived and re-authenticate for sensitive operations; admin login anomalies alert.
- **[RECOMMENDED]** Admin approval workflows for the most sensitive operations (tenant suspension, data export).
- **[FUTURE]** Admin access review dashboards and standing-approval workflows at national scale.

## 28. Production Access

- **[REQUIRED]** No direct production access as a matter of course: all changes (code, config, data migrations) deploy via CI/CD (`MASTER_RULES.md` §21.2). Ad hoc production editing is prohibited.
- **[REQUIRED]** Break-glass production access (console/DB via bastion) is MFA-protected, logged, time-boxed, and reviewed; credentials rotate after use.
- **[REQUIRED]** Database access from the bastion uses the least-privilege review role (read-only where possible); never the app role or superuser for support.
- **[REQUIRED]** No production credentials on developer machines; dev/staging use synthetic data, never production data (Section 29 note).
- **[RECOMMENDED]** Session recording for break-glass sessions; just-in-time access grants.
- **[FUTURE]** Zero-standing-privilege platform operations (all access ephemeral, policy-based).

## 29. Backups

- **[REQUIRED]** Automated backups + WAL archiving (PITR), encrypted, monitored (failure alerts within the hour), multi-AZ, with a cross-region copy (`ARCHITECTURE.md` §25; `MASTER_RULES.md` §23).
- **[REQUIRED]** Restore is proven quarterly: restore into a clean environment and verify critical journeys **and RLS integrity** (a restored database with broken policies would be a data-leak event).
- **[REQUIRED]** Backup storage is isolated from production credentials and access-controlled; backups are never publicly reachable and contain no plaintext PHI beyond what the encrypted data holds.
- **[REQUIRED]** Backup retention per policy (including audit data); offboarding a tenant includes backup-data handling per policy.
- **[RECOMMENDED]** Backup integrity verification (checksums, restore spot-checks) as a scheduled job.
- **[FUTURE]** Immutable backup storage (object-lock) against ransomware-style deletion.

## 30. Disaster Recovery

- **[REQUIRED]** RPO ≤ 15 min / RTO ≤ 4 h (default, ratified annually); multi-AZ; no single point of failure without documented, accepted risk (`MASTER_RULES.md` §22).
- **[REQUIRED]** A written, current DR runbook with contacts and access paths; failover test annually with evidence; restore drills quarterly (Section 29).
- **[REQUIRED]** DR includes the full stack: app, workers, database, Redis, object storage, secrets access path, monitoring.
- **[REQUIRED]** Security controls survive failover: RLS policies, audit pipeline, and secrets resolution are part of the restored environment, not afterthoughts.
- **[RECOMMENDED]** Chaos-tested failure scenarios (region loss, broker loss) on staging-grade environments.
- **[FUTURE]** Active-active multi-region for the highest-tier tenants if business and cost assessment support it.

## 31. Incident Response

- **[REQUIRED]** A written incident-response runbook: severity levels, roles (incident commander, responders, comms, legal), escalation path, and contact tree — current and drilled.
- **[REQUIRED]** Containment-first: isolate (revoke tokens, suspend accounts, disable integrations) before remediation; evidence preserved (audit, logs, snapshots) for analysis and legal obligations.
- **[REQUIRED]** Data-breach notification obligations are assessed with legal counsel *before* an incident, so the response is known in advance (Nepal privacy law 2075 and any applicable law).
- **[REQUIRED]** Every security incident gets a blameless postmortem with actions, tracked to completion; covering up is prohibited and treated as the worst offense (`MASTER_RULES.md` §6.9).
- **[REQUIRED]** Security incidents are paged like production incidents — a suspected breach is never "wait until Monday."
- **[RECOMMENDED]** Tabletop incident exercises on a cadence (quarterly) covering realistic scenarios (credential stuffing, cross-tenant attempt, insider exfiltration).
- **[FUTURE]** Coordinated disclosure program and, at national scale, a bug-bounty program.

## 32. Vulnerability Management

- **[REQUIRED]** Continuous vulnerability scanning of dependencies (Section 33) and container images; critical/high findings block merges and page when found in production.
- **[REQUIRED]** A documented remediation SLA by severity (e.g., critical: immediate/hotfix; high: within days; medium: within release cycle) — ratified with the team, not aspirational.
- **[REQUIRED]** Scheduled vulnerability review cadence covering the deployed stack (OS images, runtimes, managed services).
- **[REQUIRED]** **Penetration testing** on a cadence: an independent pentest before production go-live and at least annually thereafter, plus after major architecture changes; findings tracked to closure.
- **[RECOMMENDED]** SAST in CI (injection, crypto misuse, hardcoded secrets); DAST against a staging environment on a cadence.
- **[FUTURE]** Continuous red-team engagements and a formal bug-bounty program at national scale.

## 33. Dependency Security

- **[REQUIRED]** Lockfiles committed; CI installs locked versions (`MASTER_RULES.md` §27).
- **[REQUIRED]** `composer audit` / `npm audit` (and equivalents) run in CI on every PR; critical and high vulnerabilities block merge.
- **[REQUIRED]** Minimal dependency policy: a dependency is added only when the owning technology cannot do the job with reasonable effort, justified in the PR; abandoned/unmaintained dependencies are prohibited without an ADR.
- **[REQUIRED]** Upgrade discipline: patch/minor on a cadence; major upgrades deliberate and reviewed; a known critical vulnerability in production is an incident.
- **[RECOMMENDED]** License compliance checks in CI; dependabot-style automated PRs with review.
- **[FUTURE]** Software bill of materials (SBOM) publishing for customers and assessors.

## 34. Security Testing

- **[REQUIRED]** The mandatory security test suites are CI gates: cross-tenant leakage, authorization matrix (every role × action), financial idempotency, clinical safety (`MASTER_RULES.md` §16.4).
- **[REQUIRED]** Security regression tests per control: headers, CORS rejection, rate-limit behavior, token revocation, MFA enforcement, lockout, RLS policies (a test that proves a tenant cannot reach another tenant's rows at the SQL layer).
- **[REQUIRED]** Security review gate in the PR process for anything touching PHI, auth, tenancy, or money (two-reviewer rule, `MASTER_RULES.md` Process).
- **[REQUIRED]** Penetration testing on the announced cadence (Section 32) with closure evidence.
- **[RECOMMENDED]** DAST scanning of staging on a cadence; fuzzing of API input handling; frontend security tests (XSS cases, CSP assertions).
- **[FUTURE]** Formal red-team exercises; independent third-party security assessments aligned with compliance assessment work (Section 0).

---

## 35. Control Summary

| Control | Tier | Primary enforcement |
|---|---|---|
| Token auth + MFA for staff | Required | Architecture (`ARCHITECTURE.md` §9) |
| RLS tenancy with FORCE + app role | Required | Database design + leakage test suite |
| Column encryption for sensitive IDs | Required | Database design (`DATABASE.md` §0.12) |
| Secrets store, no secrets in code | Required | CI secret scan + review |
| TLS 1.2+/1.3, HSTS, CSP, strict CORS | Required | Gateway config + header tests |
| Signed expiring URLs + file scanning | Required | Storage design + staged-upload flow |
| Rate limiting + lockout | Required | API middleware + tests |
| Append-only tamper-evident audit | Required | Database design + audit service |
| Incident response + pentest cadence | Required | Runbook + program |
| Passkeys, session UI, step-up MFA | Recommended | Product roadmap |
| Isolation audits, mTLS, JIT elevation | Recommended | Ops program |
| Risk-based auth, DLP, red team, SBOM | Future | Scale/compliance gate |

---

*This document specifies the security controls Swasthya will implement — and the discipline that makes them real: enforced in CI and review, proven by tests, and owned by a named person. It claims no compliance status; it is the engineering that a future compliance assessment will examine. Changes to the security posture go through the ADR process, with evidence, never by accretion.*
