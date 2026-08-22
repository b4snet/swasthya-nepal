# SWASTHYA CONTINUOUS PRODUCTION IMPROVEMENT, CLINICAL CHANGE CONTROL AND PRODUCT EVOLUTION

## Document Identity

| Field | Value |
|---|---|
| Phase | 134 |
| Commit | 0dd0a6c |
| Branch | main |
| Date | August 23, 2026 |
| Status | Active Governance Framework |

---

## CORE RULE

No new feature is added merely because another HMS has it, a competitor has it, a user requested it casually, an AI suggested it, it looks impressive, or it makes the feature list longer.

Every new capability must have:

1. A documented problem
2. An identified user
3. A measurable benefit
4. A security analysis
5. A clinical-safety analysis where relevant
6. An architectural impact assessment
7. An operational impact assessment
8. A testing strategy
9. A rollback strategy

---

## STEP 0 — PRODUCTION BASELINE (Verified)

| Metric | Value |
|---|---|
| Release Commit | `0dd0a6c` |
| Branch | `main` |
| Total Commits | 175 |
| Frontend Pages | 82 |
| Backend Controllers | 85 |
| Data Models | 205 |
| Database Migrations | 147 |
| API Routes | 624 |
| Frontend Routes | 99 |
| RLS Policies | 508 |
| Automated Tests | 78/78 passing |
| TypeScript Errors | 0 |
| Build Status | Successful |
| npm Vulnerabilities | 2 moderate (0 critical, 0 high) |
| Security Findings (Phase 122) | 0 critical, 0 high |
| Load Tested | 1M patients (2.9M rows) |
| DR Restore Time | 144 seconds |
| Active Hospitals | 0 (pre-deployment) |
| Active Users | 0 (pre-deployment) |
| Outstanding Defects | 0 critical, 0 high |
| Support Tickets | 0 (pre-deployment) |

### Incident Status

No production incidents exist (pre-deployment). The platform is CONDITIONALLY READY for first hospital deployment.

---

## STEP 1 — PRODUCTION EVIDENCE COLLECTION

Since the platform is pre-deployment, the following targets are established for post-deployment monitoring:

### SLO Targets

| Metric | Target | Alert | Critical |
|---|---|---|---|
| Availability | ≥ 99.9% | < 99.5% | < 99.0% |
| API Latency (p50) | < 200ms | > 500ms | > 1s |
| API Latency (p95) | < 500ms | > 1s | > 2s |
| API Latency (p99) | < 1s | > 2s | > 5s |
| Error Rate | < 0.1% | > 0.5% | > 1.0% |
| DB Connections | < 80% pool | > 85% | > 95% |
| Queue Depth | < 50 jobs | > 100 | > 500 |
| Queue Processing | < 5s | > 10s | > 30s |
| Storage Usage | < 70% | > 80% | > 90% |
| Auth Failures | < 5/min | > 10/min | > 20/min |

### Evidence Collection Schedule

| Frequency | Metrics |
|---|---|
| Real-time | Error rate, auth failures |
| Every 15 min (Week 1) | API latency, DB connections, queue depth |
| Every hour (Week 2+) | Storage, backup success |
| Daily | User activity, module adoption |
| Weekly | Performance trends, security scan |
| Monthly | Full SLO review |

---

## STEP 2 — CLINICAL WORKFLOW FEEDBACK

### Feedback Classification System

Every piece of feedback is classified into exactly one category:

| Category | Description | Priority |
|---|---|---|
| BUG | Software defect producing incorrect behavior | System |
| UX PROBLEM | Correct behavior, poor user experience | Design |
| TRAINING PROBLEM | User does not know how to use existing feature | Education |
| WORKFLOW PROBLEM | Feature exists but does not match actual clinical workflow | Product |
| FEATURE REQUEST | New capability not currently in the system | Product |
| INTEGRATION PROBLEM | External system integration failure or gap | Technical |
| PERFORMANCE PROBLEM | Response time, throughput, or resource issue | Technical |

### Feedback Collection Channels

