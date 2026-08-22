# OPERATIONS_READINESS.md — SWASTHYA National Operations, SRE & Service Management

> **Status:** Operations framework ready — requires production deployment to activate
> **Release:** `3dc2a20` on `main`
> **Date:** August 22, 2026
> **Scope:** Service management for national-scale hospital operations

---

## 0. CRITICAL RULES

1. **Operational telemetry must never expose PHI** — patient names, clinical narrative, credentials, secrets.
2. **Alerts must be actionable** — every alert has an owner, response time, and resolution path.
3. **Every major incident gets a postmortem** — timeline, root cause, remediation.
4. **Production changes require approval** — reason, owner, testing, rollback.
5. **Capacity management is proactive** — monitor trends, not just thresholds.

---

## 1. Service Inventory

| Service | Description | Owner | SLA Target |
|---|---|---|---|
| Application (SPA) | React frontend, PWA | Frontend Lead | 99.9% |
| API (Laravel) | REST API, 614 routes | Backend Lead | 99.9% |
| Database (PostgreSQL) | Primary data store, RLS | DBA | 99.95% |
| Cache (Redis) | Sessions, rate limiting, queues | Ops | 99.9% |
| Queue Workers | Background job processing | Ops | 99.5% |
| Realtime (WebSocket) | Live updates, presence | Ops | 99.5% |
| Storage (S3/Local) | Documents, exports, images | Ops | 99.9% |
| FHIR Endpoints | Health system interop | Interop Lead | 99.5% |
| Notifications | Push, email, SMS | Comms Lead | 99.0% |
| Telemedicine | Video sessions | Telehealth Lead | 99.0% |

---

## 2. Service Level Objectives

| Metric | Target | Measurement | Alert Threshold |
|---|---|---|---|
| Availability | ≥ 99.9% | Uptime monitoring | < 99.5% |
| API Latency (p50) | < 200ms | APM tracing | > 500ms |
| API Latency (p95) | < 500ms | APM tracing | > 1s |
| API Latency (p99) | < 1s | APM tracing | > 2s |
| Error Rate | < 0.1% | Error monitoring | > 0.5% |
| Database Connections | < 80% pool | Connection monitoring | > 85% |
| Queue Depth | < 50 jobs | Queue monitoring | > 100 |
| Queue Processing Time | < 5s | Queue monitoring | > 10s |
| Storage Usage | < 70% | Disk monitoring | > 80% |
| Auth Failure Rate | < 5/min | Security monitoring | > 10/min |

### SLO Definitions

| SLO | Description | Error Budget |
|---|---|---|
| Availability | Platform accessible and serving requests | 43 min/month |
| Latency | API responses within target windows | 1% of requests |
| Freshness | Data available within expected time | 0.1% of queries |
| Durability | Data preserved without loss | 0% data loss |

---

## 3. Observability Stack

### 3.1 Metrics

| Category | Metrics | Source |
|---|---|---|
| RED (Services) | Rate, Errors, Duration per route | Application APM |
| USE (Resources) | Utilization, Saturation, Errors for DB/Cache/Queue | Infrastructure |
| Business | Registrations, bookings, encounters, charges, payments | Application |
| Security | Auth failures, rate limits, IDOR attempts | Security monitoring |

### 3.2 Logs

| Type | Format | Retention | PHI Safety |
|---|---|---|---|
| Application | Structured JSON | 30 days | ✅ No PHI |
| Access | Apache/Nginx combined | 90 days | ✅ No PHI |
| Audit | Append-only, immutable | 1 year | ✅ PHI-safe |
| Security | Security events | 90 days | ✅ No PHI |
| Error | Stack traces, context | 30 days | ✅ No PHI |

### 3.3 Traces

| Span | Description | Sampling |
|---|---|---|
| API Request | Full request lifecycle | 100% for errors |
| Database Query | Slow query detection | 10% for normal |
| Queue Job | Background processing | 100% for failures |
| Integration | External system calls | 100% |

### 3.4 Dashboards

| Dashboard | Audience | Refresh |
|---|---|---|
| Service Health | SRE, Operations | Real-time |
| Application Performance | Development | 1 min |
| Database Performance | DBA | 1 min |
| Security Overview | Security Team | 5 min |
| Business Metrics | Leadership | 1 hour |
| Facility Operations | Hospital Admin | 5 min |

---

## 4. PHI Safety in Operations

### 4.1 Never Log

| Data Type | Status |
|---|---|
| Patient names | ❌ Never |
| Clinical narrative | ❌ Never |
| Diagnosis details | ❌ Never |
| Test results | ❌ Never |
| Medication details | ❌ Never |
| Financial amounts | ❌ Never |
| Credentials/tokens | ❌ Never |
| Passwords | ❌ Never |

### 4.2 Safe to Log

| Data Type | Status |
|---|---|
| Request ID | ✅ Always |
| User ID (not patient) | ✅ When needed |
| Tenant ID (bucket) | ✅ When needed |
| Facility ID | ✅ When needed |
| Action type | ✅ Always |
| Timestamp | ✅ Always |
| Duration | ✅ Always |
| Status code | ✅ Always |
| Error class | ✅ When needed |

---

## 5. Alerting Matrix

| Alert | Severity | Condition | Response Time | Owner |
|---|---|---|---|---|
| Platform Down | P1 | Health check fails | < 5 min | SRE |
| API Error Rate > 1% | P1 | Error monitoring | < 15 min | Backend Lead |
| Database Down | P1 | Connection pool exhausted | < 5 min | DBA |
| Queue Backlog > 500 | P2 | Queue monitoring | < 30 min | Ops |
| Latency p95 > 2s | P2 | APM | < 30 min | Backend Lead |
| Storage > 90% | P2 | Disk monitoring | < 1 hour | Ops |
| Auth Failures > 20/min | P2 | Security monitoring | < 15 min | Security |
| Integration Down | P3 | Health check | < 1 hour | Interop Lead |
| Certificate Expiry < 7d | P3 | Certificate monitoring | < 24 hours | Ops |
| Backup Failed | P2 | Backup monitoring | < 1 hour | DBA |

