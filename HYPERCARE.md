# HYPERCARE.md — SWASTHYA Post-Go-Live Hypercare

> **Status:** Framework ready — requires production deployment to activate
> **Release:** `09fa982` on `main`
> **Duration:** 30 days minimum (extendable to 60/90 days)
> **Activation:** Only after PHASE 126 GO-LIVE SUCCESSFUL

---

## 0. CRITICAL RULES

1. **No major new modules** during hypercare — stabilization only.
2. **All fixes follow release process** — branch, tests, review, CI, approval, rollback.
3. **No untested deployments** — every change must have automated test coverage.
4. **No hidden issues** — every incident is classified, tracked, and resolved.
5. **No premature exit** — hypercare ends only when exit criteria are met.

---

## 1. Hypercare Timeline

| Day | Activity | Review |
|---|---|---|
| 1-7 | Intensive monitoring, rapid fix cycle | Daily standup |
| 8-14 | Stabilization, user feedback collection | Daily standup |
| 15-21 | Performance review, data quality audit | Weekly review |
| 22-30 | Exit criteria assessment, formal report | 30-day review |
| 31-60 | Extended monitoring (if needed) | 60-day review |
| 61-90 | Long-term stabilization (if needed) | 90-day review |

---

## 2. Production Health Monitoring

### 2.1 Key Metrics

| Metric | Target | Alert Threshold | Critical Threshold |
|---|---|---|---|
| Uptime | ≥ 99.9% | < 99.5% | < 99.0% |
| Error rate | < 0.1% | > 0.5% | > 1.0% |
| API latency (p95) | < 500ms | > 1s | > 2s |
| API latency (p99) | < 1s | > 2s | > 5s |
| Database connections | < 80% pool | > 85% | > 95% |
| Queue depth | < 50 jobs | > 100 | > 500 |
| Queue processing time | < 5s | > 10s | > 30s |
| Storage usage | < 70% | > 80% | > 90% |
| Auth failure rate | < 5/min | > 10/min | > 20/min |
| Realtime connections | Normal baseline | > 2x baseline | > 5x baseline |

### 2.2 Monitoring Schedule

| Period | Frequency | Owner |
|---|---|---|
| Day 1-3 | Every 15 min | Ops team |
| Day 4-7 | Every 30 min | Ops team |
| Day 8-14 | Every hour | Ops team |
| Day 15-30 | Every 4 hours | Ops team |
| Day 31+ | Daily | Ops team |

---

## 3. Incident Classification

| Severity | Definition | Response Time | Resolution Target |
|---|---|---|---|
| CRITICAL | Data loss, security breach, platform down, clinical integrity | < 15 min | < 4 hours |
| HIGH | Major feature broken, financial corruption, authorization failure | < 1 hour | < 8 hours |
| MEDIUM | Non-critical feature impacted, workaround exists | < 4 hours | < 24 hours |
| LOW | Minor issue, cosmetic, performance degradation | < 24 hours | < 72 hours |
| ENHANCEMENT | Requested improvement, not a defect | Backlog | Next release |

---

## 4. Critical Fix Priority

During hypercare, CRITICAL and HIGH issues take absolute priority:

| Priority | Category | Examples |
|---|---|---|
| 1 | Data Loss | Records disappearing, corrupted data |
| 2 | Security | Unauthorized access, data leakage |
| 3 | Clinical Integrity | Wrong patient data, incorrect results |
| 4 | Financial Corruption | Incorrect billing, payment errors |
| 5 | Authorization | RLS bypass, role escalation |
| 6 | Availability | Platform down, major feature broken |

---

## 5. User Feedback Process

### 5.1 Feedback Collection

| Channel | Frequency | Owner |
|---|---|---|
| Daily standup | Daily (Day 1-14) | Project Lead |
| Feedback form | Continuous | Support team |
| Support tickets | Continuous | Support team |
| Weekly survey | Weekly | Project Lead |
| Staff interviews | Bi-weekly | Clinical Lead |

### 5.2 Feedback Classification

| Category | Definition | Action |
|---|---|---|
| BUG | Something broken or incorrect | Fix in release cycle |
| UX ISSUE | Difficult or confusing workflow | Review in usability session |
| TRAINING ISSUE | User doesn't know how to use feature | Provide training |
| REQUESTED FEATURE | New capability desired | Backlog for future phase |

---

## 6. Data Quality Monitoring

### 6.1 Daily Checks