| Channel | Audience | Frequency |
|---|---|---|
| Daily standup (hypercare) | Hospital superusers | Daily (first 30 days) |
| Weekly survey | All active users | Weekly |
| Monthly review | Hospital leadership | Monthly |
| Quarterly strategic review | All stakeholders | Quarterly |
| Support ticket analysis | All users | Continuous |
| Direct observation | Selected workflows | Bi-weekly |

### Feedback Processing Rules

1. **BUG** — Triage within 24 hours. Critical bugs block other work.
2. **UX PROBLEM** — Catalogue and batch for sprint planning.
3. **TRAINING PROBLEM** — Address through documentation/training, not code changes.
4. **WORKFLOW PROBLEM** — Deep-dive with clinical users. May require product change.
5. **FEATURE REQUEST** — Enter Feature Request Governance (Step 4).
6. **INTEGRATION PROBLEM** — Assess against Integration Certification matrix (Phase 129).
7. **PERFORMANCE PROBLEM** — Enter Continuous Performance Loop (Step 23).

---

## STEP 3 — DEFECT TRIAGE

### Severity Levels

| Severity | Definition | Response Time | Fix Target |
|---|---|---|---|
| CRITICAL | Data loss, security breach, patient safety risk, system down | 1 hour | 24 hours |
| HIGH | Major workflow broken, financial error, authorization bypass | 4 hours | 72 hours |
| MEDIUM | Workflow degraded, workaround available, non-critical integration | 24 hours | 1 week |
| LOW | Minor inconvenience, cosmetic with clinical impact | 72 hours | 1 month |
| COSMETIC | Visual polish, minor UI improvement | Next sprint | Next release |
| ENHANCEMENT | Requested improvement beyond current design | Backlog | Roadmap |

### Priority Order

```
SECURITY
  > PATIENT SAFETY
    > DATA INTEGRITY
      > FINANCIAL INTEGRITY
        > AVAILABILITY
          > PERFORMANCE
            > WORKFLOW
              > UX
                > COSMETIC
```

### Triage Rules

- Critical/High defects block unrelated feature work.
- Every defect gets a unique tracking ID.
- Every defect resolution gets a verification test.
- Post-mortem required for any Critical defect.
- Monthly defect trend analysis.

---

## STEP 4 — FEATURE REQUEST GOVERNANCE

### Feature Request Template

For every proposed feature:

```
PROBLEM:       What problem does this solve?
USER:          Who benefits and how frequently?
CURRENT:       What is the current workaround?
SOLUTION:      What is the proposed change?
CLINICAL:      Does this affect clinical safety? How?
SECURITY:      Does this affect RLS, auth, or data access?
DATA:          Does this require schema changes?
INTEGRATION:   Does this affect external systems?
PERFORMANCE:   What is the expected load impact?
COST:          What is the implementation effort?
TEST:          What testing is required?
ROLLBACK:      How do we revert if this fails?
```

### Approval Gates

| Risk Level | Review Required |
|---|---|
| Low (cosmetic, UX) | Product lead |
| Medium (workflow, new module) | Product + Engineering |
| High (clinical, financial) | Product + Engineering + Clinical + Security |
| Critical (patient safety, security) | Full Change Review Board |

### Rejected Feature Criteria

Features are REJECTED when:
- No documented problem exists
- The user base is too small to justify the cost
- The security risk outweighs the benefit
- The clinical safety analysis identifies unacceptable risk
- The architectural impact is disproportionate to the value
- A simpler workaround exists
- The feature duplicates existing functionality

---

## STEP 5 — COMPETITOR GAP ANALYSIS

### Comparison Framework