---

## 6. On-Call Structure

| Role | Coverage | Escalation |
|---|---|---|
| Primary SRE | 24/7 | Immediate |
| Secondary SRE | 24/7 | After primary |
| Backend Lead | Business hours | After SRE |
| DBA | Business hours | After SRE |
| Security | Business hours | After SRE |
| Executive | Escalation only | After all |

### Escalation Timeline

| Time | Action |
|---|---|
| T+0 | Alert fires, primary SRE notified |
| T+5 min | Primary SRE acknowledges |
| T+15 min | If unresolved, secondary SRE engaged |
| T+30 min | If unresolved, team lead engaged |
| T+1 hour | If unresolved, executive escalation |
| T+4 hours | If critical, hospital IT notified |

---

## 7. Incident Management

### 7.1 Incident Severity

| Severity | Definition | Response | Resolution Target |
|---|---|---|---|
| P1 | Platform down, data loss, security breach | All hands | < 4 hours |
| P2 | Major feature broken, financial corruption | Team lead | < 8 hours |
| P3 | Non-critical feature impacted | Assigned engineer | < 24 hours |
| P4 | Minor issue, cosmetic | Normal queue | < 72 hours |

### 7.2 Postmortem Template

```markdown
# Incident Postmortem

## Summary
- **Date:** [YYYY-MM-DD]
- **Duration:** [hours]
- **Severity:** [P1/P2/P3/P4]
- **Impact:** [description]

## Timeline
- [HH:MM] Alert fired
- [HH:MM] SRE acknowledged
- [HH:MM] Root cause identified
- [HH:MM] Fix deployed
- [HH:MM] Verified resolved

## Root Cause
[Technical description]

## Remediation
- [ ] Fix applied
- [ ] Monitoring added
- [ ] Documentation updated
- [ ] Preventive measure implemented

## Action Items
| # | Action | Owner | Due |
|---|---|---|---|
| 1 | [action] | [name] | [date] |
```

---

## 8. Change Management

### 8.1 Change Process

```
CHANGE REQUEST
      ↓
REASON DOCUMENTED
      ↓
OWNER ASSIGNED
      ↓
TESTING COMPLETE
      ↓
ROLLBACK PLAN
      ↓
APPROVAL (Tech Lead)
      ↓
DEPLOY (Low-traffic window)
      ↓
VERIFY (Smoke test)
      ↓
MONITOR (30 min)
      ↓
CLOSE
```

### 8.2 Change Categories

| Category | Approval | Window |
|---|---|---|
| Critical fix | Tech Lead | Immediate |
| Bug fix | Peer review | Scheduled |
| Feature | Tech Lead + Product | Scheduled |
| Configuration | Tech Lead | Scheduled |
| Database migration | DBA + Tech Lead | Maintenance window |
| Infrastructure | SRE + Tech Lead | Maintenance window |

---

## 9. Release Management

### 9.1 Release Pipeline

```
DEVELOPMENT
    ↓
STAGING (automated tests pass)
    ↓
VALIDATION (manual QA)
    ↓
PRODUCTION APPROVAL (tech lead)
    ↓
DEPLOY (with rollback ready)
    ↓
VERIFY (smoke test)
    ↓
MONITOR (30 min)
    ↓
RELEASE COMPLETE
```

### 9.2 Rollback Criteria

| Trigger | Action |
|---|---|
| Health check fails | Immediate rollback |
| Error rate > 1% | Rollback within 15 min |
| Data integrity issue | Immediate rollback + investigation |
| Security vulnerability | Immediate rollback + investigation |
| Hospital IT request | Immediate rollback |

---

## 10. Capacity Management

| Resource | Monitoring | Scaling Trigger |
|---|---|---|
| API Instances | CPU, memory, connections | > 80% utilization |
| Database | Connections, query time, disk | > 80% pool, > 80% disk |
| Redis | Memory, connections | > 80% memory |
| Queue Workers | Job count, processing time | > 100 jobs, > 10s processing |
| Storage | Disk usage | > 80% usage |
| CDN | Bandwidth, cache hit rate | < 90% hit rate |

---

## 11. Service Health Dashboard

The National Operations Center displays:

| Section | Metrics |
|---|---|
| Overall Health | Uptime, status, last incident |
| API Performance | Latency (p50/p95/p99), error rate, throughput |
| Database | Connections, query time, slow queries |
| Queue | Depth, processing time, failed jobs |
| Storage | Usage, growth rate, backup status |
| Integrations | Status of each external system |
| Security | Auth failures, rate limits, anomalies |
| Facilities | Per-facility status, users, activity |
| Incidents | Open incidents, recent postmortems |
| Alerts | Active alerts, acknowledged, resolved |

---

## 12. Cost Management

| Category | Tracking | Review |
|---|---|---|
| Compute | Server instances, CPU hours | Monthly |
| Database | Storage, IOPS, connections | Monthly |
| Storage | Object storage, egress | Monthly |
| CDN | Bandwidth, requests | Monthly |
| Queue | Job processing, worker hours | Monthly |
| Third-party | SMS, email, payment fees | Monthly |
| Support | On-call hours, incident response | Monthly |

---

*This document defines the operational framework for SWASTHYA as a national-scale service.*
