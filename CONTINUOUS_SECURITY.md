# CONTINUOUS_SECURITY.md — SWASTHYA Continuous Security & Resilience Program

> **Status:** Program framework ready — recurring cycle
> **Release:** `d7714aa` on `main`
> **Date:** August 22, 2026
> **Cadence:** Monthly security review, quarterly DR exercise, annual pen test

---

## 0. CRITICAL RULES

1. **Self-testing is not penetration testing** — independent external testing required for certification.
2. **RLS regression must be regular** — tenant/facility/role/patient isolation verified on every major release.
3. **Backup restore must be tested** — a backup that has never been restored is a hope, not a backup.
4. **Security incidents get postmortems** — every incident, no matter how small.
5. **Resilience metrics are tracked** — RTO, RPO, MTTR, incident count, unresolved risk.

---

## 1. Security Baseline

### 1.1 Current State

| Area | Status | Last Verified |
|---|---|---|
| npm vulnerabilities | 0 high/critical, 2 moderate | Phase 122 |
| Composer vulnerabilities | 0 high/critical | Phase 122 |
| RLS policies | 508 policies verified | Phase 122 |
| Secrets | No hardcoded secrets | Phase 122 |
| PHI logging | No PHI in logs | Phase 122 |
| Authentication | Argon2id + MFA | Phase 122 |
| Authorization | RLS + RBAC enforced | Phase 122 |
| Source maps | None in build | Phase 122 |
| CORS | Strict allowlist | Phase 122 |
| Rate limiting | Auth + API throttles | Phase 122 |

### 1.2 Security Review Schedule

| Review | Frequency | Owner |
|---|---|---|
| Dependency scan | Weekly (automated) | CI/CD |
| RLS regression | Every major release | Security Lead |
| Security audit | Monthly | Security Lead |
| Penetration test | Annually | External firm |
| DR exercise | Quarterly | SRE Lead |
| Tabletop exercise | Semi-annually | Security Lead |

---

## 2. Continuous Vulnerability Management

### 2.1 Scan Schedule

| Target | Tool | Frequency | Owner |
|---|---|---|---|
| npm dependencies | `npm audit` | Weekly (CI) | Development |
| Composer dependencies | `composer audit` | Weekly (CI) | Development |
| Container images | Trivy/Grype | On build | DevOps |
| Infrastructure | Cloud security scanner | Monthly | Ops |
| Application | OWASP ZAP/Burp | Quarterly | Security |
| Secrets | GitLeaks/TruffleHog | On commit | CI/CD |

### 2.2 Vulnerability Response

| Severity | Response Time | Action |
|---|---|---|
| Critical | < 24 hours | Emergency patch |
| High | < 7 days | Prioritized fix |
| Medium | < 30 days | Scheduled fix |
| Low | < 90 days | Backlog |
| Informational | Next release | Documentation |

### 2.3 Dependency Status

| Package Manager | Total | High | Critical | Last Scan |
|---|---|---|---|---|
| npm | ~50 | 0 | 0 | Phase 122 |
| Composer | ~30 | 0 | 0 | Phase 122 |

---

## 3. Penetration Testing

### 3.1 Testing Scope

| Area | In Scope | Method |
|---|---|---|
| Authentication | Login, MFA, session, reset | Black box |
| Authorization | RLS, RBAC, IDOR | Gray box |
| API Security | Injection, mass assignment, replay | Black box |
| Frontend | XSS, CSRF, storage, tokens | Black box |
| Infrastructure | Network, services, ports | Black box |
| Data | PHI exposure, backup security | Gray box |

### 3.2 Testing Rules

| Rule | Description |
|---|---|
| Authorized only | Never test without written authorization |
| Sandbox first | Use staging environment, not production |
| No real PHI | Use synthetic data for testing |
| Document everything | Every finding gets evidence |
| Fix before disclose | Critical/High fixed before report |

### 3.3 Pen Test Status

| Test | Date | Firm | Findings | Status |
|---|---|---|---|---|
| Self-test (Phase 122) | Aug 2026 | Internal | 0 critical, 0 high | ✅ Complete |
| External pen test | Pending | TBD | — | ❌ Not performed |

---

## 4. RLS Regression Testing

### 4.1 Test Matrix

| Isolation | Test | Expected | Frequency |
|---|---|---|---|
| Tenant | Cross-tenant data access | 0 rows | Every release |
| Facility | Cross-facility data access | 0 rows | Every release |
| Role | Cross-role privilege escalation | Denied | Every release |
| Patient | Cross-patient data access | 0 rows | Every release |
| Claim forgery | Forged JWT claims | Denied | Every release |
| Missing claims | API without tenant context | 0 rows | Every release |

### 4.2 RLS Regression Schedule

| Trigger | Action |
|---|---|
| Schema migration | Full RLS regression |
| Policy change | Targeted RLS test |
| Major release | Full RLS regression |
| Security incident | Full RLS regression |
| Quarterly | Full RLS regression |

---

## 5. Backup Program

### 5.1 Backup Schedule

| Type | Frequency | Retention | Encrypted |
|---|---|---|---|
| Base backup | Nightly | 30 days | ✅ |
| WAL archiving | Continuous | 30 days | ✅ |
| Cross-region copy | Per backup | 30 days | ✅ |
| Object storage | Versioned | Indefinite | ✅ |

### 5.2 Backup Verification