| Capability | Epic | Oracle Health | SWASTHYA | Gap Assessment |
|---|---|---|---|---|
| Patient Records | ✅ | ✅ | ✅ | Parity |
| EMR/EHR | ✅ | ✅ | ✅ | Parity |
| Scheduling | ✅ | ✅ | ✅ | Parity |
| Emergency | ✅ | ✅ | ✅ | Parity |
| IPD/ICU/OT | ✅ | ✅ | ✅ | Parity |
| Lab/LIS | ✅ | ✅ | ✅ | Parity |
| Radiology/RIS | ✅ | ✅ | ✅ | Parity |
| PACS | ✅ | ✅ | ⬜ Partial | External dependency |
| Pharmacy | ✅ | ✅ | ✅ | Parity |
| Blood Bank | ✅ | ✅ | ✅ | Parity |
| Oncology | ✅ | ✅ | ✅ | Parity |
| Billing | ✅ | ✅ | ✅ | Parity |
| Procurement | ✅ | ✅ | ✅ | Parity |
| HR/Workforce | ✅ | ✅ | ✅ | Parity |
| Patient Portal | ✅ | ✅ | ✅ | Parity |
| Telemedicine | ✅ | ✅ | ✅ | Parity |
| FHIR | ✅ | ✅ | ✅ | Parity |
| HL7 | ✅ | ✅ | ⬜ Partial | Adapter only |
| DICOM | ✅ | ✅ | ⬜ Partial | External dependency |
| Analytics | ✅ | ✅ | ✅ | Parity |
| AI Assistance | ⬜ | ⬜ | ✅ | SWASTHYA advantage |
| Mobile/Offline | ⬜ | ⬜ | ✅ | SWASTHYA advantage |
| Quality/Safety | ✅ | ✅ | ✅ | Parity |
| Research | ✅ | ⬜ | ✅ | SWASTHYA advantage |
| Multi-facility | ✅ | ✅ | ✅ | Parity |
| Multi-tenant | ✅ | ✅ | ✅ | Parity |

### Gap Classification

| Gap | Classification | Action |
|---|---|---|
| PACS/DICOM integration | EXTERNAL DEPENDENCY | Requires vendor partnership |
| HL7 V2 adapter | PARTIAL | Complete when partner available |
| Clinical documentation depth | HIGH VALUE | Evidence-driven evolution |
| Voice/dictation | OPTIONAL | Requires ASR vendor |
| Bed management IoT | OPTIONAL | Requires device partnership |
| Patient wearable integration | NOT RELEVANT YET | After deployment evidence |

---

## STEP 6 — FEATURE MATRIX MAINTENANCE

### Living Feature Matrix

Every feature is tracked with:

| Field | Description |
|---|---|
| FEATURE | Feature name |
| STATUS | IMPLEMENTED / PARTIAL / DESIGNED ONLY / EXTERNAL DEPENDENCY / DEPRECATED / NOT IMPLEMENTED |
| USER | Primary user role |
| EVIDENCE | Test, UAT, or production evidence |
| SECURITY | Security review status |
| CLINICAL RISK | None / Low / Medium / High |
| DEPENDENCY | External dependencies |
| VERSION | Version introduced |
| LAST VALIDATED | Date of last validation |

### Matrix Update Rules

- Updated at every release.
- Updated when production evidence changes.
- Updated when security findings affect a feature.
- Updated when clinical safety review affects a feature.
- Never inflated for marketing purposes.

---

## STEP 7 — CLINICAL CHANGE CONTROL

### Affected Domains

Any change affecting these domains requires clinical-safety review:

- Medication (prescribing, dispensing, administration)
- Diagnosis (ICD coding, clinical notes)
- Triage (acuity, prioritization)
- Laboratory (orders, results, critical values)
- Radiology (orders, studies, reports, critical findings)
- Blood Bank (crossmatch, issue, transfusion)
- ICU (monitoring, scoring, alerts)
- Operating Theatre (procedure, checklist, recovery)
- Oncology (protocols, cycles, toxicity)
- Discharge (summary, medications, follow-up)
- Patient Identity (MRN, demographics, matching)
- Billing (charges, payments, insurance)

### Clinical Safety Review Template

```
DOMAIN:           Which clinical domain?
CHANGE:           What is changing?
INTENDED:         What should happen?
FAILURE MODES:    How can this fail?
PATIENT IMPACT:   What happens to patients if this fails?
HUMAN OVERRIDE:   Can a clinician override or correct?
AUDIT:            What audit trail is required?
TESTING:          What clinical scenarios must be tested?
ROLLBACK:         How do we revert without patient harm?
CLINICAL SIGNER:  Who from the clinical team approved?
```

### Clinical Safety Rules