| # | Check | Expected | Action if Failed |
|---|---|---|---|
| 1 | Duplicate patient check | Zero duplicates | Merge records |
| 2 | Orphan encounter check | Zero orphans | Link or archive |
| 3 | Failed notification check | Zero unprocessed | Retry or escalate |
| 4 | Billing mismatch check | Zero mismatches | Investigate |
| 5 | Inventory consistency | Positive quantities | Adjust with audit |
| 6 | Unresolved patient IDs | Zero | Investigate |

### 6.2 Weekly Checks

| # | Check | Expected | Action if Failed |
|---|---|---|---|
| 1 | Audit trail completeness | All events logged | Investigate gaps |
| 2 | RLS policy effectiveness | Zero cross-tenant access | Emergency fix |
| 3 | Backup integrity | Restore successful | Fix backup process |
| 4 | Performance trends | Stable or improving | Investigate degradation |

---

## 7. Release Process During Hypercare

### 7.1 Fix Lifecycle

```
INCIDENT REPORTED
      ↓
CLASSIFY (severity)
      ↓
INVESTIGATE (root cause)
      ↓
FIX (branch + tests)
      ↓
REVIEW (peer review)
      ↓
CI (automated tests pass)
      ↓
DEPLOYMENT APPROVAL (tech lead)
      ↓
DEPLOY (with rollback ready)
      ↓
VERIFY (smoke test)
      ↓
CLOSE (incident resolved)
```

### 7.2 Deployment Rules

| Rule | Description |
|---|---|
| Branch required | No direct commits to main |
| Tests required | All existing + new tests must pass |
| Review required | At least one peer review |
| CI required | TypeScript, vitest, build all pass |
| Approval required | Tech lead signs off |
| Rollback ready | Previous version tagged and ready |
| Window | Deploy during low-traffic hours |

---

## 8. 30-Day Review Report Template

```markdown
# SWASTHYA HYPERCARE 30-DAY REVIEW

## Period: [Start Date] to [End Date]

## Production Health
- Uptime: [percentage]
- Error rate: [percentage]
- p95 latency: [ms]
- p99 latency: [ms]

## Incidents
- CRITICAL: [count] (resolved: [count])
- HIGH: [count] (resolved: [count])
- MEDIUM: [count] (resolved: [count])
- LOW: [count] (resolved: [count])
- ENHANCEMENT: [count] (backlogged: [count])

## Data Quality
- Duplicates found: [count]
- Orphan records: [count]
- Billing mismatches: [count]
- Inventory inconsistencies: [count]

## User Feedback
- BUG: [count] (resolved: [count])
- UX ISSUE: [count] (addressed: [count])
- TRAINING ISSUE: [count] (resolved: [count])
- FEATURE REQUEST: [count] (backlogged: [count])

## Performance vs Targets
| Metric | Target | Actual | Status |
|---|---|---|---|
| Uptime | ≥ 99.9% | [value] | [pass/fail] |
| Error rate | < 0.1% | [value] | [pass/fail] |
| p95 latency | < 500ms | [value] | [pass/fail] |
| Queue depth | < 50 | [value] | [pass/fail] |

## Exit Criteria Assessment
- [ ] No critical defects open
- [ ] No unresolved high-severity integrity/security defects
- [ ] Operational metrics stable for 7+ days
- [ ] Support process stable
- [ ] Hospital team confirms satisfaction

## Recommendation
- EXTEND hypercare (specify duration)
- EXIT hypercare
- ESCALATE (specify issues)
```

---

## 9. Exit Criteria

Hypercare ends ONLY when ALL of the following are true:

| # | Criterion | Evidence Required |
|---|---|---|
| 1 | No critical defects open | Incident tracker |
| 2 | No unresolved high-severity integrity/security defects | Security review |
| 3 | Operational metrics stable for 7+ days | Monitoring dashboard |
| 4 | Support process stable | Support ticket trends |
| 5 | Hospital team confirms satisfaction | Formal sign-off |
| 6 | Data quality verified | Data quality report |
| 7 | Backup/restore tested | Restore drill results |

---

## 10. Escalation Matrix

| Level | Trigger | Escalated To | Response |
|---|---|---|---|
| 1 | HIGH defect unresolved > 8 hours | Technical Lead | Immediate investigation |
| 2 | CRITICAL defect unresolved > 4 hours | Project Lead + Hospital IT | All-hands response |
| 3 | Multiple CRITICAL defects | Executive Sponsor | Emergency review |
| 4 | Data integrity issue | Hospital IT Director + Clinical Director | Immediate halt + investigation |
| 5 | Security breach | Security Officer + Hospital IT Director | Incident response protocol |

---

*This document must be reviewed and approved before hypercare activation.*