| Check | Frequency | Owner |
|---|---|---|
| Backup completion | Daily | Automated |
| Backup integrity | Weekly | DBA |
| Restore test | Quarterly | SRE |
| Cross-region verify | Monthly | Ops |

### 5.3 Backup Status

| Metric | Value | Last Verified |
|---|---|---|
| Backup success rate | 100% | Phase 121 |
| Restore drill (1M rows) | 144 seconds | Phase 121 |
| RPO achieved | On-demand (no WAL in dev) | Phase 121 |
| RTO achieved | 144 seconds | Phase 121 |

---

## 6. Disaster Recovery Exercises

### 6.1 DR Exercise Schedule

| Exercise | Frequency | Scope | Owner |
|---|---|---|---|
| Database restore | Quarterly | Full database | DBA |
| Application failover | Quarterly | App + DB | SRE |
| Queue recovery | Semi-annually | Queue workers | Ops |
| Provider outage | Semi-annually | External integrations | Interop |
| Full DR | Annually | Everything | All |

### 6.2 DR Exercise Template

```markdown
# DR Exercise Report

## Date: [YYYY-MM-DD]
## Scope: [what was tested]
## Participants: [who was involved]

## Pre-Exercise
- [ ] Backup verified
- [ ] Standby environment ready
- [ ] Communication plan active

## Exercise Steps
1. [step]
2. [step]
3. [step]

## Results
| Metric | Target | Actual |
|---|---|---|
| RTO | < 4 hours | [value] |
| RPO | < 15 min | [value] |
| Data integrity | 100% | [value] |

## Issues Found
- [issue]

## Remediation
- [action]

## Post-Exercise
- [ ] Lessons learned documented
- [ ] Runbooks updated
- [ ] Monitoring adjusted
```

---

## 7. Security Incident Response

### 7.1 Incident Types

| Type | Severity | Response |
|---|---|---|
| Credential compromise | P1 | Immediate lockout + investigation |
| Data exposure | P1 | Contain + notify + investigate |
| Malicious account | P1 | Lockout + investigate + legal |
| API abuse | P2 | Rate limit + block + investigate |
| Vulnerability exploited | P1/P2 | Patch + investigate + harden |

### 7.2 Incident Response Process

```
DETECT
  ↓
CONTAIN (isolate affected systems)
  ↓
ERADICATE (remove threat)
  ↓
RECOVER (restore normal operations)
  ↓
NOTIFY (affected parties, authorities if required)
  ↓
POSTMORTEM (timeline, root cause, remediation)
  ↓
IMPROVE (prevent recurrence)
```

### 7.3 Notification Requirements

| Event | Notify | Timeline |
|---|---|---|
| Data breach | Hospital IT, affected patients | Within 72 hours |
| Security incident | Security team, management | Within 1 hour |
| Vulnerability exploited | Security team, development | Immediately |
| Service outage | Operations, hospital IT | Within 15 minutes |

---

## 8. Tabletop Exercises

### 8.1 Exercise Types

| Scenario | Participants | Frequency |
|---|---|---|
| Ransomware attack | All teams | Semi-annually |
| Data breach | Security + Legal + Hospital | Annually |
| Infrastructure failure | SRE + Ops | Quarterly |
| Supply chain attack | Security + Development | Annually |
| Insider threat | Security + HR + Legal | Annually |

### 8.2 Tabletop Template

```markdown
# Tabletop Exercise

## Scenario: [description]
## Date: [YYYY-MM-DD]
## Participants: [roles]

## Phase 1: Detection
- How do we detect this?
- Who is notified first?

## Phase 2: Response
- What actions do we take?
- What is our communication plan?

## Phase 3: Recovery
- How do we restore normal operations?
- How do we verify integrity?

## Phase 4: Lessons Learned
- What went well?
- What needs improvement?
- What actions do we take?
```

---

## 9. Resilience Metrics

### 9.1 Key Metrics

| Metric | Definition | Target | Current |
|---|---|---|---|
| RTO | Recovery Time Objective | < 4 hours | 144s (measured) |
| RPO | Recovery Point Objective | < 15 min | On-demand (dev) |
| MTTR | Mean Time To Resolve | < 2 hours | TBD |
| Incident Count | Security incidents per month | < 3 | 0 |
| Unresolved Risk | Open security findings | 0 critical | 0 |
| Backup Success | Backup completion rate | 100% | 100% |
| DR Exercise Success | Exercise pass rate | 100% | TBD |

### 9.2 Reporting

| Report | Frequency | Audience |
|---|---|---|
| Security metrics | Monthly | Security team |
| DR metrics | Quarterly | Operations |
| Resilience score | Quarterly | Leadership |
| Annual security review | Annually | Board |

---

## 10. Continuous Improvement

### 10.1 Review Cycle

| Month | Activity |
|---|---|
| January | Annual security review, pen test planning |
| February | DR exercise, backup verification |
| March | RLS regression, dependency audit |
| April | Security metrics review, tabletop exercise |
| May | DR exercise, backup verification |
| June | Mid-year security review, pen test |
| July | RLS regression, dependency audit |
| August | Security metrics review, tabletop exercise |
| September | DR exercise, backup verification |
| October | RLS regression, dependency audit |
| November | Security metrics review, tabletop exercise |
| December | Annual security report, DR exercise |

---

*This document defines the recurring security and resilience program for SWASTHYA.*