1. No medication logic changes without pharmacist review.
2. No laboratory result handling changes without lab director review.
3. No blood bank logic changes without transfusion medicine review.
4. No ICU scoring changes without critical care review.
5. No discharge logic changes without attending physician review.
6. Every clinical change gets a human override mechanism.
7. Every clinical change gets an audit trail.

---

## STEP 8 — SECURITY CHANGE CONTROL

### Affected Areas

Any change affecting these areas requires security review:

- RLS policies
- Permissions / RBAC
- Authentication / MFA
- Storage access
- Realtime subscriptions
- API endpoints
- External integrations
- Patient Portal
- Financial data access
- Staff data access
- PHI handling

### Security Review Checklist

```
RLS:          Does this introduce new tables/policies?
PERMISSIONS:  Does this require new roles or permissions?
AUTH:         Does this change authentication flow?
STORAGE:      Does this access or modify storage?
API:          Does this expose new endpoints?
INTEGRATION:  Does this connect to external systems?
PHI:          Does this handle patient health information?
AUDIT:        Is this change auditable?
TENANT:       Is tenant isolation maintained?
FACILITY:     Is facility isolation maintained?
```

### Security Rules

1. No new feature may weaken tenant isolation.
2. No new feature may weaken facility isolation.
3. No new feature may weaken least privilege.
4. No new feature may reduce auditability.
5. No new feature may compromise secret handling.
6. RLS changes require DBA + Security review.
7. Auth changes require Security lead approval.

---

## STEP 9 — DATABASE CHANGE CONTROL

### Migration Evaluation

Before any migration:

| Check | Question |
|---|---|
| Backwards Compatibility | Can old code run against new schema? |
| Data Migration | Does existing data need transformation? |
| Rollback | Can this migration be safely reversed? |
| Index Impact | Will this migration lock tables or degrade writes? |
| RLS Impact | Does this affect row-level security policies? |
| Production Data | Will this work with real production data volumes? |
| Reporting | Will this break existing reports or exports? |
| Foreign Keys | Are all referenced tables/columns valid? |

### Migration Rules

1. Every migration has a down() method.
2. No migration modifies data without explicit rollback.
3. Large table migrations use chunked processing.
4. Index creation uses CONCURRENTLY where supported.
5. Column additions use nullable or default values first.
6. Column drops follow a deprecation period.
7. Every migration is tested against a production-representative dataset.

---

## STEP 10 — API EVOLUTION

### Compatibility Rules

| Action | Timeline | Communication |
|---|---|---|
| ADD new endpoint | Immediate | Documented in release notes |
| MIGRATE consumers | 2 releases | Deprecation notice on old endpoint |
| DEPRECATE endpoint | 2 releases after migration | Warning headers + docs |
| REMOVE endpoint | 4 releases after deprecation | Breaking change notice |

### API Review Checklist

- Current consumers identified?
- Frontend dependencies mapped?
- Mobile dependencies mapped?
- External integrations checked?
- Partner integrations verified?
- Backwards compatible?
- Documentation updated?
- Versioned correctly?

---

## STEP 11 — FRONTEND EVOLUTION

### Design System Rules

Every new module/page must use:

| Element | Standard |
|---|---|
| Visual language | White/light background |
| Primary accent | #4FA9FF (SWASTHYA blue) |
| Icons | Professional SVG (lucide-react) |
| Typography | System font stack |
| Spacing | 8px grid |
| Cards | White bg, subtle border, 12px radius |
| Tables | Clean headers, alternating rows |
| Forms | Consistent input styling |
| Buttons | Primary (blue), secondary (gray), danger (red) |
| Status | Semantic colors only |
| Responsive | Desktop-first, 3 breakpoints |
| Accessibility | Semantic HTML, aria labels |

### Design System Violations

Do NOT allow:
- Random AI-generated dark themes
- Inconsistent color palettes
- Emoji as icons
- Decorative elements without purpose
- Animations that interfere with clinical workflow
- Pop-ups that block clinical data entry
- Font sizes below 12px for clinical data

---

## STEP 12 — ROLE-AWARE PRODUCT EVOLUTION

### Authorization Matrix

For every new feature define:

| Action | Question |
|---|---|
| SEE | Who can see this in the UI? |
| CREATE | Who can create new records? |
| UPDATE | Who can modify existing records? |
| APPROVE | Who can approve/sign-off? |
| DELETE/VOID | Who can remove or void records? |
| EXPORT | Who can export this data? |
| AUDIT | Who can view the audit trail? |

### Authorization Rules

1. UI may hide unavailable functionality.
2. Backend authorization is always authoritative.
3. Every API endpoint enforces role + scope.
4. Every mutation is audit-logged.
5. No role should see the entire application.

---

## STEP 13 — MODULE ENTITLEMENT

### Module Entitlement Rules

Every new module integrates with hospital onboarding:

| Config Element | Scope |
|---|---|
| Enabled modules | Hospital-level |
| Department modules | Facility-level |
| Role capabilities | Role-level |
| Workflow templates | Module-level |
| Navigation items | Role + Module |
| API access | Role + Permission |

### Entitlement Enforcement

1. Disabled modules are hidden from navigation.
2. Disabled modules have no API access.
3. Module entitlement is checked at the API gateway.
4. Hospital configuration determines module visibility.
5. Module entitlement is never hard-coded in the frontend.

---

## STEP 14 — MULTI-TENANT SAFETY

### Required Proofs for Every New Feature

| Proof | Test |
|---|---|
| Tenant isolation | Hospital A cannot see Hospital B data |
| Facility isolation | Facility A cannot see Facility B data |
| Department isolation | Department A sees only own data (where applicable) |
| Role isolation | Nurse cannot access admin functions |
| Patient scope | Provider sees only assigned patients (where applicable) |
| Forged ID test | API rejects identifiers from wrong tenant |
| Direct URL test | URL manipulation cannot bypass scope |
| API manipulation test | Forged headers cannot escalate access |

### Test Execution

- Pre-release: Automated RLS regression suite.
- Post-release: Spot-check with synthetic cross-tenant data.
- Quarterly: Full isolation audit.

---

## STEP 15 — OBSERVABILITY FOR NEW FEATURES

### New Feature Observability Requirements

Every important new workflow must expose:

| Metric | Description |
|---|---|
| Success rate | % of attempts that complete successfully |
| Failure rate | % of attempts that fail |
| Latency | Time to complete (p50, p95, p99) |
| Queue state | Pending, processing, failed jobs |
| Integration status | External system health |
| Error classification | Categorized error types |

### PHI Safety Rules

| NEVER LOG | SAFE TO LOG |
|---|---|
| Patient names | Patient ID (hashed/anonymized) |
| Clinical narrative | Record type + action |
| Passwords/tokens | Auth method + result |
| Payment secrets | Payment status + amount |
| PHI content | Event type + timestamp |
| Staff credentials | Role + action |

---

## STEP 16 — TEST-FIRST ENGINEERING

### Feature Development Lifecycle

```
1. Capture contract (what should this do?)
2. Write/identify tests (what proves it works?)
3. Implement (write the code)
4. Run focused tests (does the feature work?)
5. Run RLS/RBAC (is authorization correct?)
6. Run browser E2E (does the UI work?)
7. Run regression (did anything break?)
8. Security review (is this secure?)
9. Performance review (is this fast enough?)
10. Documentation (is this documented?)
11. Checkpoint (commit with evidence)
```

### Test Requirements

| Feature Risk | Minimum Tests |
|---|---|
| Low (cosmetic) | TypeScript compilation |
| Medium (workflow) | Unit + component |
| High (clinical) | Unit + component + integration + E2E |
| Critical (patient safety) | Full suite + security + performance |

---

## STEP 17 — PRODUCTION-SAFE FEATURE FLAGS

### Flag States

| State | Description |
|---|---|
| disabled | Feature not available to anyone |
| internal | Available to internal staff only |
| pilot | Available to designated pilot hospital |
| selected | Available to specific hospitals/facilities |
| enabled | Available to all authorized users |

### Flag Rules

1. Feature flags are server-side, not client-side.
2. Feature flags cannot bypass authorization.
3. Feature flags have an expiration date.
4. Feature flags are tracked in the release notes.
5. Feature flags are cleaned up after full rollout.

---

## STEP 18 — PILOT NEW FEATURES

### Pilot Progression

```
INTERNAL (engineering team)
  → STAGING (test environment)
    → DESIGNATED HOSPITAL (pilot site)
      → LIMITED USERS (selected clinicians)
        → FULL FACILITY (all authorized users)
          → MULTI-FACILITY (all hospitals)
```

### Pilot Gates

| Gate | Criteria |
|---|---|
| Internal | Tests pass, no security issues |
| Staging | E2E passes, no performance regression |
| Pilot hospital | Clinical review passed, training complete |
| Limited users | No critical defects after 1 week |
| Full facility | No high defects after 2 weeks |
| Multi-facility | No medium defects after 4 weeks |

---

## STEP 19 — ROLLBACK

### Rollback Requirements

Every significant release must define:

| Element | Description |
|---|---|
| Rollback trigger | What condition triggers rollback |
| Rollback command | Exact steps to execute rollback |
| DB rollback | Schema/data recovery strategy |
| Feature-flag fallback | Can the feature be disabled without full rollback |
| Communication plan | Who is notified and how |

### Rollback Rules

1. Rollback can be executed by any on-call engineer.
2. Rollback completes within the documented RTO.
3. Data integrity is verified after rollback.
4. A post-rollback incident is created automatically.
5. Root cause is identified before re-deployment.

---

## STEP 20 — DEPRECATION

### Deprecation Process

```
1. DOCUMENT      — Mark feature as deprecated in docs
2. NOTIFY        — Alert affected users (minimum 1 release notice)
3. MIGRATE       — Provide data migration path if needed
4. REPLACE       — Ensure replacement feature is available
5. MONITOR       — Track usage to confirm zero consumers
6. REMOVE        — Delete code, migrations, routes, UI
```

### Deprecation Rules

1. No security-sensitive feature is deprecated without migration.
2. Deprecated features remain functional for minimum 2 releases.
3. Deprecated features are marked in the UI.
4. Deprecation is tracked in release notes.
5. Obsolete security-sensitive code is removed, not abandoned.

---

## STEP 21 — CONTINUOUS SECURITY LOOP

### Security Cycle

```
SCAN → ASSESS → FIX → TEST → DEPLOY → MONITOR → RETEST
```

### Scan Targets

| Target | Tool | Frequency |
|---|---|---|
| Dependencies (npm) | npm audit | Weekly (CI) |
| Dependencies (composer) | composer audit | Weekly (CI) |
| RLS policies | Custom test suite | Every release |
| Authentication | Manual + automated | Monthly |
| API security | OWASP checklist | Monthly |
| Storage access | Bucket policy review | Monthly |
| Realtime access | Subscription review | Monthly |
| Penetration test | External firm | Annually |
| Incident review | Post-incident | As needed |

---

## STEP 22 — CONTINUOUS DR LOOP

### DR Cycle

```
BACKUP → RESTORE → VERIFY → RECORD RTO/RPO
```

### DR Exercise Types

| Exercise | Frequency | Scope |
|---|---|---|
| Database backup/restore | Quarterly | Full database |
| Application rollback | Quarterly | Previous version |
| Queue worker failure | Semi-annually | Worker restart |
| Integration outage | Semi-annually | External provider |
| Full infrastructure | Annually | Complete system |

---

## STEP 23 — CONTINUOUS PERFORMANCE LOOP

### Performance Cycle

```
IDENTIFY → PROFILE → FIX → LOAD TEST → DEPLOY → VERIFY
```

### Performance Monitoring

| Metric | Collection Method | Alert Threshold |
|---|---|---|
| API latency (p50/p95/p99) | Application metrics | > 500ms p95 |
| Database query time | pg_stat_statements | > 1s slow queries |
| Queue processing time | Queue metrics | > 10s |
| Memory usage | System metrics | > 80% |
| CPU usage | System metrics | > 80% |
| Disk I/O | System metrics | > 80% |
| Connection pool | Application metrics | > 80% |

### Performance Rules

1. Optimize based on measured data, not assumptions.
2. Load test before and after optimization.
3. Profile before fixing.
4. Document performance improvements with evidence.
5. Never sacrifice correctness for speed.

---

## STEP 24 — CONTINUOUS UX LOOP

### UX Review Metrics

| Metric | Measurement |
|---|---|
| Clicks to complete | Count per workflow |
| Time to complete | Stopwatch/screen recording |
| Error frequency | Error rate per workflow |
| Abandonment | Start-but-not-complete rate |
| Repeated navigation | Back-and-forth patterns |
| User confusion | Support tickets + observation |

### UX Rules

1. Optimize for workflow efficiency, not visual novelty.
2. High-frequency workflows get priority.
3. Clinical data entry speed matters more than animation.
4. Role-specific navigation reduces cognitive load.
5. Empty states and error states must be informative.

---

## STEP 25 — HOSPITAL SUCCESS REVIEW

### Hospital Health Score

| Dimension | Weight | Measurement |
|---|---|---|
| Adoption | 25% | Active users / total users |
| Workflow Coverage | 20% | Modules actively used / enabled |
| Support Issues | 20% | Open tickets, severity trend |
| Training | 15% | Training completion rate |
| Performance | 10% | SLO compliance |
| Security | 10% | Findings, compliance |

### Health Score Bands

| Score | Status | Action |
|---|---|---|
| 90-100 | Excellent | Continue, share best practices |
| 75-89 | Good | Address specific gaps |
| 60-74 | Needs attention | Weekly review, targeted support |
| Below 60 | At risk | Daily review, escalation |

---

## STEP 26 — NATIONAL GOVERNANCE

### National-Level Matrices

| Matrix | Updated | Owner |
|---|---|---|
| Feature Matrix | Every release | Product |
| Incident Matrix | Weekly | Operations |
| Security Matrix | Monthly | Security |
| Integration Matrix | Monthly | Interop |
| Performance Matrix | Monthly | SRE |
| Hospital Rollout Matrix | Per deployment | Customer Success |
| Risk Register | Monthly | Governance Board |

---

## STEP 27 — RELEASE CADENCE

### Release Pipeline

```
PLANNING
  → DEVELOPMENT
    → TESTING
      → SECURITY
        → STAGING
          → UAT
            → APPROVAL
              → PRODUCTION
                → MONITORING
```

### Release Types

| Type | Cadence | Review |
|---|---|---|
| Patch (bug fix) | As needed | Engineering + QA |
| Minor (feature) | Bi-weekly | Full pipeline |
| Major (breaking) | Quarterly | Change Review Board |
| Emergency (security) | Immediate | Security + Engineering |

### Release Rules

1. No direct uncontrolled production changes.
2. Every release has a rollback plan.
3. Every release is tagged in Git.
4. Every release has release notes.
5. Every release is monitored for 48 hours.

---

## STEP 28 — CHANGE REVIEW BOARD

### CRB Composition

| Role | Responsibility |
|---|---|
| Engineering Lead | Technical feasibility, architecture |
| Security Lead | Security impact, RLS, auth |
| Product Lead | User value, priority, scope |
| Clinical Representative | Patient safety, workflow impact |
| Operations Lead | Deployment, support, monitoring |

### CRB Triggers

| Trigger | Review Required |
|---|---|
| New module | Full CRB |
| Clinical workflow change | Clinical + Security |
| Security-sensitive change | Security + Engineering |
| Database schema change | Engineering + DBA |
| API breaking change | Engineering + Product |
| Integration change | Engineering + Security |
| Cosmetic change | Product only |

### CRB Risk-Appropriate Review

Not every change requires full CRB:

- **Low risk**: Product approval only
- **Medium risk**: Product + Engineering
- **High risk**: Full CRB
- **Critical risk**: Full CRB + external review

---

## STEP 29 — PRODUCT ROADMAP

### Roadmap Categories

| Category | Definition |
|---|---|
| NOW | Approved, starting next sprint |
| NEXT | Approved, in planning |
| LATER | Identified, not yet approved |
| DECLINED | Evaluated and rejected |

### Roadmap Rules

1. Only approved work enters NOW/NEXT.
2. Every NOW item has a contract, tests, and rollback plan.
3. Every NEXT item has a cost-benefit analysis.
4. Every DECLINED item has a documented reason.
5. Roadmap is reviewed monthly.
6. Roadmap does not auto-expand.

---

## STEP 30 — NEW FEATURE PHASE TEMPLATE

When a substantial feature is approved, create a new controlled phase:

```
1.  BASELINE        — Verify current state
2.  CONTRACT        — Define what the feature must do
3.  ARCHITECTURE    — Design the implementation
4.  SECURITY        — Security analysis and requirements
5.  RLS             — Row-level security requirements
6.  IMPLEMENTATION  — Build the feature
7.  TESTS           — Unit, component, integration tests
8.  E2E             — End-to-end browser tests
9.  PERFORMANCE     — Load testing if applicable
10. DOCUMENTATION   — User docs, API docs, release notes
11. CHECKPOINT      — Git commit with evidence
12. FINAL REPORT    — Phase completion report
```

### Phase Rules

1. Never skip the contract (Step 2).
2. Never skip security review (Step 4).
3. Never skip tests (Step 7).
4. Never skip documentation (Step 10).
5. Every phase has a rollback plan.
6. Every phase is committed with evidence.

---

## STEP 31 — CONTINUOUS PRODUCT GOVERNANCE REPORT

### Current Production Status

| Metric | Value |
|---|---|
| Release Commit | `0dd0a6c` |
| Active Hospitals | 0 (pre-deployment) |
| Active Facilities | 0 (pre-deployment) |
| Active Modules | 28 (available, not deployed) |
| Uptime | N/A (pre-deployment) |
| Performance | Load tested (1M patients) |
| Incidents | 0 |
| Security Findings | 0 critical, 0 high |
| Backup Evidence | DR restore tested (144s) |
| DR Evidence | Restore verified |
| Customer Feedback | None (pre-deployment) |
| Top Defects | 0 critical, 0 high |

### Feature Status Summary

| Status | Count |
|---|---|
| Implemented | 25 |
| Partial | 3 |
| Designed Only | 0 |
| External Dependency | 3 (PACS, HL7, DICOM) |
| Deprecated | 0 |
| Not Implemented | 0 |

### Technical Debt

| Item | Severity | Impact |
|---|---|---|
| PACS viewer (partial) | Medium | External vendor required |
| HL7 V2 (adapter only) | Medium | External testing required |
| DICOM integration (partial) | Medium | External vendor required |
| 2 moderate npm vulnerabilities | Low | Non-exploitable in context |
| No external pen test | Medium | Required before production |

### Clinical Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Medication error from incorrect dose display | Low | High | RLS + audit + human override |
| Wrong patient data (IDOR) | Low | Critical | RLS + tenant isolation |
| Lab result misrouting | Low | High | Tenant + facility scoping |
| Blood bank incompatibility | Low | Critical | Crossmatch logic + audit |

### Operational Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| No production monitoring data | Certain | Medium | Deploy + observe |
| No real hospital feedback | Certain | Medium | Deploy pilot |
| No production DR evidence | Certain | Medium | DR exercise |
| No external integration testing | Certain | Medium | Partner sandbox |

### Approved Next Priorities

| Priority | Item | Status |
|---|---|---|
| 1 | External penetration test | Required before production |
| 2 | First hospital deployment | Requires authorization |
| 3 | Production PITR configuration | Required for go-live |
| 4 | External integration certification | Requires partner sandbox |
| 5 | Multi-hospital expansion | After first stable hospital |

### Rejected Items

No items currently rejected. Governance board is active and reviewing.

### Deprecations

No functionality currently deprecated.

---

## FINAL STATUS

```
CONDITIONALLY READY

SWASTHYA is a technically complete, governed healthcare platform
with 28 enterprise modules, continuous security/DR/performance
governance, and a controlled product evolution framework.

The platform is NOT "complete" — it is a continuously evolving
healthcare system governed by evidence, safety, and security.

The next action is human decision:
  1. External penetration test
  2. First hospital deployment
  3. Production monitoring
  4. Real-world feedback

DO NOT automatically create Phase 135.
Wait for an approved product/clinical/operational requirement.
```

---

*This document is a living governance framework. It is updated when evidence changes, not on a schedule.*
